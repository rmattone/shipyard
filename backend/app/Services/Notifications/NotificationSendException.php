<?php

namespace App\Services\Notifications;

use RuntimeException;

/**
 * Delivery failure for one notification channel. Messages must stay
 * curated: Telegram request URLs embed the bot token, so raw URLs or
 * exception chains never belong in the message.
 */
class NotificationSendException extends RuntimeException {}
