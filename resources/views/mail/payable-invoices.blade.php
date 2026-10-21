@php
    $ink = '#011f5b';
    $orange = '#ff4200';
    $muted = '#74809a';
    $line = '#d7dde9';
    $wash = '#eef1f7';
    $red = '#c62828';
    $sans = "'Nunito Sans','Segoe UI',Helvetica,Arial,sans-serif";
    $heading = "'Nunito','Trebuchet MS','Segoe UI',Helvetica,Arial,sans-serif";

    $logoPath = public_path('img/logo.png');
    $logo = isset($message)
        ? $message->embed($logoPath)
        : 'data:image/png;base64,'.base64_encode(is_file($logoPath) ? (string) file_get_contents($logoPath) : '');

    $bani = fn ($v) => number_format((float) $v, 2, ',', '.');
    $zi = fn ($d) => $d?->format('d.m.Y') ?? '—';

    $rows = collect($digest['rows'] ?? []);
    $overdue = collect($digest['overdue'] ?? []);
    $totals = $digest['totals'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Facturi gata de plată</title>
</head>
<body style="margin:0;padding:0;background:{{ $wash }};font-family:{{ $sans }};color:{{ $ink }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $wash }};padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="680" cellpadding="0" cellspacing="0" style="width:680px;max-width:100%;background:#ffffff;border:1px solid {{ $line }};border-radius:14px;overflow:hidden;">

                <tr>
                    <td style="padding:22px 28px 18px;border-bottom:3px solid {{ $orange }};">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="44" style="width:44px;vertical-align:middle;">
                                    <img src="{{ $logo }}" width="40" height="41" alt="Christian Tour" style="display:block;width:40px;height:41px;border:0;">
                                </td>
                                <td style="padding-left:12px;vertical-align:middle;">
                                    <div style="font-family:{{ $heading }};font-size:17px;font-weight:800;color:{{ $ink }};">Christian Tour</div>
                                    <div style="font-size:12px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:{{ $muted }};">Trezorerie · facturi de plată</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px 28px 0;">
                        <h1 style="margin:0 0 6px;font-family:{{ $heading }};font-size:19px;font-weight:800;color:{{ $ink }};">
                            @if ($rows->isEmpty())
                                Nicio factură de plătit
                            @elseif ($firstRun)
                                {{ $rows->count() }} facturi aprobate așteaptă plata
                            @else
                                {{ $rows->count() }} facturi sunt gata de plată
                            @endif
                        </h1>
                        <p style="margin:0;font-size:13px;color:{{ $muted }};">
                            @if ($rows->isEmpty())
                                Tot ce a fost aprobat a fost și plătit. Nimic de făcut săptămâna asta.
                            @elseif ($firstRun)
                                Prima listă: tot ce a primit aprobarea finală de la începuturi și n-a fost încă plătit.
                            @else
                                Au trecut de toate aprobările și pot fi puse la plată.
                            @endif
                        </p>
                    </td>
                </tr>

                @if ($rows->isNotEmpty())
                    <tr>
                        <td style="padding:18px 28px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid {{ $line }};border-radius:12px;">
                                <tr>
                                    <td style="padding:14px 16px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                                            @foreach ($totals as $total)
                                                <tr>
                                                    <td style="padding:4px 0;color:{{ $muted }};">De plătit, {{ $total['moneda'] }}</td>
                                                    <td align="right" style="padding:4px 0;font-family:{{ $heading }};font-size:17px;font-weight:800;">
                                                        {{ $bani($total['rest']) }} {{ $total['moneda'] }}
                                                    </td>
                                                    <td align="right" style="padding:4px 0 4px 12px;color:{{ $muted }};white-space:nowrap;">
                                                        {{ $total['count'] }} {{ $total['count'] === 1 ? 'factură' : 'facturi' }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                            @if ($overdue->isNotEmpty())
                                                <tr>
                                                    <td colspan="3" style="padding:10px 0 0;border-top:1px solid {{ $line }};font-size:13px;color:{{ $red }};">
                                                        <b>{{ $overdue->count() }}</b> {{ $overdue->count() === 1 ? 'are scadența trecută' : 'au scadența trecută' }} — merită plătite întâi.
                                                    </td>
                                                </tr>
                                            @endif
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:18px 28px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:0 0 6px;color:{{ $muted }};font-size:11px;letter-spacing:.6px;text-transform:uppercase;">Furnizor</td>
                                    <td style="padding:0 0 6px;color:{{ $muted }};font-size:11px;letter-spacing:.6px;text-transform:uppercase;">Factura</td>
                                    <td align="right" style="padding:0 0 6px;color:{{ $muted }};font-size:11px;letter-spacing:.6px;text-transform:uppercase;">Scadent</td>
                                    <td align="right" style="padding:0 0 6px;color:{{ $muted }};font-size:11px;letter-spacing:.6px;text-transform:uppercase;">De plătit</td>
                                </tr>
                                @foreach ($rows->take(40) as $row)
                                    <tr>
                                        <td style="padding:7px 0;border-top:1px solid {{ $line }};">
                                            {{ \Illuminate\Support\Str::limit($row['partner'], 34) }}
                                            @if ($row['department'])
                                                <div style="font-size:11px;color:{{ $muted }};">{{ $row['department'] }}</div>
                                            @endif
                                        </td>
                                        <td style="padding:7px 0;border-top:1px solid {{ $line }};font-size:12px;color:{{ $muted }};">
                                            {{ \Illuminate\Support\Str::limit($row['nr_doc'], 18) }}
                                            <div style="font-size:11px;">{{ $zi($row['data_doc']) }}</div>
                                        </td>
                                        <td align="right" style="padding:7px 0;border-top:1px solid {{ $line }};white-space:nowrap;{{ $row['overdue'] ? 'color:'.$red.';font-weight:700;' : '' }}">
                                            {{ $zi($row['data_scadenta']) }}
                                        </td>
                                        <td align="right" style="padding:7px 0;border-top:1px solid {{ $line }};white-space:nowrap;font-weight:700;">
                                            {{ $bani($row['rest']) }} {{ $row['moneda'] }}
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                            @if ($rows->count() > 40)
                                <p style="margin:10px 0 0;font-size:12px;color:{{ $muted }};">
                                    Și încă {{ $rows->count() - 40 }}. Lista întreagă e în aplicație.
                                </p>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding:22px 28px 6px;">
                            <a href="{{ url('/invoices/received?approval=approved&payment=unpaid') }}" style="display:inline-block;padding:12px 26px;background:{{ $orange }};color:#ffffff;border-radius:9px;text-decoration:none;font-family:{{ $heading }};font-size:14px;font-weight:700;">Deschide lista în aplicație</a>
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:14px 28px 26px;border-top:1px solid {{ $line }};">
                        <p style="margin:14px 0 0;font-size:11px;line-height:1.6;color:{{ $muted }};text-align:center;">
                            Mailul pleacă joia dimineața, către trezorerie. Sunt trecute numai facturile de furnizor care au primit
                            aprobarea finală de la Top Management și n-au fost încă achitate; cele amânate apar abia când le vine ziua,
                            iar cele sub {{ number_format((float) ($digest['min_lei'] ?? 50), 0, ',', '.') }} de lei nu se trec deloc.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
