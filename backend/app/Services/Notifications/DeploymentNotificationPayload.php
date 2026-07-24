<?php

namespace App\Services\Notifications;

use App\Models\Deployment;
use App\Models\NotificationChannel;

final readonly class DeploymentNotificationPayload
{
    private const EXCERPT_LINES = 10;

    private const EXCERPT_MAX_CHARS = 900;

    public function __construct(
        public string $event,
        public bool $succeeded,
        public bool $isRollback,
        public string $appName,
        public ?string $branch,
        public ?string $commitShort,
        public ?string $commitMessage,
        public ?int $durationSeconds,
        public ?string $finishedAt,
        public string $url,
        public ?string $failureExcerpt,
    ) {}

    public static function fromDeployment(Deployment $deployment, string $event): self
    {
        $succeeded = $event === NotificationChannel::EVENT_SUCCEEDED;
        $application = $deployment->application;

        $commitMessage = null;
        if (filled($deployment->commit_message)) {
            $commitMessage = str($deployment->commit_message)->before("\n")->limit(100)->toString();
        }

        // getDuration() is a signed Carbon 3 diff and comes back negative;
        // abs() here rather than changing the accessor other code reads.
        $duration = $deployment->getDuration();

        return new self(
            event: $event,
            succeeded: $succeeded,
            isRollback: $deployment->type === 'rollback',
            appName: $application?->name ?? 'unknown application',
            branch: $application?->branch,
            commitShort: filled($deployment->commit_hash) ? substr($deployment->commit_hash, 0, 7) : null,
            commitMessage: $commitMessage,
            durationSeconds: $duration !== null ? (int) abs($duration) : null,
            finishedAt: $deployment->finished_at?->toIso8601String(),
            url: secure_url("/apps/{$deployment->application_id}/deployments/{$deployment->id}"),
            failureExcerpt: $succeeded ? null : self::excerptFromLog($deployment->log),
        );
    }

    public static function test(): self
    {
        return new self(
            event: NotificationChannel::EVENT_SUCCEEDED,
            succeeded: true,
            isRollback: false,
            appName: 'ShipYard test',
            branch: 'main',
            commitShort: 'abc1234',
            commitMessage: 'This is a test notification from ShipYard.',
            durationSeconds: 42,
            finishedAt: now()->toIso8601String(),
            url: secure_url('/'),
            failureExcerpt: null,
        );
    }

    public function eventLabel(): string
    {
        return $this->isRollback ? 'Rollback' : 'Deployment';
    }

    public function resultLabel(): string
    {
        return $this->succeeded ? 'succeeded' : 'failed';
    }

    public function title(): string
    {
        return "{$this->eventLabel()} {$this->resultLabel()} — {$this->appName}";
    }

    public function durationHuman(): ?string
    {
        if ($this->durationSeconds === null) {
            return null;
        }

        $minutes = intdiv($this->durationSeconds, 60);
        $seconds = $this->durationSeconds % 60;

        return $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s";
    }

    private static function excerptFromLog(?string $log): ?string
    {
        if (blank($log)) {
            return null;
        }

        $lines = explode("\n", trim($log));
        $tail = implode("\n", array_slice($lines, -self::EXCERPT_LINES));

        if (strlen($tail) > self::EXCERPT_MAX_CHARS) {
            $tail = '…'.substr($tail, -self::EXCERPT_MAX_CHARS);
        }

        return $tail;
    }
}
