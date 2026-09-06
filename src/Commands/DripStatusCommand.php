<?php

namespace Lad\Commands;

use Illuminate\Console\Command;
use Lad\Services\LadEngine;

class DripStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lad:status {recipient_id : The ID of the recipient to inspect}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect lifecycle/drip campaign states and due dates for a recipient';

    /**
     * Command aliases.
     *
     * @var list<string>
     */
    protected $aliases = [
        'lifecycle:status',
        'drip:status',
    ];

    /**
     * Execute the console command.
     */
    public function handle(LadEngine $engine): int
    {
        $recipientId = $this->argument('recipient_id');
        $modelClass = config('lad.recipient_model', 'App\\Models\\User');
        $recipient = class_exists($modelClass) ? $modelClass::find($recipientId) : null;

        $target = $recipient ?? $recipientId;
        $status = $engine->getStatusForRecipient($target);

        $this->components->info(sprintf('Status for Recipient #%s:', $status['recipient_id']));
        $this->line(sprintf(' - Global Cooldown: %s', $status['in_cooldown'] ? '<fg=red>ACTIVE (Paused)</>' : '<fg=green>INACTIVE (Ready)</>'));
        $this->line(sprintf(' - Opted Out: %s', $status['is_opted_out'] ? '<fg=red>YES</>' : '<fg=green>NO</>'));
        $this->line(sprintf(' - Last Notification Sent At: %s', $status['last_sent_at'] ?? 'Never'));

        if (empty($status['campaigns'])) {
            $this->components->warn('No registered campaigns found in engine.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($status['campaigns'] as $campaignName => $info) {
            $rows[] = [
                'campaign' => $campaignName,
                'state' => $info['state'],
                'instance' => $info['instance_key'],
                'anchor_at' => $info['anchor_at'] ?? 'N/A',
                'last_step' => $info['last_step'] ?? 'None',
                'next_step' => $info['next_step'] ?? ($info['state'] === 'completed' ? 'Completed' : 'None'),
                'due_at' => $info['due_at'] ?? 'N/A',
                'is_due' => $info['is_due'] ? 'YES' : 'NO',
            ];
        }

        $this->newLine();
        $this->table(['Campaign', 'Status', 'Instance', 'Anchor At', 'Last Step', 'Next Step', 'Due At', 'Is Due?'], $rows);

        return self::SUCCESS;
    }
}
