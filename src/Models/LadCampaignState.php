<?php

namespace Lad\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $recipient_type
 * @property int|string $recipient_id
 * @property string $campaign
 * @property string $instance_key
 * @property string $status
 * @property Carbon $anchor_at
 * @property string|null $last_step
 * @property Carbon|null $last_sent_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $recipient
 *
 * @method static Builder<static> forRecipient(Model|int|string $recipient, ?string $recipientType = null)
 * @method static Builder<static> active()
 * @method static Builder<static> campaign(string $campaign)
 * @method static Builder<static> instance(string $instanceKey = 'default')
 */
class LadCampaignState extends Model
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_CANCELLED = 'cancelled';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'campaign',
        'instance_key',
        'status',
        'anchor_at',
        'last_step',
        'last_sent_at',
        'completed_at',
        'cancelled_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('lad.table_names.states', parent::getTable());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anchor_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Polymorphic relation to the recipient model.
     *
     * @return MorphTo<Model, $this>
     */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Determine if the campaign state is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Determine if the campaign state is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Determine if the campaign state is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Determine if the campaign state is in a terminal state (completed or cancelled).
     */
    public function isTerminal(): bool
    {
        return $this->isCompleted() || $this->isCancelled();
    }

    /**
     * Mark the campaign state as cancelled.
     */
    public function cancel(?CarbonInterface $at = null): self
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => $at ?? Carbon::now(),
        ]);

        return $this;
    }

    /**
     * Mark the campaign state as completed.
     */
    public function complete(?CarbonInterface $at = null): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => $at ?? Carbon::now(),
        ]);

        return $this;
    }

    /**
     * Record a successful step dispatch.
     */
    public function recordDispatch(string $step, CarbonInterface $sentAt): self
    {
        $this->update([
            'last_step' => $step,
            'last_sent_at' => $sentAt,
        ]);

        return $this;
    }

    /**
     * Scope a query to only include states for a given recipient.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForRecipient(Builder $query, Model|int|string $recipient, ?string $recipientType = null): Builder
    {
        if ($recipient instanceof Model) {
            return $query->where('recipient_id', $recipient->getKey())
                ->where(function (Builder $q) use ($recipient): void {
                    $q->where('recipient_type', $recipient->getMorphClass())
                        ->orWhereNull('recipient_type');
                });
        }

        $query->where('recipient_id', $recipient);

        if ($recipientType !== null) {
            $query->where('recipient_type', $recipientType);
        }

        return $query;
    }

    /**
     * Scope a query to only include active states.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope a query to a specific campaign.
     *
     * @param  Builder<static>  $query
     */
    public function scopeCampaign(Builder $query, string $campaign): Builder
    {
        return $query->where('campaign', $campaign);
    }

    /**
     * Scope a query to a specific instance key.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInstance(Builder $query, string $instanceKey = 'default'): Builder
    {
        return $query->where('instance_key', $instanceKey);
    }
}
