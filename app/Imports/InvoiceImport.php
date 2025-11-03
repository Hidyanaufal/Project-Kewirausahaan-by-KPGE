<?php

namespace App\Imports;

use App\Models\Sales\Invoice;
use App\Models\Sales\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class InvoiceImport implements ToModel, WithHeadingRow, WithChunkReading, ShouldQueue
{
    protected int $companyId;
    protected bool $reset;

    public function __construct(int $companyId, bool $reset = false)
    {
        $this->companyId = $companyId;
        $this->reset = $reset;

        if ($reset) {
            Log::warning("Invoice Import - Reset enabled, deleting all invoices for company_id={$companyId}");
            Invoice::where('company_id', $companyId)->delete();
        } else {
            Log::info("Invoice Import - Reset disabled, keeping existing invoices for company_id={$companyId}");
        }
    }

    public function chunkSize(): int
    {
        return 200;
    }

    protected function parseAmount($value): float
    {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
        return (float) $value;
    }

    public function model(array $row)
    {
        Log::info("Invoice Import - Processing row", $row);

        if (empty($row['invoice_number']) || empty($row['client_name'])) {
            Log::warning("Invoice Import - Skipped row (missing invoice_number or client_name)", $row);
            return null;
        }

        // Cek duplikat invoice number
        if (Invoice::where('company_id', $this->companyId)->where('invoice_number', $row['invoice_number'])->exists()) {
            Log::info("Invoice Import - Skipped, invoice already exists: {$row['invoice_number']}");
            return null;
        }

        // Cari atau buat client
        $client = Client::firstOrCreate(
            [
                'company_id' => $this->companyId,
                'name' => $row['client_name']
            ],
            [
                'email' => $row['client_email'] ?? null,
                'phone' => $row['client_phone'] ?? null,
                'address' => $row['client_address'] ?? null,
            ]
        );

        // Parsing tanggal & nominal
        $invoiceDate = !empty($row['invoice_date'])
            ? \Carbon\Carbon::parse($row['invoice_date'])
            : now();

        $dueDate = !empty($row['due_date'])
            ? \Carbon\Carbon::parse($row['due_date'])
            : $invoiceDate->copy()->addDays(30);

        $amount = $this->parseAmount($row['amount'] ?? 0);

        $invoice = Invoice::create([
            'company_id' => $this->companyId,
            'client_id' => $client->id,
            'invoice_number' => $row['invoice_number'],
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'currency' => $row['currency'] ?? 'IDR',
            'amount' => $amount * 100, // dalam cent
            'tax' => $this->parseAmount($row['tax'] ?? 0) * 100,
            'discount' => $this->parseAmount($row['discount'] ?? 0) * 100,
            'description' => $row['description'] ?? null,
            'status' => $row['status'] ?? 'unpaid',
        ]);

        Log::info("Invoice Import - Invoice created: {$invoice->invoice_number}");

        return $invoice;
    }
}
