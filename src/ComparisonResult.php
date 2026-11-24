<?php

declare(strict_types=1);

namespace Componenta\Identity;

/** The relative order of the left operand with respect to the right operand. */
enum ComparisonResult: int
{
    case Less = -1;
    case Equal = 0;
    case Greater = 1;
}
