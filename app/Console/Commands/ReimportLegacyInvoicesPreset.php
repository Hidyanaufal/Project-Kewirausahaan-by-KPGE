<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ReimportLegacyInvoicesPreset extends Command
{
    protected $signature = 'legacy:reimport-invoices-preset
                            {preset : kpp, kppe, atau both}
                            {--dry-run : Preview tanpa mengubah database}
                            {--only-broken : Hanya reset invoice imported yang rusak}
                            {--prefer-supplement : Prioritaskan sheet supplement (t) untuk angka finansial}
                            {--actor=1 : User ID untuk created_by / updated_by}';

    protected $description = 'Re-import workbook legacy KPP/KPPE dengan preset company dan file Excel';

    /**
     * @var array<string, array{company_id: int, label: string, file: string}>
     */
    protected array $presets = [
        'kpp' => [
            'company_id' => 2,
            'label' => 'KPP — PT. Karya Persada Panas Bumi',
            'file' => 'Copy of Master Project & Invoice - kpp.xlsx',
        ],
        'kppe' => [
            'company_id' => 3,
            'label' => 'KPPE — PT. Ksatria Pratama Persada Energi',
            'file' => 'Copy of Master Project & Invoice - kppe.xlsx',
        ],
    ];

    public function handle(): int
    {
        $preset = strtolower((string) $this->argument('preset'));
        $keys = match ($preset) {
            'kpp', 'kppe' => [$preset],
            'both', 'all' => ['kpp', 'kppe'],
            default => null,
        };

        if ($keys === null) {
            $this->error("Preset '{$preset}' tidak dikenal. Gunakan: kpp, kppe, atau both.");

            return self::FAILURE;
        }

        foreach ($keys as $key) {
            $config = $this->presets[$key];
            $filePath = $this->resolveFilePath($config['file']);

            $this->newLine();
            $this->info("=== {$config['label']} (company {$config['company_id']}) ===");
            $this->line("File: {$filePath}");

            $exitCode = $this->call('legacy:reimport-invoices', [
                'companyId' => $config['company_id'],
                '--file' => $filePath,
                '--dry-run' => $this->option('dry-run'),
                '--only-broken' => $this->option('only-broken'),
                '--prefer-supplement' => $this->option('prefer-supplement'),
                '--actor' => $this->option('actor'),
            ]);

            if ($exitCode !== self::SUCCESS) {
                $this->error("Re-import {$key} gagal.");

                return $exitCode;
            }
        }

        if (count($keys) > 1) {
            $this->newLine();
            $this->info('Selesai re-import KPP dan KPPE.');
        }

        return self::SUCCESS;
    }

    protected function resolveFilePath(string $file): string
    {
        $resolvedPath = base_path($file);

        if (! File::exists($resolvedPath)) {
            $this->error("File tidak ditemukan: {$resolvedPath}");

            exit(self::FAILURE);
        }

        return $resolvedPath;
    }
}
