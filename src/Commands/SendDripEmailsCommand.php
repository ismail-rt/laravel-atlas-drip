<?php

namespace Lad\Commands;

use Illuminate\Console\Command;
use Lad\Services\LadEngine;

class SendDripEmailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lad:send
                            {--dry-run : Simulate execution without dispatching emails or updating state}
                            {--campaign= : Filter to a specific campaign}
                            {--recipient= : Process only a specific recipient ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch due lifecycle and drip campaign emails';

    /**
     * Command aliases.
     *
     * @var list<string>
     */
    protected $aliases = [
        'lifecycle:send',
        'drip:send',
    ];

    /**
     * Execute the console command.
     */
    public function handle(LadEngine $engine): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $campaign = $this->option('campaign');
        $recipientId = $this->option('recipient');

        if ($dryRun) {
            $this->warn('Running in DRY-RUN mode. No emails will be sent and no database states modified.');
        }

        $this->info('Evaluating recipients for due drip steps...');

        $dispatches = $engine->run(
            campaignName: $campaign ? (string) $campaign : null,
            specificRecipientId: $recipientId,
            dryRun: $dryRun
        );

        if (empty($dispatches)) {
            $this->info('No due drip steps found.');

            return self::SUCCESS;
        }

        $rows = array_map(function (array $dispatch): array {
            $recipient = $dispatch['recipient'];
            $recipientId = is_object($recipient) && method_exists($recipient, 'getKey')
                ? $recipient->getKey()
                : (string) $recipient;

            return [
                'recipient_id' => $recipientId,
                'campaign' => $dispatch['campaign'],
                'step' => $dispatch['step'],
                'sent_at' => $dispatch['sent_at']->toDateTimeString(),
                'status' => $dispatch['dry_run'] ? 'Simulated' : 'Dispatched',
            ];
        }, $dispatches);

        $this->table(['Recipient ID', 'Campaign', 'Step', 'Timestamp', 'Status'], $rows);

        foreach ($rows as $row) {
            $this->line(sprintf(' - [%s] Recipient #%s', $row['status'], $row['recipient_id']));
            $this->line(sprintf('   Campaign: %s', $row['campaign']));
            $this->line(sprintf('   Step: %s', $row['step']));
        }

        $this->info(sprintf('Finished. Total %d step(s) %s.', count($dispatches), $dryRun ? 'simulated' : 'dispatched'));

        return self::SUCCESS;
    }
}
