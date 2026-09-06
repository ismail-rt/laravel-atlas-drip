<?php

namespace Lad\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lad\Campaign;
use Lad\Models\LadCampaignState;

class CampaignCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly mixed $recipient,
        public readonly Campaign $campaign,
        public readonly LadCampaignState $state
    ) {}
}
