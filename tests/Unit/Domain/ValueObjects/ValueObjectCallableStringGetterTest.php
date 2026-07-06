<?php

declare(strict_types=1);

namespace Zolta\Tests\Unit\Domain\ValueObjects;

use PHPUnit\Framework\TestCase;
use Zolta\Domain\Interfaces\VO;
use Zolta\Domain\ValueObjects\UserId;
use Zolta\Domain\ValueObjects\ValueObject;
use Zolta\Domain\ValueObjects\VOConstructionContext;

final class ValueObjectCallableStringGetterTest extends TestCase
{
    public function test_to_array_treats_string_getter_names_as_properties_not_callables(): void
    {
        $valueObject = new CallableNamedPropertyValueObject('trim me');

        $this->assertSame(['trim' => 'trim me'], $valueObject->toArray());
    }

    public function test_uuid_value_objects_can_be_compared_without_recursion(): void
    {
        $first = new UserId('9d8481cd-211d-42a4-93eb-abd30f34f351');
        $second = new UserId('9d8481cd-211d-42a4-93eb-abd30f34f351');

        $this->assertTrue($first->equals($second));
        $this->assertSame(
            ['value' => '9d8481cd-211d-42a4-93eb-abd30f34f351'],
            $first->toArray(),
        );
    }
}

final class CallableNamedPropertyValueObject extends ValueObject
{
    public function __construct(
        protected string $trim,
        protected ?VOConstructionContext $context = null,
    ) {
        parent::__construct();
    }

    public function equals(VO $vo): bool
    {
        return parent::equals($vo);
    }
}
