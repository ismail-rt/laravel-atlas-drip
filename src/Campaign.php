<?php

namespace Lad;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Campaign
{
    /**
     * Execution priority (lower numbers execute first).
     */
    protected int $priority = 100;

    /**
     * Eligibility callback determining if a recipient enters/continues the campaign.
     */
    protected ?Closure $eligibilityCallback = null;

    /**
     * Anchor callback returning the reference baseline timestamp for the campaign.
     */
    protected ?Closure $anchorCallback = null;

    /**
     * Maximum age in days from anchor timestamp for initial enrollment failsafe.
     */
    protected ?float $maxEnrollmentAgeDays = null;

    /**
     * Instance key callback or string to isolate recurring streaks.
     */
    protected mixed $instanceKeyCallback = null;

    /**
     * Goal cancellation predicate. If true, campaign transitions to terminal 'cancelled'.
     */
    protected ?Closure $cancelPredicate = null;

    /**
     * Array of sequential Step instances, keyed by step key.
     *
     * @var array<string, Step>
     */
    protected array $steps = [];

    /**
     * Custom recipient query builder callback.
     */
    protected ?Closure $recipientQueryCallback = null;

    /**
     * Create a new Campaign instance.
     */
    public function __construct(
        public readonly string $name
    ) {}

    /**
     * Fluent factory method.
     */
    public static function make(string $name): static
    {
        return new static($name);
    }

    /**
     * Get the campaign name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the priority for execution order (lower numbers run first).
     */
    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Get the priority.
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Set eligibility predicate for this campaign.
     */
    public function eligible(Closure|callable $callback): self
    {
        $this->eligibilityCallback = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Evaluate eligibility for a recipient.
     */
    public function isEligible(mixed $recipient): bool
    {
        if ($this->eligibilityCallback === null) {
            return true;
        }

        return (bool) ($this->eligibilityCallback)($recipient);
    }

    /**
     * Set the anchor callback returning the baseline timestamp.
     */
    public function anchor(Closure|callable $callback): self
    {
        $this->anchorCallback = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Resolve the anchor timestamp for a recipient.
     */
    public function resolveAnchor(mixed $recipient): ?CarbonInterface
    {
        if ($this->anchorCallback === null) {
            return null;
        }

        $result = ($this->anchorCallback)($recipient);

        if ($result === null) {
            return null;
        }

        return $result instanceof CarbonInterface ? $result : Carbon::parse($result);
    }

    /**
     * Set the maximum age in days from anchor timestamp for initial enrollment.
     */
    public function maxEnrollmentAgeDays(int|float $days): self
    {
        $this->maxEnrollmentAgeDays = (float) $days;

        return $this;
    }

    /**
     * Get the maximum enrollment age in days.
     */
    public function getMaxEnrollmentAgeDays(): ?float
    {
        return $this->maxEnrollmentAgeDays;
    }

    /**
     * Set the instance key callback or constant string.
     */
    public function instanceKey(mixed $callbackOrKey): self
    {
        $this->instanceKeyCallback = $callbackOrKey;

        return $this;
    }

    /**
     * Resolve the instance key for a recipient.
     */
    public function resolveInstanceKey(mixed $recipient): string
    {
        if ($this->instanceKeyCallback === null) {
            return 'default';
        }

        if (is_callable($this->instanceKeyCallback)) {
            $key = ($this->instanceKeyCallback)($recipient);

            return (string) ($key ?? 'default');
        }

        return (string) $this->instanceKeyCallback;
    }

    /**
     * Set the goal cancellation callback.
     */
    public function cancelWhen(Closure|callable $callback): self
    {
        $this->cancelPredicate = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Check if the recipient has reached the cancellation goal.
     */
    public function shouldCancel(mixed $recipient): bool
    {
        if ($this->cancelPredicate === null) {
            return false;
        }

        return (bool) ($this->cancelPredicate)($recipient);
    }

    /**
     * Define the sequential steps for this campaign.
     *
     * @param  array<int|string, Step>  $steps
     */
    public function steps(array $steps): self
    {
        $this->steps = [];

        foreach ($steps as $step) {
            if ($step instanceof Step) {
                $this->steps[$step->getKey()] = $step;
            }
        }

        return $this;
    }

    /**
     * Get all steps in order.
     *
     * @return array<string, Step>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * Get a step by key.
     */
    public function getStep(string $key): ?Step
    {
        return $this->steps[$key] ?? null;
    }

    /**
     * Get the first step in the sequence.
     */
    public function getFirstStep(): ?Step
    {
        $firstKey = array_key_first($this->steps);

        return $firstKey !== null ? $this->steps[$firstKey] : null;
    }

    /**
     * Get the next step after the given step key (or first step if null).
     */
    public function getNextStep(?string $currentStepKey): ?Step
    {
        if ($currentStepKey === null || ! isset($this->steps[$currentStepKey])) {
            return $this->getFirstStep();
        }

        $keys = array_keys($this->steps);
        $currentIndex = array_search($currentStepKey, $keys, true);

        if ($currentIndex === false || ! isset($keys[$currentIndex + 1])) {
            return null;
        }

        $nextKey = $keys[$currentIndex + 1];

        return $this->steps[$nextKey] ?? null;
    }

    /**
     * Get the step prior to the given step key.
     */
    public function getPreviousStep(string $stepKey): ?Step
    {
        $keys = array_keys($this->steps);
        $currentIndex = array_search($stepKey, $keys, true);

        if ($currentIndex === false || $currentIndex <= 0) {
            return null;
        }

        $previousKey = $keys[$currentIndex - 1];

        return $this->steps[$previousKey] ?? null;
    }

    /**
     * Determine if the given step is the last step in the campaign.
     */
    public function isLastStep(string $stepKey): bool
    {
        $keys = array_keys($this->steps);

        if (empty($keys)) {
            return true;
        }

        return end($keys) === $stepKey;
    }

    /**
     * Provide a custom query builder for recipient resolution.
     */
    public function recipientQuery(Closure|callable $callback): self
    {
        $this->recipientQueryCallback = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Resolve the recipient query builder.
     *
     * @return Builder<Model>
     */
    public function resolveRecipientQuery(): Builder
    {
        if ($this->recipientQueryCallback !== null) {
            return ($this->recipientQueryCallback)();
        }

        $modelClass = config('lad.recipient_model', 'App\\Models\\User');

        return $modelClass::query();
    }
}
