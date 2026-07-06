<?php

declare(strict_types=1);

namespace Zolta\Domain\Contracts;

abstract class Specification implements SpecificationInterface
{
    abstract public function isSatisfiedBy(mixed $candidate, array $options = []): bool;

    public function message(): string
    {
        return 'unspecified specification';
    }

    public function and(SpecificationInterface $specification): SpecificationInterface
    {
        $a = $this;

        return new class($a, $specification) extends Specification implements SpecificationInterface
        {
            public function __construct(private readonly SpecificationInterface $a, private readonly SpecificationInterface $b) {}

            public function isSatisfiedBy(mixed $candidate, array $options = []): bool
            {
                return $this->a->isSatisfiedBy($candidate, $options) && $this->b->isSatisfiedBy($candidate, $options);
            }

            public function message(): string
            {
                return $this->a->message().' and '.$this->b->message();
            }
        };
    }

    public function or(SpecificationInterface $specification): SpecificationInterface
    {
        $a = $this;

        return new class($a, $specification) extends Specification implements SpecificationInterface
        {
            public function __construct(private readonly SpecificationInterface $a, private readonly SpecificationInterface $b) {}

            public function isSatisfiedBy(mixed $candidate, array $options = []): bool
            {
                return $this->a->isSatisfiedBy($candidate, $options) || $this->b->isSatisfiedBy($candidate, $options);
            }

            public function message(): string
            {
                return $this->a->message().' or '.$this->b->message();
            }
        };
    }

    public function not(): SpecificationInterface
    {
        $a = $this;

        return new class($a) extends Specification implements SpecificationInterface
        {
            public function __construct(private readonly SpecificationInterface $specification) {}

            public function isSatisfiedBy(mixed $candidate, array $options = []): bool
            {
                return ! $this->specification->isSatisfiedBy($candidate, $options);
            }

            public function message(): string
            {
                return 'not ('.$this->specification->message().')';
            }
        };
    }
}
