<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Query;

enum WhereOperator: string
{
    case AND = 'AND';
    case OR = 'OR';
}
