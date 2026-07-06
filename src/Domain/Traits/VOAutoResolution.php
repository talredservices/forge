<?php

declare(strict_types=1);

namespace Zolta\Domain\Traits;

use DomainException;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use Zolta\Domain\Attributes\Transform;
use Zolta\Domain\Attributes\UseInvariant;
use Zolta\Domain\Attributes\UsePolicy;
use Zolta\Domain\Attributes\UseRule;
use Zolta\Domain\Attributes\UseSpecification;
use Zolta\Domain\Cache\ReflectionCache;
use Zolta\Domain\Contracts\RuleInterface;
use Zolta\Domain\Contracts\SpecificationInterface;
use Zolta\Domain\Contracts\TransformerInterface;
use Zolta\Domain\Interfaces\VO;
use Zolta\Domain\ValueObjects\VOConstructionContext;

trait VOAutoResolution
{
    /** @var array<string, ReflectionClass<static>> */
    private static array $voReflectionCache = [];

    /** @var array<string, list<string>> */
    private static array $voAllPropertyNamesCache = [];

    /** @var array<string, list<string>> */
    private static array $voArrayPropertyNamesCache = [];

    /** @var array<string, array<string, list<array{class: string, arguments: array<int|string, mixed>}>>> */
    private static array $voPropertyAttrsCache = [];

    /** @var array<string, array<string, array{typeName: ?string, isVO: bool}>> */
    private static array $voPropertyTypesCache = [];

    /**
     * Return cached ReflectionClass for the current VO class.
     *
     * @return ReflectionClass<static>
     */
    protected static function cachedReflection(): ReflectionClass
    {
        return self::$voReflectionCache[static::class]
            ??= new ReflectionClass(static::class);
    }

