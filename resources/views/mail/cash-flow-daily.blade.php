@php
    /** Un mail se citește pe telefon: tabel simplu, cifre aliniate, fără culori de ecran. */
    $money = fn (?float $value, int $decimals = 0) => $value === null ? '—' : number_format($value, $decimals, ',', '.');
    $currency = $digest['currency'];
@endphp
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trezorerie</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1c1917;">
<div style="max-width:720px;margin:0 auto;background:#ffffff;border:1px solid #e7e5e4;border-radius:12px;padding:24px;">

    <h1 style="margin:0 0 4px;font-size:18px;">Poziția de trezorerie</h1>
    <p style="margin:0 0 20px;font-size:13px;color:#78716c;">
        la {{ $digest['as_of'] ? \Carbon\CarbonImmutable::parse($digest['as_of'])->format('d.m.Y') : '—' }}
        · raport construit {{ optional($digest['built_at'])->format('d.m.Y H:i') ?? '—' }}
    </p>

    @if ($digest['problems'] !== [])
        <div style="margin:0 0 20px;padding:12px;border:1px solid #fcd34d;background:#fffbeb;border-radius:8px;font-size:13px;">
            <strong>Raportul e incomplet.</strong>
            <ul style="margin:6px 0 0;padding-left:18px;">
                @foreach ($digest['problems'] as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <table style="width:100%;border-collapse:collapse;margin:0 0 24px;font-size:14px;">
        <tbody>
        @foreach ($digest['position'] as $code => $amount)
            <tr>
                <td style="padding:6px 0;border-bottom:1px solid #f5f5f4;">{{ $code }}</td>
                <td style="padding:6px 0;border-bottom:1px solid #f5f5f4;text-align:right;font-variant-numeric:tabular-nums;">
                    {{ $money($amount, 2) }}
                </td>
            </tr>
        @endforeach
        <tr>
            <td style="padding:8px 0;font-weight:600;">Total în {{ $currency }}</td>
            <td style="padding:8px 0;text-align:right;font-weight:600;font-variant-numeric:tabular-nums;">
                {{ $money($digest['position_total']) }}
            </td>
        </tr>
        </tbody>
    </table>

    <h2 style="margin:0 0 8px;font-size:15px;">Următoarele {{ count($digest['weeks']) }} săptămâni</h2>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
        <tr style="color:#78716c;text-align:right;">
            <th style="padding:6px 4px;text-align:left;font-weight:500;">Săptămâna</th>
            <th style="padding:6px 4px;font-weight:500;">Încasări</th>
            <th style="padding:6px 4px;font-weight:500;">Plăți</th>
            <th style="padding:6px 4px;font-weight:500;">Flux net</th>
            <th style="padding:6px 4px;font-weight:500;">Sold final</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($digest['weeks'] as $week)
            <tr style="text-align:right;">
                <td style="padding:6px 4px;text-align:left;border-top:1px solid #f5f5f4;">S+{{ $week['index'] }} · {{ $week['week'] }}</td>
                <td style="padding:6px 4px;border-top:1px solid #f5f5f4;font-variant-numeric:tabular-nums;">{{ $money($week['in']) }}</td>
                <td style="padding:6px 4px;border-top:1px solid #f5f5f4;font-variant-numeric:tabular-nums;">{{ $money($week['out']) }}</td>
                <td style="padding:6px 4px;border-top:1px solid #f5f5f4;font-variant-numeric:tabular-nums;color:{{ $week['net'] < 0 ? '#b91c1c' : '#15803d' }};">{{ $money($week['net']) }}</td>
                <td style="padding:6px 4px;border-top:1px solid #f5f5f4;font-weight:600;font-variant-numeric:tabular-nums;color:{{ $week['closing'] < 0 ? '#b91c1c' : '#1c1917' }};">{{ $money($week['closing']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table style="width:100%;border-collapse:collapse;margin:20px 0 0;font-size:13px;">
        <tbody>
        <tr>
            <td style="padding:4px 0;color:#78716c;">Încasări 13 săptămâni</td>
            <td style="padding:4px 0;text-align:right;font-variant-numeric:tabular-nums;">{{ $money($digest['in_13']) }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0;color:#78716c;">Plăți 13 săptămâni</td>
            <td style="padding:4px 0;text-align:right;font-variant-numeric:tabular-nums;">{{ $money($digest['out_13']) }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0;color:#78716c;">Sold la 13 săptămâni</td>
            <td style="padding:4px 0;text-align:right;font-weight:600;font-variant-numeric:tabular-nums;">{{ $money($digest['closing_13']) }}</td>
        </tr>
        @if ($digest['min_closing'] !== null)
            <tr>
                <td style="padding:4px 0;color:#78716c;">Cel mai jos sold ({{ $digest['min_closing']['week'] }})</td>
                <td style="padding:4px 0;text-align:right;font-variant-numeric:tabular-nums;">{{ $money($digest['min_closing']['value']) }}</td>
            </tr>
        @endif
        </tbody>
    </table>

    <p style="margin:24px 0 0;">
        <a href="{{ $url }}" style="display:inline-block;padding:10px 16px;background:#1c1917;color:#ffffff;border-radius:8px;text-decoration:none;font-size:14px;">Deschide raportul</a>
    </p>

    <p style="margin:20px 0 0;font-size:12px;color:#a8a29e;">
        Toate cifrele sunt în {{ $currency }}, din raportul WCFR 52 Weeks. Raportul întreg e atașat.
    </p>
</div>
</body>
</html>
