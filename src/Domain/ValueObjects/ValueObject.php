<?php

declare(strict_types=1);

namespace Zolta\Domain\ValueObjects;

use DateTimeInterface;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Stringable;
use Traversable;
use Zolta\Domain\Interfaces\VO;
use Zolta\Domain\Traits\VOAutoResolution;

abstract class ValueObject implements JsonSerializable, Stringable, VO
{
    use VOAutoResolution;

    /**
     * Override in children to explicitly list the keys exposed via get()/toArray().
     *
     * When left empty, all non-internal properties are considered.
     *
     * @var array<string|int, callable|string>
     */
    protected array $getters = [];

    /**
     * Unified auto-resolving constructor.
     *
     * Handles both explicit and reflection-based ValueObject construction.
     */
    public function __construct()
    {
        $ref = new ReflectionClass($this);
        $constructor = $ref->getConstructor();

        if (! $constructor) {
            return;
        }

        $props = [];
        $context = null;

        // --- Step 1: Extract constructor parameters
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (! property_exists($this, $name)) {
                continue;
            }

            $value = $this->{$name};
            $type = $param->getType();

            // Detect VOConstructionContext
            if (
                $type instanceof ReflectionNamedType &&
                $type->getName() === VOConstructionContext::class
            ) {
                $context = $value;

                continue;
            }

            $props[$name] = $value;
        }

        // --- Step 2: Fallback (ensure we catch declared property)
        if (
            ! $context &&
            property_exists($this, 'context') &&
            $this->context instanceof VOConstructionContext
        ) {
            $context = $this->context;
        }

        // --- Step 3: Final fallback for external builder injection
        if (! $context) {
            foreach (get_object_vars($this) as $prop => $val) {
                if ($val instanceof VOConstructionContext) {
                    $context = $val;
                    break;
                }
            }
        }

        // --- Step 4: Run the resolution pipeline
        $resolved = static::resolveInternal($props, $context);

        // --- Step 5: Assign resolved values (safe assign only constructor props)
        $assignable = array_intersect_key($resolved, $props);

        foreach ($assignable as $key => $value) {
            if (! $ref->hasProperty($key)) {
                continue;
            }

            $propRef = $ref->getProperty($key);
            $propRef->setAccessible(true);
            $type = $propRef->getType();

            // Skip assigning null to non-nullable props
            if ($value === null && $type instanceof ReflectionNamedType && ! $type->allowsNull()) {
                continue;
            }

            $this->{$key} = $value;
        }
    }

    /**
     * Generic getter for value object properties.
     *
     * @param  array<int,string>|string  $key
     */
    public function get(array|string $key = 'value'): mixed
    {
        if (is_array($key)) {
            $resolved = [];
            foreach ($key as $k) {
                $resolved[$k] = $this->get($k);
            }

            return $resolved;
        }

        $property = (string) $key;

        return property_exists($this, $property) ? $this->{$property} ?? null : null;
    }

    /**
     * Convert the ValueObject into an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        foreach ($this->normalizedGetterMap() as $key => $definition) {
            if (is_string($definition)) {
                $value = $this->get($definition);
            } elseif (is_callable($definition)) {
                $value = $definition($this);
            } else {
                $value = $definition;
            }

            $payload[$key] = $this->normalizeValue($value);
        }

        return $payload;
    }

    /**
     * Determine equality by comparing normalized payloads.
     */
    public function equals(VO $other): bool
    {
        if ($other === $this) {
            return true;
        }

        if (! $other instanceof static) {
            return false;
        }

        return $this->toArray() == $other->toArray();
    }

    /**
     * Provide sensible JSON serialization by delegating to toArray().
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * String representation: prefer primary getter, fall back to JSON payload.
     */
    public function __toString(): string
    {
        try {
            $primary = $this->primaryStringGetter();

            $value = $primary !== null
                ? $this->normalizeValue($this->get($primary))
                : $this->toArray();

            $normalized = $this->normalizeValue($value);

            if (is_scalar($normalized) || $normalized === null) {
                return (string) $normalized;
            }

            if ($normalized instanceof Stringable) {
                return (string) $normalized;
            }

            $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

            return $encoded === false ? static::class : $encoded;
        } catch (\Throwable) {
            return static::class;
        }
    }

    /**
     * Normalize the configured getters into an associative map.
     *
     * @return array<string, callable|string>
     */
    protected function normalizedGetterMap(): array
    {
        $map = [];
        $raw = $this->getters;

        if ($raw === []) {
            $raw = $this->discoverDefaultGetters();
        }

        foreach ($raw as $key => $value) {
            if (is_int($key)) {
                if (is_string($value) && $value !== '') {
                    $map[$value] = $value;
                }

                continue;
            }

            if ($key !== '') {
                $map[$key] = $value;
            }
        }

        if ($map === []) {
            foreach ($this->discoverDefaultGetters() as $prop) {
                $map[$prop] = $prop;
            }
        }

        return $map;
    }

    /**
     * Discover readable properties when $getters is not explicitly set.
     *
     * @return list<string>
     */
    protected function discoverDefaultGetters(): array
    {
        $ref = new ReflectionClass($this);
        $properties = [];

        foreach (
            $ref->getProperties(
                ReflectionProperty::IS_PRIVATE |
                ReflectionProperty::IS_PROTECTED |
                ReflectionProperty::IS_PUBLIC
            ) as $property
        ) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();
            if (in_array($name, ['getters', 'context'], true)) {
                continue;
            }

            if (! array_key_exists($name, $properties)) {
                $properties[$name] = $name;
            }
        }

        return array_values($properties);
    }

    /**
     * Normalize nested values for array/string conversion.
     */
    protected function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof VO) {
            return $value->toArray();
        }

        if ($value instanceof Traversable) {
            $normalized = [];
            foreach ($value as $k => $item) {
                $normalized[$k] = $this->normalizeValue($item);
            }

            return $normalized;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $k => $item) {
                $normalized[$k] = $this->normalizeValue($item);
            }

            return $normalized;
        }

        return $value;
    }

    /**
     * Determine which getter should back the string representation.
     */
    protected function primaryStringGetter(): ?string
    {
        if (property_exists($this, 'value')) {
            return 'value';
        }

        $map = $this->normalizedGetterMap();

        if (isset($map['value']) && is_string($map['value'])) {
            return $map['value'];
        }

        $firstKey = array_key_first($map);

        if ($firstKey === null) {
            return null;
        }

        $definition = $map[$firstKey];

        return is_string($definition) ? $definition : null;
    }
}
