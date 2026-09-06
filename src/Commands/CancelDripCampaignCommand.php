<?php

namespace Lad\Commands;

use Illuminate\Console\Command;
use Lad\Services\LadEngine;

class CancelDripCampaignCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lad:cancel
                            {recipient_id : The ID of the recipient}
                            {campaign? : Optional campaign name to cancel}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually cancel active lifecycle/drip campaign states for a recipient';

    /**
     * Command aliases.
     *
     * @var list<string>
     */
    protected $aliases = [
        'lifecycle:cancel',
        'drip:cancel',
    ];

    /**
     * Execute the console command.
     */
    public function handle(LadEngine $engine): int
    {
        $recipientId = $this->argument('recipient_id');
        $campaign = $this->argument('campaign');

        $modelClass = config('lad.recipient_model', 'App\\Models\\User');
        $recipient = class_exists($modelClass) ? $modelClass::find($recipientId) : null;

        $target = $recipient ?? $recipientId;
        $cancelledCount = $engine->cancelRecipient($target, $campaign ? (string) $campaign : null, 'manual_artisan_command');

        if ($cancelledCount === 0) {
            $this->components->warn(sprintf('No active campaigns found to cancel for recipient #%s.', $recipientId));
        } else {
            $this->components->info(sprintf('Successfully cancelled %d active campaign state(s) for recipient #%s.', $cancelledCount, $recipientId));
        }

        return self::SUCCESS;
    }
}
