<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixServiceInvoices extends Command
{
    // Tambahkan opsi --dry-run untuk preview tanpa mengubah data
    protected $signature = 'fix:service-invoices {--dry-run : Preview perubahan tanpa menyimpan ke database}';

    protected $description = 'Memperbaiki actual_total dan pph23 pada invoice bertipe service yang datanya lama/salah';

    public function handle(): void
    {
        // Cek apakah mode dry-run aktif
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('⚠️  DRY-RUN MODE: Tidak ada data yang akan diubah.');
        }

        $this->info('Mulai proses fix invoice service...');

        // Ambil semua invoice yang memiliki minimal 1 line item
        // dengan offering bertipe 'service', tanpa duplikat
        $invoices = DB::table('invoices')
            ->join('document_line_items', function ($join) {
                $join->on('document_line_items.documentable_id', '=', 'invoices.id')
                     ->where('document_line_items.documentable_type', '=', 'App\\Models\\Accounting\\Invoice');
            })
            ->join('offerings', 'offerings.id', '=', 'document_line_items.offering_id')
            ->where('offerings.type', 'service')
            ->select(
                'invoices.id',
                'invoices.status',
                'invoices.subtotal',
                'invoices.profit_total',
                'invoices.discount_total',
                'invoices.tax_total',
                'invoices.total',
                'invoices.pph23',
                'invoices.actual_total',
                'invoices.amount_paid',
            )
            ->whereIn('status', ['partial']) // hanya invoice yang sudah tidak draft
            // ->where('invoices.tax_total', '!=', DB::raw("invoices.subtotal * 0.11"))
            ->where('invoices.subtotal', '>', 0)
            ->distinct()
            ->get();

        $this->info("Ditemukan {$invoices->count()} invoice bertipe service.");
        $this->newLine();

        $fixed = 0;

        foreach ($invoices as $invoice) {

            // Hitung base: subtotal + profit - diskon
            $base = $invoice->subtotal
                  + ($invoice->profit_total ?? 0)
                  - ($invoice->discount_total ?? 0);

            // Hitung PPh23 sebesar 2% dari base (dibulatkan)
            $newPph23 = (int) round($base * 0.02);

            // Cek apakah WAPU (total > 1 Miliar)
            // Rule: jika subtotal < 10 juta => WAPU, jika >= 10 juta => non-WAPU
            $isWapu = (int) $invoice->subtotal < 10000000000;
            
            $newActualTotal = $isWapu
                ? $base - $newPph23
                : $base - $newPph23 + ($invoice->tax_total ?? 0);

            // Hitung amount_due baru (stored column: actual_total - amount_paid)
            $newAmountDue = $newActualTotal - ($invoice->amount_paid ?? 0);

            if ($isDryRun) {
                $newStatus = $newAmountDue < 0
                    ? 'overpaid'
                    : ($newAmountDue == 0 ? 'paid' : 'partial');

                // Tampilkan preview perubahan tanpa update database
                $this->line("Invoice ID <comment>{$invoice->id}</comment>");
                $this->line("  pph23        : {$invoice->pph23} → <comment>{$newPph23}</comment>");
                $this->line("  actual_total : {$invoice->actual_total} → <comment>{$newActualTotal}</comment>");
                $this->line("  amount_due   : " . ($invoice->actual_total - $invoice->amount_paid) . " → <comment>{$newAmountDue}</comment>");
                $this->line(" amount_paid   : {$invoice->amount_paid} (tidak berubah)");
                $this->line("  status       : {$invoice->status} → <comment>{$newStatus}</comment>");
                $this->newLine();
            } else {
                $newStatus = $newAmountDue < 0
                    ? 'overpaid'
                    : ($newAmountDue == 0 ? 'paid' : 'partial');

                // Update invoice ke database
                DB::table('invoices')
                    ->where('id', $invoice->id)
                    ->update([
                        'pph23'        => $newPph23,
                        'actual_total' => $newActualTotal,
                        'status'       => $newStatus, // update status jika sudah lunas/overpaid
                    ]);

                $this->line("✓ Invoice ID {$invoice->id} berhasil diupdate.");
            }

            $fixed++;
        }

        $this->newLine();

        if ($isDryRun) {
            $this->warn("DRY-RUN selesai. {$fixed} invoice akan diupdate jika dijalankan tanpa --dry-run.");
            $this->info('Jalankan tanpa --dry-run untuk menyimpan perubahan:');
            $this->line('  php artisan fix:service-invoices');
        } else {
            $this->info("Selesai! Total {$fixed} invoice berhasil diperbaiki.");
        }
    }
}