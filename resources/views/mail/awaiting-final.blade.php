@php
    $ink = '#011f5b';
    $orange = '#ff4200';
    $muted = '#74809a';
    $line = '#d7dde9';
    $wash = '#eef1f7';
    $red = '#c62828';
    $sans = "'Nunito Sans','Segoe UI',Helvetica,Arial,sans-serif";
    $titleFont = "'Nunito','Trebuchet MS','Segoe UI',Helvetica,Arial,sans-serif";

    $logoPath = public_path('img/logo.png');
    $logo = isset($message)
        ? $message->embed($logoPath)
        : 'data:image/png;base64,'.base64_encode(is_file($logoPath) ? (string) file_get_contents($logoPath) : '');

    $bani = fn ($v) => number_format((float) $v, 2, ',', '.');
    $zi = fn ($d) => $d?->format('d.m.Y') ?? '—';

    $toPay = collect($digest['to_pay'] ?? []);
    $recorded = collect($digest['recorded'] ?? []);
    $stale = collect($digest['stale'] ?? []);
    $totals = $digest['totals'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>De aprobat final</title>
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
                                    <div style="font-family:{{ $titleFont }};font-size:17px;font-weight:800;color:{{ $ink }};">Christian Tour</div>
                                    <div style="font-size:12px;color:{{ $muted }};">Aprobări · de semnat final</div>
                                </td>
                                <td align="right" style="vertical-align:middle;font-size:12px;color:{{ $muted }};">
                                    {{ $digest['today']->format('d.m.Y') }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 28px 8px;">
                        <h1 style="margin:0 0 6px;font-family:{{ $titleFont }};font-size:20px;font-weight:800;color:{{ $ink }};">
                            {{ $digest['count'] }} {{ $digest['count'] === 1 ? 'factură așteaptă' : 'facturi așteaptă' }} aprobarea finală
                        </h1>
                        <p style="margin:0;font-size:14px;line-height:1.55;color:{{ $muted }};">
                            Departamentele și-au dat acordul; mai rămâne semnătura dumneavoastră.
                            @if ($stale->isNotEmpty())
                                <span style="color:{{ $red }};">{{ $stale->count() }} {{ $stale->count() === 1 ? 'stă' : 'stau' }} de cel puțin {{ $digest['stale_days'] }} zile.</span>
                            @endif
                        </p>
                    </td>
                </tr>

                @if ($totals !== [])
                    <tr>
                        <td style="padding:14px 28px 4px;">
                            @foreach ($totals as $total)
                                <span style="display:inline-block;margin:0 10px 8px 0;padding:8px 12px;background:{{ $wash }};border-radius:10px;font-size:13px;">
                                    <strong style="font-family:{{ $titleFont }};">{{ $bani($total['rest']) }} {{ $total['moneda'] }}</strong>
                                    <span style="color:{{ $muted }};"> · {{ $total['count'] }} {{ $total['count'] === 1 ? 'factură' : 'facturi' }} de plătit</span>
                                </span>
                            @endforeach
                        </td>
                    </tr>
                @endif

                @foreach ([['De decis, cu rest de plată', $toPay, false], ['Deja plătite, așteaptă semnătura pentru evidență', $recorded, true]] as [$title, $list, $quiet])
                    @if ($list->isNotEmpty())
                        <tr>
                            <td style="padding:18px 28px 6px;">
                                <h2 style="margin:0;font-family:{{ $titleFont }};font-size:14px;font-weight:800;color:{{ $quiet ? $muted : $ink }};text-transform:uppercase;letter-spacing:.04em;">
                                    {{ $title }} ({{ $list->count() }})
                                </h2>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:6px 28px 4px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
                                    @foreach ($list->take(25) as $row)
                                        <tr>
                                            <td style="padding:9px 0;border-bottom:1px solid {{ $line }};">
                                                <div style="font-weight:700;">{{ $row['partner'] }}</div>
                                                <div style="font-size:12px;color:{{ $muted }};">
                                                    {{ $row['tip_doc'] }} {{ $row['nr_doc'] }} · {{ $zi($row['data_doc']) }}
                                                    @if ($row['data_scadenta'])
                                                        · scadentă <span style="{{ $row['overdue'] ? 'color:'.$red.';font-weight:700;' : '' }}">{{ $zi($row['data_scadenta']) }}</span>
                                                    @endif
                                                </div>
                                                <div style="font-size:12px;color:{{ $muted }};">
                                                    {{ implode(' · ', $row['departments']) ?: '—' }}
                                                    @if ($row['approved_by'])
                                                        · aprobat de {{ $row['approved_by'] }}
                                                    @endif
                                                    @if (($row['waiting_days'] ?? null) !== null)
                                                        <span style="{{ ($row['waiting_days'] >= $digest['stale_days']) ? 'color:'.$red.';font-weight:700;' : '' }}">
                                                            · de {{ $row['waiting_days'] }} {{ $row['waiting_days'] === 1 ? 'zi' : 'zile' }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td align="right" style="padding:9px 0;border-bottom:1px solid {{ $line }};white-space:nowrap;vertical-align:top;">
                                                <div style="font-family:{{ $titleFont }};font-weight:800;">{{ $bani($quiet ? $row['value'] : $row['rest']) }} {{ $row['moneda'] }}</div>
                                                @if (! $quiet && abs($row['value'] - $row['rest']) > 0.01)
                                                    <div style="font-size:11px;color:{{ $muted }};">din {{ $bani($row['value']) }}</div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                                @if ($list->count() > 25)
                                    <p style="margin:8px 0 0;font-size:12px;color:{{ $muted }};">
                                        … și încă {{ $list->count() - 25 }}. Lista întreagă e în aplicație.
                                    </p>
                                @endif
                            </td>
                        </tr>
                    @endif
                @endforeach

                <tr>
                    <td align="center" style="padding:22px 28px 26px;">
                        <a href="{{ url('/approvals?tab=final') }}" style="display:inline-block;padding:12px 26px;background:{{ $orange }};color:#ffffff;border-radius:9px;text-decoration:none;font-family:{{ $titleFont }};font-size:14px;font-weight:700;">Deschide aprobările</a>
                    </td>
                </tr>

                <tr>
                    <td style="padding:14px 28px 22px;border-top:1px solid {{ $line }};font-size:11px;color:{{ $muted }};">
                        Vestea pleacă în zilele de lucru, numai când e ceva de semnat. O factură dispare din listă în clipa în care primește decizia finală — aprobată, contestată sau amânată.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
