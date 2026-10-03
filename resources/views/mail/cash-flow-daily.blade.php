@php
    /**
     * Mailul poartă culorile aplicației: albastrul Christian Tour pentru text
     * și cap de tabel, portocaliul doar pe ce se apasă și pe ce doare. Totul
     * e scris în stiluri pe element, fiindcă Gmail aruncă foile de stil; ce
     * ține de font rămâne și în <style>, pentru clienții care le citesc.
     */
    $ink = '#011f5b';          // albastrul mărcii
    $orange = '#ff4200';       // portocaliul mărcii
    $muted = '#74809a';
    $line = '#d7dde9';
    $wash = '#eef1f7';
    $red = '#c8102e';
    $green = '#1e7d3b';
    $sans = "'Nunito Sans','Segoe UI',Helvetica,Arial,sans-serif";
    $heading = "'Nunito','Trebuchet MS','Segoe UI',Helvetica,Arial,sans-serif";

    /**
     * Sigla merge în mail ca atașament ascuns (cid:), nu ca adresă de pe
     * internet: altfel clienții de mail o blochează până dă omul clic pe
     * „arată imaginile”. La o randare fără mesaj (probă, test) rămâne scrisă
     * în pagină.
     */
    $logoPath = public_path('img/logo.png');
    $logo = isset($message)
        ? $message->embed($logoPath)
        : 'data:image/png;base64,'.base64_encode(is_file($logoPath) ? (string) file_get_contents($logoPath) : '');

    $money = fn (?float $value, int $decimals = 0) => $value === null ? '—' : number_format($value, $decimals, ',', '.');
    $currency = $digest['currency'];