    /**
     * Return cached list of all settable property names (excluding internals).
     *
     * @return list<string>
     */
    protected static function cachedAllPropertyNames(): array
    {
        if (isset(self::$voAllPropertyNamesCache[static::class])) {
            return self::$voAllPropertyNamesCache[static::class];
        }

        $reflectionClass = static::cachedReflection();
        $names = [];
        foreach (
            $reflectionClass->getProperties(
                ReflectionProperty::IS_PRIVATE |
                    ReflectionProperty::IS_PROTECTED |
                    ReflectionProperty::IS_PUBLIC
            ) as $reflectionProperty
        ) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }
            $name = $reflectionProperty->getName();
            if (in_array($name, ['getters', 'context'], true)) {
                continue;
            }
            $names[] = $name;
        }

        return self::$voAllPropertyNamesCache[static::class] = $names;
    }

    /**
     * Return cached list of array-typed property names (for pre-initialization).
     *
     * @return list<string>
     */
    protected static function cachedArrayPropertyNames(): array
    {
        if (isset(self::$voArrayPropertyNamesCache[static::class])) {
            return self::$voArrayPropertyNamesCache[static::class];
        }

        $reflectionClass = static::cachedReflection();
        $names = [];
        $currentRef = $reflectionClass;
        while ($currentRef) {
            foreach ($currentRef->getProperties() as $p) {
                if ($p->isStatic()) {
                    continue;
                }
                if (
                    $p->getType() instanceof \ReflectionNamedType &&
                    $p->getType()->getName() === 'array'
                ) {
                    $names[] = $p->getName();
                }
            }
            $currentRef = $currentRef->getParentClass();
        }

        return self::$voArrayPropertyNamesCache[static::class] = array_values(array_unique($names));
    }

    /**
     * Return cached property attributes keyed by property name.
     *
     * @return array<string, list<array{class: string, arguments: array<int|string, mixed>}>>
     */
    protected static function cachedPropertyAttrs(): array
    {
        if (isset(self::$voPropertyAttrsCache[static::class])) {
            return self::$voPropertyAttrsCache[static::class];
        }

        $reflectionClass = static::cachedReflection();
        $result = [];
        foreach (
            $reflectionClass->getProperties(
                ReflectionProperty::IS_PRIVATE |
                    ReflectionProperty::IS_PROTECTED |
                    ReflectionProperty::IS_PUBLIC
            ) as $reflectionProperty
        ) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }
            $attrs = [];
            foreach ($reflectionProperty->getAttributes() as $attr) {
                $attrs[] = [
                    'class' => $attr->getName(),
                    'arguments' => $attr->getArguments(),
                ];
            }
            $result[$reflectionProperty->getName()] = $attrs;
        }

        return self::$voPropertyAttrsCache[static::class] = $result;
    }

    /**
     * Return cached property type info keyed by property name.
     *
     * @return array<string, array{typeName: ?string, isVO: bool}>
     */
    protected static function cachedPropertyTypes(): array
    {
        if (isset(self::$voPropertyTypesCache[static::class])) {
            return self::$voPropertyTypesCache[static::class];
        }

        $reflectionClass = static::cachedReflection();
        $result = [];
        foreach (
            $reflectionClass->getProperties(
                ReflectionProperty::IS_PRIVATE |
                    ReflectionProperty::IS_PROTECTED |
                    ReflectionProperty::IS_PUBLIC
            ) as $reflectionProperty
        ) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }
            $type = $reflectionProperty->getType();
            $typeName = ($type instanceof \ReflectionNamedType) ? $type->getName() : null;
            $isVO = $typeName !== null && is_subclass_of($typeName, VO::class);
            $result[$reflectionProperty->getName()] = ['typeName' => $typeName, 'isVO' => $isVO];
        }

        return self::$voPropertyTypesCache[static::class] = $result;
    }

    /**
     * Factory-style entry point — resolves and instantiates.
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolve(array $data = [], ?VOConstructionContext $voConstructionContext = null): static
    {
        $resolved = static::resolveInternal($data, $voConstructionContext);

        return static::new($resolved);
    }

    /**
     * Decoupled “brain”: resolves and validates attributes, returns associative array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function resolveInternal(array $data, ?VOConstructionContext $voConstructionContext = null): array
    {
        $voConstructionContext ??= new VOConstructionContext;
        $runtimePreprocessors = $voConstructionContext->runtimePreprocessors;
        $runtimeOptions = $voConstructionContext->runtimeOptions;

        $reflectionClass = static::cachedReflection();
        $placeholder = $reflectionClass->newInstanceWithoutConstructor();

        // --- Initialize uninitialized typed array properties (cached list)
        foreach (static::cachedArrayPropertyNames() as $arrayPropName) {
            $p = $reflectionClass->getProperty($arrayPropName);
            if (! $p->isInitialized($placeholder)) {
                $p->setValue($placeholder, []);
            }
        }

        // --- Normalize preprocessor keys (strip nested prefixes like "email.address")
        $normalizedRuntimePreprocessors = [];
        foreach ($runtimePreprocessors as $key => $fn) {
            $segments = explode('.', (string) $key);
            $normalizedRuntimePreprocessors[end($segments)] = $fn;
        }
        $runtimePreprocessors = $normalizedRuntimePreprocessors;

        // --- Step 0: Collect VO properties (only from $data keys, using cached property list)
        $allProps = static::cachedAllPropertyNames();
        $incomingKeys = array_keys($data);
        $voProperties = array_values(array_intersect($allProps, $incomingKeys));

        // --- Step 1: Validate runtime preprocessors (after normalization)
        foreach (array_keys($runtimePreprocessors) as $propName) {
            if (! in_array($propName, $voProperties, true)) {
                throw new InvalidArgumentException(
                    "Runtime preprocessor cannot be applied: property '{$propName}' does not exist in ".static::class
                );
            }
        }

        // --- Step 2: Merge preprocessors (runtime overrides default)
        $voDefaults = [];
        if (method_exists($placeholder, 'defaultPreprocessors')) {
            try {
                $voDefaults = $placeholder->defaultPreprocessors() ?: [];
            } catch (\Throwable) {
                $voDefaults = [];
            }
        }
        $preprocessors = array_merge($voDefaults, $runtimePreprocessors);

        // Helper: short class name
        $classBase = static function (string $fqcn): string {
            $pos = strrpos($fqcn, '\\');

            return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
        };

        // Helper: find the runtime options bucket for this property, supporting keys like "email.address"
        /**
         * @param  array<string, mixed>  $runtimeOptions
         */
        $bucketForProp = static function (array $runtimeOptions, string $propName): array {
            if (isset($runtimeOptions[$propName]) && is_array($runtimeOptions[$propName])) {
                return $runtimeOptions[$propName];
            }
            // fallback: try any key whose last segment matches $propName
            foreach ($runtimeOptions as $k => $v) {
                if (! is_array($v)) {
                    continue;
                }
                $segments = explode('.', (string) $k);
                if (end($segments) === $propName) {
                    return $v;
                }
            }

            return [];
        };

        // --- Step 3: Process properties
        $cachedAttrs = static::cachedPropertyAttrs();
        $cachedTypes = static::cachedPropertyTypes();
        $values = [];
        foreach ($voProperties as $voProperty) {
            $value = $data[$voProperty] ?? null;

            // 3.1 Apply preprocessor if exists
            if (isset($preprocessors[$voProperty]) && is_callable($preprocessors[$voProperty])) {
                $value = $preprocessors[$voProperty]($value);
            }

            // 3.2 Apply Transform / Rule / Specification attributes (from cache)
            $propAttrs = $cachedAttrs[$voProperty] ?? [];
            $propRuntimeBucket = $bucketForProp($runtimeOptions, $voProperty);

            // First: TRANSFORMS (order matters — transform before validation)
            foreach ($propAttrs as $attr) {
                if ($attr['class'] !== Transform::class) {
                    continue;
                }

                $args = $attr['arguments'];
                $transformerClass = $args[0] ?? null;
                if (! $transformerClass) {
                    continue;
                }

                $transformer = new $transformerClass;
                if (! $transformer instanceof TransformerInterface) {
                    throw new DomainException("Transformer {$transformerClass} must implement TransformerInterface");
                }

                $staticOptions = $args[1] ?? [];
                // allow both FQCN and short class name
                $runtimeTransOptions = $propRuntimeBucket[$transformerClass]
                    ?? $propRuntimeBucket[$classBase($transformerClass)]
                    ?? [];

                if (is_array($runtimeTransOptions) && is_array($staticOptions)) {
                    $options = array_replace_recursive($staticOptions, $runtimeTransOptions);
                } elseif (! empty($runtimeTransOptions)) {
                    $options = is_array($runtimeTransOptions) ? $runtimeTransOptions : ['value' => $runtimeTransOptions];
                } else {
                    $options = $staticOptions;
                }

                $value = $transformer->transform($value, $options);
            }

            // Second: RULES and SPECIFICATIONS (validate after transforms)
            foreach ($propAttrs as $propAttr) {
                $attrClass = $propAttr['class'];
                $args = $propAttr['arguments'];

                // Rules
                if ($attrClass === UseRule::class) {
                    $ruleClass = $args[0] ?? null;
                    if ($ruleClass) {
                        $rule = new $ruleClass;
                        if (! $rule instanceof RuleInterface) {
                            throw new DomainException("Rule {$ruleClass} must implement RuleInterface");
                        }

                        $staticOptions = $args[1] ?? [];

                        // support both FQCN and short class name
                        $runtimeRuleOptions = $propRuntimeBucket[$ruleClass]
                            ?? $propRuntimeBucket[$classBase($ruleClass)]
                            ?? [];

                        if (is_array($runtimeRuleOptions) && is_array($staticOptions)) {
                            $ruleOptions = array_replace_recursive($staticOptions, $runtimeRuleOptions);
                        } elseif (! empty($runtimeRuleOptions)) {
                            $ruleOptions = is_array($runtimeRuleOptions)
                                ? $runtimeRuleOptions
                                : ['value' => $runtimeRuleOptions];
                        } else {
                            $ruleOptions = $staticOptions;
                        }

                        $rule->validate($value, $ruleOptions);
                    }
                }

                // Specifications
                if ($attrClass === UseSpecification::class) {
                    $specClass = $args[0] ?? null;
                    if ($specClass) {
                        $spec = new $specClass;
                        if (! $spec instanceof SpecificationInterface) {
                            throw new DomainException("Specification {$specClass} must implement SpecificationInterface");
                        }

                        $staticSpecArgs = $args[1] ?? [];
                        $runtimeSpecOptions = $propRuntimeBucket[$specClass]
                            ?? $propRuntimeBucket[$classBase($specClass)]
                            ?? [];

                        $specOptions = is_array($runtimeSpecOptions)
                            ? array_replace_recursive($staticSpecArgs, $runtimeSpecOptions)
                            : $staticSpecArgs;

                        if (! $spec->isSatisfiedBy($value, $specOptions)) {
                            throw new DomainException("Specification {$specClass} not satisfied for {$voProperty}");
                        }
                    }
                }
            }

            $values[$voProperty] = $value;

            // --- Step 3.3: If value is a nested ValueObject, run its class-level policies/invariants
            $typeInfo = $cachedTypes[$voProperty] ?? null;
            if ($typeInfo !== null && $typeInfo['isVO']) {
                $typeName = $typeInfo['typeName'];
                if (is_array($value)) {
                    // recursively resolve using the same runtime context
                    $value = $typeName::resolve($value, $voConstructionContext);
                }

                // once constructed, its class-level policy will apply inside its own resolveInternal()
            }
        }

        // --- Step 4: Apply class-level invariants & policies ---
        $classAttrs = ReflectionCache::getClassAttributes(static::class);
        $voForClassChecks = null;

        foreach ($classAttrs as $classAttr) {
            $attrClass = $classAttr['class'] ?? null;
            $args = $classAttr['arguments'] ?? [];
            $options = $runtimeOptions['class'][$attrClass] ?? ($args[1] ?? []);

            if ($attrClass === UseInvariant::class) {
                $invClass = $args[0] ?? null;
                if ($invClass) {
                    $inv = new $invClass;
                    $voForClassChecks ??= static::new($values);
                    $inv->ensure($voForClassChecks, $options);
                }
            }

            if ($attrClass === UsePolicy::class) {
                $policyClass = $args[0] ?? null;
                if ($policyClass) {
                    $policy = new $policyClass;
                    $voForClassChecks ??= static::new($values);
                    $policy->apply($voForClassChecks, $options);
                }
            }
        }

        return $values;
    }

    /**
     * Instantiates the VO from resolved attributes.
     *
     * @param  array<string, mixed>  $resolved
     */
    protected static function new(array $resolved): static
    {
        $reflectionClass = static::cachedReflection();
        $instance = $reflectionClass->newInstanceWithoutConstructor();

        foreach ($resolved as $name => $value) {
            if (! $reflectionClass->hasProperty($name)) {
                continue;
            }
            $prop = $reflectionClass->getProperty($name);
            $type = $prop->getType();

            // handle enum-typed properties
            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();
                if (enum_exists($typeName)) {
                    if (is_object($value) && $value::class === $typeName) {
                        $prop->setValue($instance, $value);

                        continue;
                    }

                    $valueToUse = is_array($value)
                        ? ($value['value'] ?? reset($value))
                        : $value;

                    try {
                        if (method_exists($typeName, 'from')) {
                            $enumInstance = $typeName::from($valueToUse);
                        } elseif (method_exists($typeName, 'tryFrom')) {
                            $enumInstance = $typeName::tryFrom($valueToUse);

                            if ($enumInstance === null) {
                                throw new DomainException("Invalid enum value for {$typeName}");
                            }
                        } else {
                            throw new DomainException("Enum {$typeName} does not support scalar coercion");
                        }

                        $prop->setValue($instance, $enumInstance);

                        continue;
                    } catch (\Throwable $e) {
                        throw new DomainException("Failed to coerce enum property {$name}: ".$e->getMessage(), $e->getCode(), $e);
                    }
                }
            }

            // skip assigning null to non-nullable typed props
            if ($value === null && $type && ! $type->allowsNull()) {
                continue;
            }

            $prop->setValue($instance, $value);
        }

        // Ensure all typed nullable props are initialized
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            if (! $reflectionProperty->isInitialized($instance)) {
                $type = $reflectionProperty->getType();
                if ($type && $type->allowsNull()) {
                    $reflectionProperty->setValue($instance, null);
                }
            }
        }

        return $instance;
    }

    /**
     * Safe getter utility.
     *
     * @param  array<int|string, callable|string>|string  $key
     */
    public function get(array|string $key): mixed
    {
        if (is_string($key)) {
            if (property_exists($this, $key)) {
                return $this->$key;
            }
            throw new InvalidArgumentException("Key {$key} is not accessible on ".static::class);
        }

        if (is_array($key)) {
            $result = [];
            foreach ($key as $k => $callbackOrNothing) {
                $attr = is_int($k) ? $callbackOrNothing : $k;
                $value = $this->get($attr);
                if (is_callable($callbackOrNothing)) {
                    $value = $callbackOrNothing($value, $this);
                }
                $result[$attr] = $value;
            }

            return $result;
        }

        throw new InvalidArgumentException('Expected string or array for get()');
    }

    public function __get(string $name): mixed
    {
        try {
            return $this->get($name);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("Property '{$name}' does not exist on ".static::class);
        }
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \LogicException("Cannot set property '{$name}' on immutable ValueObject ".static::class);
    }
}
