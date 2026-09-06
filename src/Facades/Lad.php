<?php

namespace Lad\Facades;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Facade;
use Lad\Campaign;
use Lad\Services\LadEngine;

/**
 * @method static LadEngine register(Campaign $campaign)
 * @method static array<string, Campaign> getCampaigns()
 * @method static Campaign|null getCampaign(string $name)
 * @method static LadEngine clearCampaigns()
 * @method static bool isOptedOut(mixed $recipient)
 * @method static bool isInCooldown(mixed $recipient, ?CarbonInterface $now = null)
 * @method static CarbonInterface|null getLastNotificationSentAt(mixed $recipient)
 * @method static bool canEnroll(Campaign $campaign, mixed $recipient, CarbonInterface $anchor, ?CarbonInterface $now = null)
 * @method static array|null evaluateRecipient(mixed $recipient, ?CarbonInterface $now = null, bool $dryRun = false, ?string $specificCampaign = null)
 * @method static list<array> run(?string $campaignName = null, mixed $specificRecipientId = null, bool $dryRun = false, ?CarbonInterface $now = null)
 * @method static string unsubscribeUrl(mixed $recipient)
 * @method static int cancelRecipient(mixed $recipient, ?string $campaign = null, string $reason = 'manual')
 * @method static void optOutRecipient(mixed $recipient)
 * @method static array getStatusForRecipient(mixed $recipient, ?CarbonInterface $now = null)
 *
 * @see LadEngine
 */
class Lad extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'lad.engine';
    }
}
