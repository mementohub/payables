@php
    $ink = '#011f5b';
    $orange = '#ff4200';
    $muted = '#74809a';
    $line = '#d7dde9';
    $wash = '#eef1f7';
    $sans = "'Nunito Sans','Segoe UI',Helvetica,Arial,sans-serif";
    $heading = "'Nunito','Trebuchet MS','Segoe UI',Helvetica,Arial,sans-serif";

    $logoPath = public_path('img/logo.png');
    $logo = isset($message)
        ? $message->embed($logoPath)
        : 'data:image/png;base64,'.base64_encode(is_file($logoPath) ? (string) file_get_contents($logoPath) : '');

    $money = $contract->value !== null
        ? number_format((float) $contract->value, 0, ',', '.').' '.($contract->currency ?? 'RON')
        : '—';
@endphp
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Contract</title>
</head>
<body style="margin:0;padding:0;background:{{ $wash }};font-family:{{ $sans }};color:{{ $ink }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $wash }};padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border:1px solid {{ $line }};border-radius:14px;overflow:hidden;">
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
                        <h1 style="margin:0 0 6px;font-family:{{ $heading }};font-size:19px;font-weight:800;color:{{ $ink }};">
                            {{ $sender?->name ?? 'Cineva' }} ți-a trimis un contract
                        </h1>
                        <p style="margin:0;font-size:13px;color:{{ $muted }};">{{ ucfirst($rights) }}.</p>
                        @if ($note)
                            <p style="margin:12px 0 0;padding:10px 12px;background:{{ $wash }};border-radius:8px;font-size:13px;">{{ $note }}</p>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 28px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid {{ $line }};border-radius:12px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <div style="font-family:{{ $heading }};font-size:15px;font-weight:700;">{{ $contract->title }}</div>
                                    <div style="font-size:12px;color:{{ $muted }};margin-top:2px;">{{ $contract->number }} · {{ $contract->partner_name }}</div>
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;font-size:13px;">
                                        <tr>
                                            <td style="padding:3px 0;color:{{ $muted }};">Valoare</td>
                                            <td align="right" style="padding:3px 0;">{{ $money }}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:3px 0;color:{{ $muted }};">Semnat</td>
                                            <td align="right" style="padding:3px 0;">{{ $contract->signed_at?->format('d.m.Y') ?? '—' }}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:3px 0;color:{{ $muted }};">Expiră</td>
                                            <td align="right" style="padding:3px 0;">{{ $contract->expires_at?->format('d.m.Y') ?? 'fără termen' }}</td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td align="center" style="padding:22px 28px 6px;">
                        <a href="{{ $url }}" style="display:inline-block;padding:12px 26px;background:{{ $orange }};color:#ffffff;border-radius:9px;text-decoration:none;font-family:{{ $heading }};font-size:14px;font-weight:700;">Deschide contractul</a>
                    </td>
                </tr>

                <tr>
                    <td style="padding:14px 28px 26px;border-top:1px solid {{ $line }};">
                        <p style="margin:14px 0 0;font-size:11px;line-height:1.6;color:{{ $muted }};text-align:center;">
                            @if ($share->expires_at)
                                Legătura e bună până la {{ $share->expires_at->format('d.m.Y') }}.
                            @else
                                Legătura nu expiră.
                            @endif
                            Contractul nu e atașat: stă în aplicație, iar deschiderile se văd în jurnalul lui.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
