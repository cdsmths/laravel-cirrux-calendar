<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Exceptions;

/**
 * Thrown by a listener of `BookingCreating` to hold a booking back. The message is the listener's own.
 */
final class BookingVetoedException extends CirruxException {}
