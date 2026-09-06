<?php

namespace Lad\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lad\Campaign;
use Lad\Models\LadCampaignState;
use Lad\Models\LadNotificationLog;
use Lad\Step;

class StepDispatched
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly mixed $recipient,
        public readonly Campaign $campaign,
        public readonly Step $step,
        public readonly LadCampaignState $state,
        public readonly LadNotificationLog $log
    ) {}
}
