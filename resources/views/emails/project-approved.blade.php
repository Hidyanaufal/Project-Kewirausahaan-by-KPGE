<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Project Disetujui</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .highlight { background: #f0fdf4; padding: 12px; border-radius: 6px; margin: 16px 0; }
        .muted { color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <h2>Project disetujui</h2>
    <p>Pengajuan project Anda telah disetujui.</p>
    <div class="highlight">
        <p style="margin: 0;"><strong>Nomor project:</strong> {{ $project->number }}</p>
        <p style="margin: 8px 0 0 0;"><strong>Nama project:</strong> {{ $project->name }}</p>
    </div>
    <p class="muted">Anda dapat melihat detail project di aplikasi.</p>
</body>
</html>
