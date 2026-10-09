@php
    /** Același croi ca raportul de trezorerie: tabel simplu, cifre aliniate. */
    $ink = '#011f5b';
    $orange = '#ff4200';
    $muted = '#74809a';
    $line = '#d7dde9';
    $wash = '#eef1f7';
    $red = '#c8102e';
    $sans = "'Nunito Sans','Segoe UI',Helvetica,Arial,sans-serif";
    $heading = "'Nunito','Trebuchet MS','Segoe UI',Helvetica,Arial,sans-serif";

    $logoPath = public_path('img/logo.png');
    $logo = isset($message)
        ? $message->embed($logoPath)
        : 'data:image/png;base64,'.base64_encode(is_file($logoPath) ? (string) file_get_contents($logoPath) : '');

    $money = fn (?float $value, ?string $currency) => $value === null
        ? '—'
        : number_format($value, 0, ',', '.').' '.($currency ?? 'RON');
@endphp
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Contracte</title>
</head>
<body style="margin:0;padding:0;background:{{ $wash }};font-family:{{ $sans }};color:{{ $ink }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $wash }};padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:100%;background:#ffffff;border:1px solid {{ $line }};border-radius:14px;overflow:hidden;">
                <tr>
                    <td style="padding:22px 28px 18px;border-bottom:3px solid {{ $orange }};">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="44" style="width:44px;vertical-align:middle;">
                                    <img src="{{ $logo }}" width="40" height="41" alt="Christian Tour" style="display:block;width:40px;height:41px;border:0;">
                                </td>
                                <td style="padding-left:12px;vertical-align:middle;">
                                    <div style="font-family:{{ $heading }};font-size:17px;font-weight:800;color:{{ $ink }};">Christian Tour</div>
                                    <div style="font-size:12px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:{{ $muted }};">Contract Management</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px 28px 0;">
                        <h1 style="margin:0 0 4px;font-family:{{ $heading }};font-size:20px;font-weight:800;color:{{ $ink }};">{{ $heading }}</h1>
                        @if ($intro)
                            <p style="margin:0 0 6px;font-size:13px;color:{{ $muted }};">{{ $intro }}</p>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:14px 28px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
                            <tr style="background:{{ $wash }};">
                                <th align="left" style="padding:9px 8px;font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:{{ $muted }};">Contract</th>
                                <th align="left" style="padding:9px 8px;font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:{{ $muted }};">Partener</th>
                                <th align="right" style="padding:9px 8px;font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:{{ $muted }};">Valoare</th>
                                <th align="right" style="padding:9px 8px;font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:{{ $muted }};">Expiră</th>
                            </tr>
                            @foreach ($rows as $row)
                                <tr>
                                    <td style="padding:9px 8px;border-bottom:1px solid {{ $line }};">
                                        <strong style="font-family:{{ $heading }};">{{ $row['number'] }}</strong>
                                        <div style="font-size:11px;color:{{ $muted }};">{{ \Illuminate\Support\Str::limit($row['title'], 46) }}</div>
                                        @if (! empty($row['notice']))
                                            <div style="font-size:11px;color:{{ $red }};">preaviz până la {{ $row['notice'] }}</div>
                                        @endif
                                    </td>
                                    <td style="padding:9px 8px;border-bottom:1px solid {{ $line }};">
                                        {{ \Illuminate\Support\Str::limit($row['partner'], 28) }}
                                        <div style="font-size:11px;color:{{ $muted }};">{{ $row['department'] ?? 'fără departament' }}{{ $row['owner'] ? ' · '.$row['owner'] : '' }}</div>
                                    </td>
                                    <td align="right" style="padding:9px 8px;border-bottom:1px solid {{ $line }};white-space:nowrap;">{{ $money($row['value'], $row['currency']) }}</td>
                                    <td align="right" style="padding:9px 8px;border-bottom:1px solid {{ $line }};white-space:nowrap;color:{{ ($row['days_left'] ?? 99) <= 30 ? $red : $ink }};">
                                        {{ $row['expires_at'] }}
                                        <div style="font-size:11px;color:{{ $muted }};">
                                            {{ ($row['days_left'] ?? 0) < 0 ? 'a trecut de '.abs($row['days_left']).' zile' : 'în '.$row['days_left'].' zile' }}
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>

                <tr>
                    <td align="center" style="padding:24px 28px 8px;">
                        <a href="{{ $url }}" style="display:inline-block;padding:12px 26px;background:{{ $orange }};color:#ffffff;border-radius:9px;text-decoration:none;font-family:{{ $heading }};font-size:14px;font-weight:700;">Deschide repertoriul</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 28px 26px;border-top:1px solid {{ $line }};">
                        <p style="margin:14px 0 0;font-size:11px;line-height:1.6;color:{{ $muted }};text-align:center;">
                            Mail automat — Christian Tour · Contract Management.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
