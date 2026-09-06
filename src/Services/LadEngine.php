<?php

namespace Lad\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Lad\Campaign;
use Lad\Contracts\RecipientInterface;
use Lad\Events\CampaignCancelled;
use Lad\Events\CampaignCompleted;
use Lad\Events\CampaignEnrolled;
use Lad\Events\StepDispatched;
use Lad\Models\LadCampaignState;
use Lad\Models\LadNotificationLog;
use Lad\Step;

class LadEngine
{
    /**
     * Registered campaigns indexed by name.
     *
     * @var array<string, Campaign>
     */
    protected array $campaigns = [];

    /**
     * Create a new LadEngine instance.
     */
    public function __construct(
        protected NotificationDedupeService $dedupeService
    ) {}

    /**
     * Register a campaign with the engine.
     */
    public function register(Campaign $campaign): self
    {
        $this->campaigns[$campaign->getName()] = $campaign;

        return $this;
    }

    /**
     * Get all registered campaigns, sorted by priority (ascending).
     *
     * @return array<string, Campaign>
     */
    public function getCampaigns(): array
    {
        $campaigns = $this->campaigns;

        uasort($campaigns, fn (Campaign $a, Campaign $b): int => $a->getPriority() <=> $b->getPriority());

        return $campaigns;
    }

    /**
     * Get a specific campaign by name.
     */
    public function getCampaign(string $name): ?Campaign
    {
        return $this->campaigns[$name] ?? null;
    }

    /**
     * Clear all registered campaigns.
     */
    public function clearCampaigns(): self
    {
        $this->campaigns = [];

        return $this;
    }

    /**
     * Check if a recipient has opted out of marketing/lifecycle emails.
     */
    public function isOptedOut(mixed $recipient, bool $cancelActiveStates = false): bool
    {
        $optedOut = false;

        if ($recipient instanceof RecipientInterface) {
            $optedOut = $recipient->hasOptedOutOfLifecycle() || $recipient->hasOptedOutOfDrip();
        } elseif ($recipient instanceof Model) {
            $optedOut = $recipient->getAttribute('marketing_emails_opted_out_at') !== null
                || $recipient->getAttribute('lifecycle_opted_out_at') !== null
                || $recipient->getAttribute('drip_opted_out_at') !== null;
        }

        if ($optedOut && $cancelActiveStates) {
            $this->cancelRecipient($recipient, null, 'opted_out');
        }

        return $optedOut;
    }

    /**
     * Check if the recipient is within the global inter-campaign cooldown period.
     */
    public function isInCooldown(mixed $recipient, ?CarbonInterface $now = null): bool
    {
        $cooldownHours = (int) config('lad.global_cooldown_hours', 48);

        if ($cooldownHours <= 0) {
            return false;
        }

        $now = $now ?? Carbon::now();
        $cutoff = $now->copy()->subHours($cooldownHours);

        return LadNotificationLog::forRecipient($recipient)
            ->where('sent_at', '>', $cutoff)
            ->exists();
    }

    /**
     * Get the most recent notification log sent timestamp for a recipient.
     */
    public function getLastNotificationSentAt(mixed $recipient): ?CarbonInterface
    {
        $log = LadNotificationLog::forRecipient($recipient)
            ->latest('sent_at')
            ->first();

        return $log?->sent_at;
    }