@endphp
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Poziția de trezorerie</title>
    <style>
        @import url('https://fonts.bunny.net/css?family=nunito:600,700,800|nunito-sans:400,600,700');
        body { margin: 0; padding: 0; }
        a { color: {{ $ink }}; }
        @media (max-width: 600px) {
            .ct-pad { padding: 20px 16px !important; }
            .ct-hide-sm { display: none !important; }
            .ct-total { font-size: 26px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:{{ $wash }};font-family:{{ $sans }};color:{{ $ink }};-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $wash }};padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:100%;background:#ffffff;border:1px solid {{ $line }};border-radius:14px;overflow:hidden;">

                {{-- Antet: sigla și numele aplicației, pe linia portocalie a mărcii --}}
                <tr>
                    <td class="ct-pad" style="padding:22px 28px 18px;border-bottom:3px solid {{ $orange }};">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="44" style="width:44px;vertical-align:middle;">
                                    <img src="{{ $logo }}" width="40" height="41" alt="Christian Tour" style="display:block;width:40px;height:41px;border:0;">
                                </td>
                                <td style="padding-left:12px;vertical-align:middle;">
                                    <div style="font-family:{{ $heading }};font-size:17px;font-weight:800;letter-spacing:-0.2px;color:{{ $ink }};">Christian Tour</div>
                                    <div style="font-size:12px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:{{ $muted }};">Cash Flow · Payables</div>
                                </td>
                                <td class="ct-hide-sm" align="right" style="vertical-align:middle;font-size:12px;color:{{ $muted }};">
                                    {{ optional($digest['built_at'])->format('d.m.Y H:i') ?? '—' }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="ct-pad" style="padding:26px 28px 0;">
                        <h1 style="margin:0 0 4px;font-family:{{ $heading }};font-size:21px;font-weight:800;color:{{ $ink }};">Poziția de trezorerie</h1>
                        <p style="margin:0;font-size:13px;color:{{ $muted }};">
                            la {{ $digest['as_of'] ? \Carbon\CarbonImmutable::parse($digest['as_of'])->format('d.m.Y') : '—' }}
                            · raport WCFR 52 Weeks
                        </p>
                    </td>
                </tr>

                @if ($digest['problems'] !== [])
                    <tr>
                        <td class="ct-pad" style="padding:18px 28px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-left:3px solid {{ $orange }};background:#fff6f2;border-radius:0 8px 8px 0;">
                                <tr>
                                    <td style="padding:12px 14px;font-size:13px;color:{{ $ink }};">
                                        <strong style="font-family:{{ $heading }};">Raportul e incomplet.</strong>
                                        @foreach ($digest['problems'] as $problem)
                                            <div style="margin-top:4px;color:{{ $muted }};">{{ $problem }}</div>
                                        @endforeach
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif

                {{-- Banii de azi: totalul mare, monedele sub el --}}
                <tr>
                    <td class="ct-pad" style="padding:20px 28px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $ink }};border-radius:12px;">
                            <tr>
                                <td style="padding:18px 20px 14px;">
                                    <div style="font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#9fb0d4;">Total în {{ $currency }}</div>
                                    <div class="ct-total" style="font-family:{{ $heading }};font-size:32px;font-weight:800;color:#ffffff;line-height:1.2;">{{ $money($digest['position_total']) }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:0 20px 18px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid rgba(255,255,255,0.18);">
                                        @foreach ($digest['position'] as $code => $amount)
                                            <tr>
                                                <td style="padding:7px 0 0;font-size:13px;font-weight:700;color:#ffffff;">{{ $code }}</td>
                                                <td align="right" style="padding:7px 0 0;font-size:13px;color:#dfe7f6;font-variant-numeric:tabular-nums;">{{ $money($amount, 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Săptămâna în curs, pe liniile raportului --}}
                @if ($digest['top_in'] !== [] || $digest['top_out'] !== [])
                    <tr>
                        <td class="ct-pad" style="padding:26px 28px 0;">
                            <h2 style="margin:0 0 2px;font-family:{{ $heading }};font-size:15px;font-weight:700;color:{{ $ink }};">Săptămâna în curs</h2>
                            @if ($digest['current_week'] !== null)
                                <p style="margin:0 0 10px;font-size:12px;color:{{ $muted }};">{{ $digest['current_week']['from'] }} – {{ $digest['current_week']['to'] }} · cele mai mari linii ale săptămânii, cu ce stă sub ele</p>
                            @endif

                            @foreach ([['Top 3 linii de încasat', $digest['top_in'], $green], ['Top 3 linii de plătit', $digest['top_out'], $ink]] as [$title, $lines, $tone])
                                @continue($lines === [])
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid {{ $line }};border-radius:12px;margin:0 0 12px;">
                                    <tr>
                                        <td colspan="2" style="padding:11px 16px 4px;font-family:{{ $heading }};font-size:12px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;color:{{ $tone }};">
                                            {{ $title }}
                                        </td>
                                    </tr>
                                    @foreach ($lines as $row)
                                        <tr>
                                            <td style="padding:8px 16px 0;font-size:13px;color:{{ $ink }};">
                                                <span style="display:inline-block;min-width:30px;font-family:{{ $heading }};font-weight:800;color:{{ $tone }};">{{ $row['code'] }}</span>
                                                <span style="font-weight:700;">{{ $row['label'] }}</span>
                                            </td>
                                            <td align="right" style="padding:8px 16px 0;font-size:13px;font-weight:700;color:{{ $tone }};font-variant-numeric:tabular-nums;white-space:nowrap;vertical-align:top;">
                                                {{ $money($row['lei']) }}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="2" style="padding:3px 16px {{ $loop->last ? '13px' : '10px' }};{{ $loop->last ? '' : 'border-bottom:1px solid '.$wash.';' }}">
                                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                    @foreach ($row['documents'] as $doc)
                                                        <tr>
                                                            <td style="padding:2px 0;font-size:11px;line-height:1.5;color:{{ $muted }};">
                                                                <span style="color:{{ $ink }};font-weight:600;">{{ $doc['partner'] }}</span>
                                                                @if ($doc['bank'])
                                                                    · prin bancă
                                                                @elseif ($doc['reference'] !== null)
                                                                    · {{ $doc['reference'] }}
                                                                @endif
                                                                @if (! empty($doc['group'])) · {{ $doc['group'] }} @endif
                                                                @if (! empty($doc['date'])) · scadent {{ $doc['date'] }} @endif
                                                                @if (! empty($doc['currency']) && $doc['currency'] !== 'RON' && $doc['amount'] !== null)
                                                                    · {{ $money($doc['amount'], 2) }} {{ $doc['currency'] }}
                                                                @endif
                                                            </td>
                                                            <td align="right" style="padding:2px 0;font-size:11px;color:{{ $ink }};font-variant-numeric:tabular-nums;white-space:nowrap;">{{ $money($doc['lei']) }}</td>
                                                        </tr>
                                                    @endforeach
                                                    @if ($row['rest'] > 0)
                                                        <tr>
                                                            <td colspan="2" style="padding:2px 0;font-size:11px;line-height:1.5;color:{{ $muted }};">+ încă {{ number_format($row['rest'], 0, ',', '.') }} {{ $row['rest'] === 1 ? 'bucată' : 'bucăți' }} pe linia asta</td>
                                                        </tr>
                                                    @endif
                                                </table>
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endforeach
                        </td>
                    </tr>
                @endif

                {{-- Săptămânile apropiate --}}
                <tr>
                    <td class="ct-pad" style="padding:26px 28px 0;">
                        <h2 style="margin:0 0 10px;font-family:{{ $heading }};font-size:15px;font-weight:700;color:{{ $ink }};">Următoarele {{ count($digest['weeks']) }} săptămâni</h2>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
                            <tr style="background:{{ $wash }};">
                                <th align="left" style="padding:9px 8px;font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:{{ $muted }};border-radius:8px 0 0 8px;">Săptămâna</th>
                                <th align="right" style="padding:9px 8px;font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:{{ $muted }};">Încasări</th>
                                <th align="right" style="padding:9px 8px;font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:{{ $muted }};">Plăți</th>
                                <th align="right" class="ct-hide-sm" style="padding:9px 8px;font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:{{ $muted }};">Flux net</th>
                                <th align="right" style="padding:9px 8px;font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:{{ $muted }};border-radius:0 8px 8px 0;">Sold final</th>
                            </tr>
                            @foreach ($digest['weeks'] as $week)
                                <tr>
                                    <td style="padding:9px 8px;border-bottom:1px solid {{ $line }};color:{{ $ink }};">
                                        <strong style="font-family:{{ $heading }};">S+{{ $week['index'] }}</strong>
                                        <span style="color:{{ $muted }};">{{ $week['week'] }}</span>
                                    </td>
                                    <td align="right" style="padding:9px 8px;border-bottom:1px solid {{ $line }};font-variant-numeric:tabular-nums;">{{ $money($week['in']) }}</td>
                                    <td align="right" style="padding:9px 8px;border-bottom:1px solid {{ $line }};font-variant-numeric:tabular-nums;">{{ $money($week['out']) }}</td>
                                    <td align="right" class="ct-hide-sm" style="padding:9px 8px;border-bottom:1px solid {{ $line }};font-variant-numeric:tabular-nums;color:{{ $week['net'] < 0 ? $red : $green }};">{{ $money($week['net']) }}</td>
                                    <td align="right" style="padding:9px 8px;border-bottom:1px solid {{ $line }};font-weight:700;font-variant-numeric:tabular-nums;color:{{ $week['closing'] < 0 ? $red : $ink }};">{{ $money($week['closing']) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>

                {{-- Cifrele pe 13 săptămâni --}}
                <tr>
                    <td class="ct-pad" style="padding:22px 28px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $wash }};border-radius:12px;">
                            <tr>
                                <td style="padding:14px 18px;font-size:13px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td style="padding:4px 0;color:{{ $muted }};">Încasări 13 săptămâni</td>
                                            <td align="right" style="padding:4px 0;font-variant-numeric:tabular-nums;">{{ $money($digest['in_13']) }}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:4px 0;color:{{ $muted }};">Plăți 13 săptămâni</td>
                                            <td align="right" style="padding:4px 0;font-variant-numeric:tabular-nums;">{{ $money($digest['out_13']) }}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:4px 0;color:{{ $muted }};">Sold la 13 săptămâni</td>
                                            <td align="right" style="padding:4px 0;font-weight:700;font-variant-numeric:tabular-nums;">{{ $money($digest['closing_13']) }}</td>
                                        </tr>
                                        @if ($digest['min_closing'] !== null)
                                            <tr>
                                                <td style="padding:4px 0;color:{{ $muted }};">Cel mai jos sold ({{ $digest['min_closing']['week'] }})</td>
                                                <td align="right" style="padding:4px 0;font-variant-numeric:tabular-nums;color:{{ $digest['min_closing']['value'] < 0 ? $red : $ink }};">{{ $money($digest['min_closing']['value']) }}</td>
                                            </tr>
                                        @endif
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="ct-pad" align="center" style="padding:26px 28px 6px;">
                        <a href="{{ $url }}" style="display:inline-block;padding:12px 26px;background:{{ $orange }};color:#ffffff;border-radius:9px;text-decoration:none;font-family:{{ $heading }};font-size:14px;font-weight:700;">Deschide raportul</a>
                    </td>
                </tr>

                <tr>
                    <td class="ct-pad" style="padding:18px 28px 26px;border-top:1px solid {{ $line }};">
                        <p style="margin:14px 0 0;font-size:11px;line-height:1.6;color:{{ $muted }};text-align:center;">
                            Toate cifrele sunt în {{ $currency }}, din raportul WCFR 52 Weeks construit în noaptea precedentă.<br>
                            Raportul întreg e atașat. Mail automat — Christian Tour · Payables.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
