@php
    $ink = '#011f5b';
    $green = '#047857';
    $muted = '#74809a';
    $line = '#d7dde9';
    $wash = '#eef1f7';
    $sans = "'Nunito Sans','Segoe UI',Helvetica,Arial,sans-serif";
    $titleFont = "'Nunito','Trebuchet MS','Segoe UI',Helvetica,Arial,sans-serif";

    $logoPath = public_path('img/logo.png');
    $logo = isset($message)
        ? $message->embed($logoPath)
        : 'data:image/png;base64,'.base64_encode(is_file($logoPath) ? (string) file_get_contents($logoPath) : '');
@endphp
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Confirmare</title>
</head>
<body style="margin:0;padding:0;background:{{ $wash }};font-family:{{ $sans }};color:{{ $ink }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $wash }};padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border:1px solid {{ $line }};border-radius:14px;overflow:hidden;">
                <tr>
                    <td style="padding:22px 28px 18px;border-bottom:3px solid {{ $green }};">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="44" style="width:44px;vertical-align:middle;">
                                    <img src="{{ $logo }}" width="40" height="41" alt="Christian Tour" style="display:block;width:40px;height:41px;border:0;">
                                </td>
                                <td style="padding-left:12px;vertical-align:middle;">
                                    <div style="font-family:{{ $titleFont }};font-size:17px;font-weight:800;color:{{ $ink }};">Christian Tour</div>
                                    <div style="font-size:12px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:{{ $muted }};">Proceduri interne</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px 28px 0;">
                        <h1 style="margin:0 0 6px;font-family:{{ $titleFont }};font-size:19px;font-weight:800;color:{{ $ink }};">
                            Am înregistrat luarea la cunoștință
                        </h1>
                        <p style="margin:0;font-size:13px;color:{{ $muted }};">
                            Păstrează mailul: e dovada ta.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 28px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid {{ $line }};border-radius:12px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <div style="font-family:{{ $titleFont }};font-size:15px;font-weight:700;">{{ $document->title }}</div>
                                    <div style="font-size:12px;color:{{ $muted }};margin-top:2px;">Procedura {{ $document->number }}</div>
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;font-size:13px;">
                                        <tr>
                                            <td style="padding:3px 0;color:{{ $muted }};">Nume</td>
                                            <td align="right" style="padding:3px 0;">{{ $consent->name }}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:3px 0;color:{{ $muted }};">Adresă</td>
                                            <td align="right" style="padding:3px 0;">{{ $consent->email }}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:3px 0;color:{{ $muted }};">Data și ora</td>
                                            <td align="right" style="padding:3px 0;font-weight:700;">{{ $consent->consented_at->timezone('Europe/Bucharest')->format('d.m.Y, H:i') }}</td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:14px 28px 26px;border-top:1px solid {{ $line }};">
                        <p style="margin:14px 0 0;font-size:11px;line-height:1.6;color:{{ $muted }};text-align:center;">
                            Dacă nu tu ai apăsat, spune-i Departamentului care ține procedurile — mailul a plecat pe adresa scrisă în formular.
                            Procedura rămâne de citit oricând la <a href="{{ $url }}" style="color:{{ $ink }};">această legătură</a>.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
