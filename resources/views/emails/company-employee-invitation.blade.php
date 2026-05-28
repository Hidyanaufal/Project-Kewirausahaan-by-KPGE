<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Undangan Company</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .button { display: inline-block; padding: 12px 24px; background: #22c55e; color: #fff !important; text-decoration: none; border-radius: 6px; margin-top: 16px; }
        .button:hover { background: #16a34a; }
        .muted { color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <h2>Undangan bergabung ke company</h2>
    <p>
        Anda diundang untuk bergabung ke company
        <strong>{{ $companyName ?: 'Company' }}</strong>.
    </p>

    @if(!empty($role))
        <p><strong>Role:</strong> {{ $role }}</p>
    @endif

    <p class="muted">Silakan login untuk menerima undangan tersebut.</p>
    <p>
        <a href="{{ $loginUrl }}" class="button">Login</a>
    </p>
    <p class="muted" style="margin-top: 24px;">Jika tombol tidak berfungsi, salin tautan ini ke browser: {{ $loginUrl }}</p>
</body>
</html>

