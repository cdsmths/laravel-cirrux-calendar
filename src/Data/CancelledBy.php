<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

enum CancelledBy: string
{
    /** Cancelled through the package, by the app. */
    case User = 'user';

    /** The event disappeared from the Cirrux calendar. */
    case External = 'external';
}
