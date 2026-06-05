<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Project Disetujui</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .highlight { background: #f0fdf4; padding: 12px; border-radius: 6px; margin: 16px 0; }
        .button { display: inline-block; padding: 12px 24px; background: #22c55e; color: #fff !important; text-decoration: none; border-radius: 6px; margin-top: 16px; }
        .button:hover { background: #16a34a; }
        .muted { color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <h2>Project disetujui</h2>
    <p>
        @if($recipientName)
            Halo {{ $recipientName }},
        @else
            Halo,
        @endif
    </p>
    <p>Pengajuan project Anda telah disetujui.</p>
    <div class="highlight">
        <p style="margin: 0;"><strong>Modul:</strong> Project</p>
        <p style="margin: 8px 0 0 0;"><strong>Nomor project:</strong> {{ $project->number }}</p>
        <p style="margin: 8px 0 0 0;"><strong>Nama project:</strong> {{ $project->name }}</p>
        <p style="margin: 8px 0 0 0;"><strong>Status:</strong> {{ $approvalStatus }}</p>
        @if($approvalDate)
            <p style="margin: 8px 0 0 0;"><strong>Tanggal disetujui:</strong> {{ $approvalDate->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
        @endif
    </div>
    <p class="muted">Anda dapat melihat detail project di aplikasi setelah login.</p>
    <p>
        <a href="{{ $viewUrl }}" class="button">Lihat detail project</a>
    </p>
    <p class="muted" style="margin-top: 24px;">Jika tombol tidak berfungsi, salin tautan ini ke browser: {{ $viewUrl }}</p>
    <p class="muted" style="margin-top: 24px;">Pesan ini dikirim otomatis oleh sistem. Mohon tidak membalas email ini.</p>
</body>
</html>
