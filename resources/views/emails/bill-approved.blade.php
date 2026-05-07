<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bill Disetujui</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .highlight { background: #f0fdf4; padding: 12px; border-radius: 6px; margin: 16px 0; }
        .button { display: inline-block; padding: 12px 24px; background: #22c55e; color: #fff !important; text-decoration: none; border-radius: 6px; margin-top: 16px; }
        .button:hover { background: #16a34a; }
        .muted { color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <h2>Bill disetujui</h2>
    <p>Pengajuan bill Anda telah disetujui.</p>
    <div class="highlight">
        <p style="margin: 0;"><strong>Nomor bill:</strong> {{ $bill->bill_number ?? 'Draft' }}</p>
        @if($bill->vendor)
            <p style="margin: 8px 0 0 0;"><strong>Vendor:</strong> {{ $bill->vendor->name }}</p>
        @endif
    </div>
    <p class="muted">Anda dapat melihat detail bill di aplikasi setelah login.</p>
    <p>
        <a href="{{ $viewUrl }}" class="button">Lihat detail bill</a>
    </p>
    <p class="muted" style="margin-top: 24px;">Jika tombol tidak berfungsi, salin tautan ini ke browser: {{ $viewUrl }}</p>
</body>
</html>
