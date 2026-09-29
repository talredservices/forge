<?php

declare(strict_types=1);

namespace Zolta\Tests\Unit\Compatibility;

use PHPUnit\Framework\TestCase;
use Talred\Domain\Attributes\UseRule;
use Talred\Domain\Contracts\RuleInterface;
use Talred\Domain\Traits\VOAutoResolution;
use Talred\Domain\ValueObjects\Email;
use Talred\Domain\ValueObjects\ValueObject;
use Talred\Exceptions\ValidationException;
use Talred\Framework\FrameworkRegistry;
use Talred\Support\Application\DTO\Input\InputDTO;

final class TalredNamespaceCompatibilityTest extends TestCase
{
    public function test_talred_domain_class_alias_uses_the_existing_zolta_implementation(): void
    {
        self::assertTrue(class_exists(ValueObject::class));
        self::assertSame(
            \Zolta\Domain\ValueObjects\ValueObject::class,
            (new \ReflectionClass(ValueObject::class))->getName(),
        );
    }

    public function test_talred_domain_interface_alias_uses_the_existing_zolta_contract(): void
    {
        self::assertTrue(interface_exists(RuleInterface::class));
        self::assertSame(
            \Zolta\Domain\Contracts\RuleInterface::class,
            (new \ReflectionClass(RuleInterface::class))->getName(),
        );
    }

    public function test_talred_domain_trait_alias_uses_the_existing_zolta_trait(): void
    {
        self::assertTrue(trait_exists(VOAutoResolution::class));
        self::assertTrue(trait_exists(\Zolta\Domain\Traits\VOAutoResolution::class));
    }

    public function test_talred_attribute_alias_uses_the_existing_zolta_attribute(): void
    {
        self::assertTrue(class_exists(UseRule::class));
        self::assertSame(
            \Zolta\Domain\Attributes\UseRule::class,
            (new \ReflectionClass(UseRule::class))->getName(),
        );
    }

    public function test_talred_exception_alias_uses_the_existing_zolta_exception(): void
    {
        self::assertTrue(class_exists(ValidationException::class));
        self::assertSame(
            \Zolta\Exceptions\ValidationException::class,
            (new \ReflectionClass(ValidationException::class))->getName(),
        );
    }

    public function test_talred_framework_and_support_aliases_use_existing_zolta_implementations(): void
    {
        self::assertTrue(class_exists(FrameworkRegistry::class));
        self::assertSame(
            \Zolta\Framework\FrameworkRegistry::class,
            (new \ReflectionClass(FrameworkRegistry::class))->getName(),
        );
        self::assertTrue(class_exists(InputDTO::class));
        self::assertSame(
            \Zolta\Support\Application\DTO\Input\InputDTO::class,
            (new \ReflectionClass(InputDTO::class))->getName(),
        );
    }

    public function test_talred_value_object_alias_preserves_the_existing_resolution_pipeline(): void
    {
        $email = Email::resolve(['address' => '  JOHN@Example.com  ']);

        self::assertSame('john@example.com', $email->get('address'));
        self::assertSame(\Zolta\Domain\ValueObjects\Email::class, $email::class);
    }
}
