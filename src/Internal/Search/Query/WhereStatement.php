<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Query;

use Stringable;

class WhereStatement implements Stringable
{
    public function __construct(protected string $statement)
    {
    }

    public function __toString(): string
    {
        return $this->statement;
    }
}
