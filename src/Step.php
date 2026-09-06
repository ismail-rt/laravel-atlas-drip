<?php

namespace Lad;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;

class Step
{
    /**
     * Offset from anchor in seconds.
     */
    protected int $offsetSeconds = 0;

    /**
     * Minimum gap from previous step in seconds.
     */
    protected int $minimumGapSeconds = 0;

    /**
     * The notification class, closure, or mailable.
     */
    protected mixed $notification = null;

    /**
     * Optional condition callback for this step.
     */
    protected ?Closure $condition = null;

    /**
     * Create a new Step instance.
     */
    public function __construct(
        public readonly string $key
    ) {}

    /**
     * Fluent factory method.
     */
    public static function make(string $key): static
    {
        return new static($key);
    }

    /**
     * Get the step key.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Set the offset in days from the campaign anchor.
     */
    public function offsetDays(int|float $days): self
    {
        $this->offsetSeconds = (int) round($days * 86400);

        return $this;
    }

    /**
     * Set the offset in hours from the campaign anchor.
     */
    public function offsetHours(int|float $hours): self
    {
        $this->offsetSeconds = (int) round($hours * 3600);

        return $this;
    }

    /**
     * Set the offset in minutes from the campaign anchor.
     */
    public function offsetMinutes(int $minutes): self
    {
        $this->offsetSeconds = $minutes * 60;

        return $this;
    }

    /**
     * Get the offset in seconds.
     */
    public function getOffsetSeconds(): int
    {
        return $this->offsetSeconds;
    }

    /**
     * Set the minimum gap in days from the previous step sent timestamp.
     */
    public function minimumGapDays(int|float $days): self
    {
        $this->minimumGapSeconds = (int) round($days * 86400);

        return $this;
    }

    /**
     * Set the minimum gap in hours from the previous step sent timestamp.
     */
    public function minimumGapHours(int|float $hours): self
    {
        $this->minimumGapSeconds = (int) round($hours * 3600);

        return $this;
    }

    /**
     * Get the minimum gap in seconds.
     */
    public function getMinimumGapSeconds(): int
    {
        return $this->minimumGapSeconds;
    }

    /**
     * Set the notification class name, instance, closure, or mailable.
     */
    public function notification(mixed $notification): self
    {
        $this->notification = $notification;

        return $this;
    }

    /**
     * Get the configured notification.
     */
    public function getNotification(): mixed
    {
        return $this->notification;
    }

    /**
     * Set an optional callback to verify whether this step should execute.
     */
    public function when(Closure|callable $callback): self
    {
        $this->condition = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Determine if this step's condition passes for the recipient.
     */
    public function shouldSend(mixed $recipient): bool
    {
        if ($this->condition === null) {
            return true;
        }

        return (bool) ($this->condition)($recipient);
    }

    /**
     * The Two-Clock Formula:
     * due_at = max(anchor_at + offset, previous_step.sent_at + minimum_gap)
     */
    public function calculateDueAt(CarbonInterface $anchorAt, ?CarbonInterface $previousSentAt = null): CarbonInterface
    {
        $anchorDueAt = $anchorAt->copy()->addSeconds($this->offsetSeconds);

        if ($previousSentAt === null || $this->minimumGapSeconds <= 0) {
            return $anchorDueAt;
        }

        $gapDueAt = $previousSentAt->copy()->addSeconds($this->minimumGapSeconds);

        return $gapDueAt->gt($anchorDueAt) ? $gapDueAt : $anchorDueAt;
    }

    /**
     * Determine if this step is currently due.
     */
    public function isDue(
        CarbonInterface $anchorAt,
        ?CarbonInterface $previousSentAt = null,
        ?CarbonInterface $now = null
    ): bool {
        $now = $now ?? Carbon::now();
        $dueAt = $this->calculateDueAt($anchorAt, $previousSentAt);

        return $now->gte($dueAt);
    }
}
