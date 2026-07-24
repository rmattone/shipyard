<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an ad-hoc SSH connection check fails, e.g. when verifying a
 * new connection user is actually reachable before ShipYard commits to it.
 * Distinguished from a plain RuntimeException so controllers can map it to
 * 409 (conflict / not-yet-safe-to-proceed) rather than a generic 500.
 */
class ConnectionVerificationException extends RuntimeException {}
