<?php

namespace App\Console\Commands;

use App\Support\LedgerIntegrity;
use Illuminate\Console\Command;

class VerifyLedger extends Command
{
    protected $signature = 'accounting:verify-ledger {--examples=10 : how many mismatches to show}';

    protected $description = 'Checks that the posted entries, their lines and the stored report totals agree';

    public function handle(): int
    {
        $result = LedgerIntegrity::check((int) $this->option('examples'));

        $this->line("Posted entries checked:          {$result['postedEntries']}");
        $this->line("Entries that do not add up:      {$result['brokenEntries']}");
        $this->line("Report totals that do not match: {$result['totalsMismatches']}");

        if ($result['ok']) {
            $this->info('The ledger is consistent.');

            return self::SUCCESS;
        }

        $this->error('The ledger is NOT consistent.');
        foreach ($result['examples'] as $example) {
            $this->line(json_encode($example, JSON_UNESCAPED_UNICODE));
        }

        return self::FAILURE;
    }
}
