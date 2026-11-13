<?php

declare(strict_types=1);

namespace Procteye\Enum;

/** The assurance level requested for an integrity session. */
enum AssuranceLevel: string
{
    case BASIC = 'BASIC';
    case STANDARD = 'STANDARD';
    case HIGH = 'HIGH';
}
