<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pengajuan Invoice Baru</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .button { display: inline-block; padding: 12px 24px; background: #22c55e; color: #fff !important; text-decoration: none; border-radius: 6px; margin-top: 16px; }
        .button:hover { background: #16a34a; }
        .muted { color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <h2>Pengajuan invoice baru</h2>
    <p>Ada pengajuan invoice baru yang menunggu tinjauan.</p>
    <p><strong>Nomor:</strong> {{ $invoice->invoice_number ?? 'Draft' }}</p>
    @if($invoice->client)
        <p><strong>Client:</strong> {{ $invoice->client->name }}</p>
    @endif
    <p class="muted">Silakan login dan tinjau data pengajuan di bawah ini.</p>
    <p>
        <a href="{{ $viewUrl }}" class="button">Lihat data pengajuan</a>
    </p>
    <p class="muted" style="margin-top: 24px;">Jika tombol tidak berfungsi, salin tautan ini ke browser: {{ $viewUrl }}</p>
</body>
</html>
