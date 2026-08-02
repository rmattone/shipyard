<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an uploaded dump is not plain or gzipped SQL. The message is
 * shown to the user, so it must say what to do rather than just what failed.
 */
class UnsupportedDumpException extends RuntimeException {}
