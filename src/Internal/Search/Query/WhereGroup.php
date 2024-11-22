<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Query;

use Stringable;

class WhereGroup implements Stringable
{
    /**
     * @var array<Statement>
     */
    private array $children = [];

    public function __construct(private WhereOperator $operator)
    {
    }

    public function add(WhereStatement|WhereGroup $child): self
    {
        $this->children[] = $child;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->children === [];
    }

    public function __toString(): string
    {
        if ($this->isEmpty()) {
            return '1 = 1';
        }

        $children = array_map(fn ($child) => (string) $child, $this->children);
        return '(' . implode(' ' . $this->operator . ' ', $children) . ')';
    }
}
