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
 * @property int|string|null $recipient_id
 * @property string $campaign
 * @property string $step
 * @property string $dedupe_key
 * @property Carbon $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $recipient
 *
 * @method static Builder<static> forRecipient(Model|int|string $recipient, ?string $recipientType = null)
 * @method static Builder<static> sentSince(CarbonInterface $timestamp)
 */
class LadNotificationLog extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'campaign',
        'step',
        'dedupe_key',
        'sent_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('lad.table_names.logs', parent::getTable());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
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
     * Scope a query to only include logs for a given recipient.
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
     * Scope a query to only include logs sent after the given timestamp.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSentSince(Builder $query, CarbonInterface $timestamp): Builder
    {
        return $query->where('sent_at', '>=', $timestamp);
    }
}