    /**
     * Verify whether a recipient qualifies for initial enrollment based on historical cutoff and max age.
     */
    public function canEnroll(
        Campaign $campaign,
        mixed $recipient,
        CarbonInterface $anchor,
        ?CarbonInterface $now = null
    ): bool {
        $now = $now ?? Carbon::now();

        // 1. Check configured historical enrollment cutoff
        $cutoffConfig = config('lad.enrollment_started_at');
        if ($cutoffConfig !== null) {
            $cutoffDate = Carbon::parse($cutoffConfig);
            if ($anchor->lt($cutoffDate)) {
                $hasPriorLog = LadNotificationLog::forRecipient($recipient)
                    ->where('campaign', $campaign->getName())
                    ->exists();

                if (! $hasPriorLog) {
                    return false;
                }
            }
        }

        // 2. Check campaign-specific maximum enrollment age failsafe
        $maxAgeDays = $campaign->getMaxEnrollmentAgeDays();
        if ($maxAgeDays !== null && $maxAgeDays > 0) {
            $maxAgeCutoff = $now->copy()->subSeconds((int) round($maxAgeDays * 86400));
            if ($anchor->lt($maxAgeCutoff)) {
                $hasPriorLog = LadNotificationLog::forRecipient($recipient)
                    ->where('campaign', $campaign->getName())
                    ->exists();

                if (! $hasPriorLog) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Evaluate a recipient across campaigns, dispatching at most one due email.
     *
     * @return array{recipient: mixed, campaign: string, step: string, sent_at: CarbonInterface, dry_run: bool}|null
     */
    public function evaluateRecipient(
        mixed $recipient,
        ?CarbonInterface $now = null,
        bool $dryRun = false,
        ?string $specificCampaign = null
    ): ?array {
        if (! (bool) config('lad.enabled', true)) {
            return null;
        }

        $now = $now ?? Carbon::now();

        // 1. Opt-out check
        if ($this->isOptedOut($recipient, cancelActiveStates: ! $dryRun)) {
            return null;
        }

        // 2. Global Cooldown Evaluator (Inbox Protection)
        if ($this->isInCooldown($recipient, $now)) {
            return null;
        }

        $campaigns = $this->getCampaigns();

        if ($specificCampaign !== null) {
            $campaigns = isset($campaigns[$specificCampaign]) ? [$specificCampaign => $campaigns[$specificCampaign]] : [];
        }

        // 3. Campaign Priority Pipeline: At most 1 campaign email per recipient per run
        foreach ($campaigns as $campaign) {
            if (! $campaign->isEligible($recipient)) {
                continue;
            }

            $instanceKey = $campaign->resolveInstanceKey($recipient);

            /** @var LadCampaignState|null $state */
            $state = LadCampaignState::forRecipient($recipient)
                ->campaign($campaign->getName())
                ->instance($instanceKey)
                ->first();

            // Terminal states never re-enroll or restart
            if ($state !== null && $state->isTerminal()) {
                continue;
            }

            // If active state exists, check goal cancellation
            if ($state !== null && $state->isActive()) {
                if ($campaign->shouldCancel($recipient)) {
                    if (! $dryRun) {
                        $state->cancel($now);
                        event(new CampaignCancelled($recipient, $campaign, $state, 'goal_reached'));
                    }

                    continue;
                }
            }

            // If no state exists, attempt new enrollment
            if ($state === null) {
                $anchor = $campaign->resolveAnchor($recipient);
                if ($anchor === null) {
                    continue;
                }

                if (! $this->canEnroll($campaign, $recipient, $anchor, $now)) {
                    continue;
                }

                if ($campaign->shouldCancel($recipient)) {
                    continue;
                }

                if (! $dryRun) {
                    $recipientType = $recipient instanceof Model ? $recipient->getMorphClass() : null;
                    $recipientId = $recipient instanceof Model ? $recipient->getKey() : $recipient;

                    try {
                        $state = LadCampaignState::create([
                            'recipient_type' => $recipientType,
                            'recipient_id' => $recipientId,
                            'campaign' => $campaign->getName(),
                            'instance_key' => $instanceKey,
                            'status' => LadCampaignState::STATUS_ACTIVE,
                            'anchor_at' => $anchor,
                        ]);

                        event(new CampaignEnrolled($recipient, $campaign, $state));
                    } catch (\Throwable $e) {
                        if ($this->dedupeService->isUniqueViolation($e)) {
                            $state = LadCampaignState::forRecipient($recipient)
                                ->campaign($campaign->getName())
                                ->instance($instanceKey)
                                ->first();

                            if ($state === null || $state->isTerminal()) {
                                continue;
                            }
                        } else {
                            throw $e;
                        }
                    }
                } else {
                    $state = new LadCampaignState([
                        'campaign' => $campaign->getName(),
                        'instance_key' => $instanceKey,
                        'status' => LadCampaignState::STATUS_ACTIVE,
                        'anchor_at' => $anchor,
                    ]);
                }
            }

            // Determine next step in sequence
            $nextStep = $campaign->getNextStep($state->last_step);

            if ($nextStep === null) {
                if (! $dryRun && $state->isActive() && $state->exists) {
                    $state->complete($now);
                    event(new CampaignCompleted($recipient, $campaign, $state));
                }

                continue;
            }

            if (! $nextStep->shouldSend($recipient)) {
                continue;
            }

            // Two-Clock Timing Evaluation
            if (! $nextStep->isDue($state->anchor_at, $state->last_sent_at, $now)) {
                continue;
            }

            // Attempt atomic deduplicated send
            $log = $this->dedupeService->sendOnce($campaign, $nextStep, $recipient, $now, $dryRun, $instanceKey);

            if ($log === null) {
                // Dedupe caught a race condition or already sent
                continue;
            }

            if (! $dryRun && $state->exists) {
                $state->recordDispatch($nextStep->getKey(), $now);
                event(new StepDispatched($recipient, $campaign, $nextStep, $state, $log));

                if ($campaign->isLastStep($nextStep->getKey())) {
                    $state->complete($now);
                    event(new CampaignCompleted($recipient, $campaign, $state));
                }
            }

            // Single-Send Limit: Stop after one dispatch per run
            return [
                'recipient' => $recipient,
                'campaign' => $campaign->getName(),
                'step' => $nextStep->getKey(),
                'sent_at' => $now,
                'dry_run' => $dryRun,
            ];
        }

        return null;
    }

    /**
     * Run the engine across all eligible recipients.
     *
     * @return list<array{recipient: mixed, campaign: string, step: string, sent_at: CarbonInterface, dry_run: bool}>
     */
    public function run(
        ?string $campaignName = null,
        mixed $specificRecipientId = null,
        bool $dryRun = false,
        ?CarbonInterface $now = null
    ): array {
        $now = $now ?? Carbon::now();
        $dispatches = [];

        $recipientQuery = $this->resolveRecipientQuery($campaignName, $specificRecipientId);

        $recipientQuery->chunkById(100, function ($recipients) use (&$dispatches, $now, $dryRun, $campaignName): void {
            foreach ($recipients as $recipient) {
                $result = $this->evaluateRecipient($recipient, $now, $dryRun, $campaignName);
                if ($result !== null) {
                    $dispatches[] = $result;
                }
            }
        });

        return $dispatches;
    }

    /**
     * Resolve the recipient query builder.
     *
     * @return Builder<Model>
     */
    protected function resolveRecipientQuery(?string $campaignName = null, mixed $specificRecipientId = null): Builder
    {
        if ($campaignName !== null && isset($this->campaigns[$campaignName])) {
            $query = $this->campaigns[$campaignName]->resolveRecipientQuery();
        } else {
            $modelClass = config('lad.recipient_model', 'App\\Models\\User');
            $query = $modelClass::query();
        }

        if ($specificRecipientId !== null) {
            $query->whereKey($specificRecipientId);
        }

        return $query;
    }

    /**
     * Generate a signed unsubscribe URL for a recipient.
     */
    public function unsubscribeUrl(mixed $recipient): string
    {
        $recipientId = $recipient instanceof Model ? $recipient->getKey() : (string) $recipient;

        return URL::signedRoute('lad.unsubscribe', ['recipient' => $recipientId]);
    }

    /**
     * Cancel active campaign states for a recipient.
     */
    public function cancelRecipient(mixed $recipient, ?string $campaign = null, string $reason = 'manual'): int
    {
        $query = LadCampaignState::forRecipient($recipient)->active();

        if ($campaign !== null) {
            $query->campaign($campaign);
        }

        $states = $query->get();
        $count = 0;

        foreach ($states as $state) {
            $state->cancel();
            $count++;

            $campaignObj = $this->getCampaign($state->campaign) ?? Campaign::make($state->campaign);
            event(new CampaignCancelled($recipient, $campaignObj, $state, $reason));
        }

        return $count;
    }

    /**
     * Opt-out a recipient and cancel all their active campaigns.
     */
    public function optOutRecipient(mixed $recipient): void
    {
        if ($recipient instanceof Model) {
            $hasOptedOutCol = in_array('marketing_emails_opted_out_at', $recipient->getFillable(), true)
                || array_key_exists('marketing_emails_opted_out_at', $recipient->getAttributes())
                || method_exists($recipient, 'hasColumn') && $recipient->hasColumn('marketing_emails_opted_out_at');

            // Force fill and save if column exists or can be written
            $recipient->forceFill([
                'marketing_emails_opted_out_at' => Carbon::now(),
            ])->save();
        }

        $this->cancelRecipient($recipient, null, 'opt_out');
    }

    /**
     * Inspect current status, cooldown, and campaign states for a recipient.
     *
     * @return array<string, mixed>
     */
    public function getStatusForRecipient(mixed $recipient, ?CarbonInterface $now = null): array
    {
        $now = $now ?? Carbon::now();
        $recipientId = $recipient instanceof Model ? $recipient->getKey() : (string) $recipient;
        $inCooldown = $this->isInCooldown($recipient, $now);
        $lastSentAt = $this->getLastNotificationSentAt($recipient);
        $isOptedOut = $this->isOptedOut($recipient);

        $campaignsStatus = [];

        foreach ($this->getCampaigns() as $campaign) {
            $instanceKey = $campaign->resolveInstanceKey($recipient);

            /** @var LadCampaignState|null $state */
            $state = LadCampaignState::forRecipient($recipient)
                ->campaign($campaign->getName())
                ->instance($instanceKey)
                ->first();

            $nextStep = null;
            $dueAt = null;
            $isDue = false;

            if ($state !== null && $state->isActive()) {
                $nextStepObj = $campaign->getNextStep($state->last_step);
                if ($nextStepObj !== null) {
                    $nextStep = $nextStepObj->getKey();
                    $dueAt = $nextStepObj->calculateDueAt($state->anchor_at, $state->last_sent_at);
                    $isDue = $now->gte($dueAt);
                }
            }

            $campaignsStatus[$campaign->getName()] = [
                'state' => $state?->status ?? 'not_enrolled',
                'instance_key' => $instanceKey,
                'anchor_at' => $state?->anchor_at?->toIso8601String(),
                'last_step' => $state?->last_step,
                'last_sent_at' => $state?->last_sent_at?->toIso8601String(),
                'next_step' => $nextStep,
                'due_at' => $dueAt?->toIso8601String(),
                'is_due' => $isDue,
                'eligible' => $campaign->isEligible($recipient),
                'goal_reached' => $campaign->shouldCancel($recipient),
            ];
        }

        return [
            'recipient_id' => $recipientId,
            'is_opted_out' => $isOptedOut,
            'in_cooldown' => $inCooldown,
            'last_sent_at' => $lastSentAt?->toIso8601String(),
            'campaigns' => $campaignsStatus,
        ];
    }
}
