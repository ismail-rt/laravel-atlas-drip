<?php

namespace Lad\Services;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Lad\Campaign;
use Lad\Models\LadNotificationLog;
use Lad\Step;
use Throwable;

class NotificationDedupeService
{
    /**
     * Generate the deterministic deduplication key for a step send attempt.
     */
    public function generateDedupeKey(
        Campaign $campaign,
        Step $step,
        mixed $recipient,
        string $instanceKey = 'default'
    ): string {
        $recipientId = $recipient instanceof Model ? $recipient->getKey() : (string) $recipient;

        if ($instanceKey !== 'default' && $instanceKey !== '') {
            return "lad:{$campaign->getName()}:{$instanceKey}:{$step->getKey()}:{$recipientId}";
        }

        return "lad:{$campaign->getName()}:{$step->getKey()}:{$recipientId}";
    }

    /**
     * Atomically record the notification and dispatch the payload within a database transaction.
     *
     * Returns the created LadNotificationLog on success, or null if duplicate prevention intervened.
     *
     * @throws Throwable
     */
    public function sendOnce(
        Campaign $campaign,
        Step $step,
        mixed $recipient,
        ?CarbonInterface $now = null,
        bool $dryRun = false,
        string $instanceKey = 'default'
    ): ?LadNotificationLog {
        $now = $now ?? Carbon::now();
        $dedupeKey = $this->generateDedupeKey($campaign, $step, $recipient, $instanceKey);

        $recipientType = $recipient instanceof Model ? $recipient->getMorphClass() : null;
        $recipientId = $recipient instanceof Model ? $recipient->getKey() : $recipient;

        if ($dryRun) {
            // Check if log already exists without inserting
            $exists = LadNotificationLog::where('dedupe_key', $dedupeKey)->exists();
            if ($exists) {
                return null;
            }

            return new LadNotificationLog([
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientId,
                'campaign' => $campaign->getName(),
                'step' => $step->getKey(),
                'dedupe_key' => $dedupeKey,
                'sent_at' => $now,
            ]);
        }

        DB::beginTransaction();

        try {
            /** @var LadNotificationLog $log */
            $log = LadNotificationLog::create([
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientId,
                'campaign' => $campaign->getName(),
                'step' => $step->getKey(),
                'dedupe_key' => $dedupeKey,
                'sent_at' => $now,
            ]);

            $this->dispatch($recipient, $step->getNotification());

            DB::commit();

            return $log;
        } catch (Throwable $e) {
            DB::rollBack();

            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Dispatch the notification, mailable, or callable payload to the recipient.
     */
    public function dispatch(mixed $recipient, mixed $notificationPayload): void
    {
        if ($notificationPayload === null) {
            return;
        }

        $resolved = $notificationPayload;

        if ($resolved instanceof Closure || is_callable($resolved)) {
            $resolved = $resolved($recipient);
        }

        if ($resolved === null) {
            return;
        }

        if (is_string($resolved) && class_exists($resolved)) {
            $resolved = app($resolved);
        }

        if ($resolved instanceof Notification) {
            if ($recipient instanceof Model && method_exists($recipient, 'notify')) {
                $recipient->notify($resolved);
            } else {
                NotificationFacade::send($recipient, $resolved);
            }

            return;
        }

        if ($resolved instanceof Mailable) {
            Mail::to($recipient)->send($resolved);
        }
    }

    /**
     * Check if the caught exception is a database unique constraint violation.
     */
    public function isUniqueViolation(Throwable $e): bool
    {
        $current = $e;

        while ($current !== null) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }

            if ($current instanceof QueryException) {
                $sqlState = (string) $current->getCode();
                $message = $current->getMessage();

                if ($sqlState === '23000'
                    || $sqlState === '23505'
                    || str_contains($message, 'UNIQUE constraint failed')
                    || str_contains($message, 'Duplicate entry')
                    || str_contains($message, 'unique constraint')
                    || str_contains($message, 'dedupe_key')
                    || str_contains($message, 'lad_states_recip_camp_inst_uniq')) {
                    return true;
                }
            }

            $current = $current->getPrevious();
        }

        return false;
    }
}
