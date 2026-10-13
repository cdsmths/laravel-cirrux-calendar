<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Exceptions;

/**
 * Authentication with Cirrux failed definitively: not logged in, access
 * denied, or a refresh token that was revoked. Someone has to log in again.
 */
final class CirruxAuthException extends CirruxException {}
