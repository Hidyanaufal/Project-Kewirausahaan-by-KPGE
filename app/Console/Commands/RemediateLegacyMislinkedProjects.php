<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LegacyMislinkedProjectRemediationService;
use Illuminate\Console\Command;

class RemediateLegacyMislinkedProjects extends Command
{
    protected $signature = 'legacy:remediate-mislinked-projects
                            {companyId : Company ID}
                            {--invoice=* : Invoice number(s) to remediate (e.g. PG25-210)}
                            {--dry-run : Preview without updating project links}
                            {--actor=1 : User ID used as updated_by}';

    protected $description = 'Relink imported invoices whose project name does not match line-item description (e.g. KG027 → Spot Charter false positives)';

    public function handle(LegacyMislinkedProjectRemediationService $service): int
    {
        $companyId = (int) $this->argument('companyId');
        $actorId = (int) $this->option('actor');
        $dryRun = (bool) $this->option('dry-run');
        /** @var list<string> $invoiceNumbers */
        $invoiceNumbers = array_values(array_filter((array) $this->option('invoice')));

        if (! User::query()->whereKey($actorId)->exists()) {
            $this->error("User ID {$actorId} tidak ditemukan.");

            return self::FAILURE;
        }

        $summary = $service->remediate(
            companyId: $companyId,
            actorId: $actorId,
            invoiceNumbers: $invoiceNumbers,
            dryRun: $dryRun,
        );

        $this->info(sprintf(
            'Mode: %s | Scanned: %d | Remediated: %d | Skipped: %d',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $summary['scanned'],
            $summary['remediated'],
            $summary['skipped'],
        ));

        foreach ($summary['details'] as $detail) {
            $this->line(sprintf(
                '- %s [%s]%s',
                $detail['invoice_number'],
                $detail['status'],
                isset($detail['old_project_number'])
                    ? " {$detail['old_project_number']} → " . ($detail['new_project_number'] ?? '(stub)')
                    : (isset($detail['reason']) ? " ({$detail['reason']})" : ''),
            ));
        }

        return self::SUCCESS;
    }
}
