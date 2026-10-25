{{--
    Planul pe hârtie: o filă de titlu cu ipotezele scrise în cuvinte, apoi
    câte o filă pentru fiecare secțiune — contul de profit și pierdere,
    categoriile, canalele, cursele de charter, pârghiile și cheltuielile.

    Stilurile stau în pagină, fiindcă dompdf nu iese pe rețea după fișiere.
--}}
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>{{ $deck['title'] }}</title>
    <style>
        @page { margin: 34px 28px 46px 28px; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7.5px;
            color: {{ $brand['ink'] }};
            margin: 0;
        }

        .head { width: 100%; margin-bottom: 10px; }
        .head td { vertical-align: middle; padding: 0; }
        .head .logo { width: 46px; }
        .head .logo img { width: 38px; }
        .head h1 { font-size: 14px; margin: 0; }
        .head .sub { color: {{ $brand['subtle'] }}; font-size: 8px; margin-top: 2px; }
        .head .company { text-align: right; font-size: 8px; color: {{ $brand['subtle'] }}; }
        .rule { height: 2px; background: {{ $brand['accent'] }}; margin-bottom: 8px; }

        h2 { font-size: 10px; margin: 0 0 5px; }
        .note { color: {{ $brand['subtle'] }}; font-size: 7px; margin: 0 0 6px; }

        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th {
            background: {{ $brand['ink'] }};
            color: #ffffff;
            font-size: 7px;
            font-weight: bold;
            text-align: right;
            padding: 4px 4px;
        }
        table.grid th.label { text-align: left; }
        table.grid td {
            padding: 2.5px 4px;
            border-bottom: 0.5px solid {{ $brand['border'] }};
            text-align: right;
            white-space: nowrap;
        }
        table.grid td.label { text-align: left; }
        table.grid td.indent { padding-left: 14px; }
        tr.group td { background: {{ $brand['muted'] }}; font-weight: bold; }
        tr.section td, tr.total td {
            font-weight: bold;
            border-top: 1px solid {{ $brand['accent'] }};
            border-bottom: none;
        }
        td.negative { color: {{ $brand['negative'] }}; }

        .page-break { page-break-before: always; }

        ol.assumptions { margin: 0; padding-left: 14px; }
        ol.assumptions li { margin-bottom: 5px; font-size: 8.5px; line-height: 1.45; }

        .summary { width: 100%; border-collapse: collapse; margin: 10px 0 14px; }
        .summary td { padding: 6px 8px; border: 0.5px solid {{ $brand['border'] }}; }
        .summary .key { color: {{ $brand['subtle'] }}; font-size: 7px; text-transform: uppercase; }
        .summary .value { font-size: 12px; font-weight: bold; }

        .foot {
            position: fixed;
            bottom: -30px; left: 0; right: 0;
            color: {{ $brand['subtle'] }};
            font-size: 7px;
        }
        .foot .right { float: right; }
    </style>
</head>
<body>
<div class="foot">
    {{ $company }} · {{ $deck['title'] }}
    <span class="right">Tipărit {{ $printedAt }}</span>
</div>

@php
    $cell = function ($value) {
        if ($value === null || $value === '') {
            return ['text' => '', 'negative' => false];
        }

        if (is_string($value)) {
            return ['text' => $value, 'negative' => str_starts_with($value, '-')];
        }

        return ['text' => number_format((float) $value, 0, ',', '.'), 'negative' => (float) $value < 0];
    };
@endphp

<table class="head">
    <tr>
        @if ($logo)
            <td class="logo"><img src="{{ $logo }}" alt=""></td>
        @endif
        <td>
            <h1>{{ $deck['title'] }}</h1>
            <div class="sub">{{ $deck['subtitle'] }}</div>
        </td>
        <td class="company">{{ $company }}</td>
    </tr>
</table>
<div class="rule"></div>

<table class="summary">
    <tr>
        @foreach ($summary as $item)
            <td>
                <div class="key">{{ $item['label'] }}</div>
                <div class="value">{{ $item['value'] }}</div>
                <div class="key">{{ $item['hint'] }}</div>
            </td>
        @endforeach
    </tr>
</table>

<h2>Pe ce se sprijină cifrele</h2>
<ol class="assumptions">
    @foreach ($deck['assumptions'] as $line)
        <li>{{ $line }}</li>
    @endforeach
</ol>

@foreach ($deck['sections'] as $section)
    <div class="page-break">
        <table class="head">
            <tr>
                @if ($logo)
                    <td class="logo"><img src="{{ $logo }}" alt=""></td>
                @endif
                <td>
                    <h1>{{ $section['title'] }}</h1>
                    <div class="sub">{{ $deck['subtitle'] }}</div>
                </td>
                <td class="company">{{ $company }}</td>
            </tr>
        </table>
        <div class="rule"></div>

        @if (($section['note'] ?? '') !== '')
            <p class="note">{{ $section['note'] }}</p>
        @endif

        <table class="grid">
            <thead>
            <tr>
                @foreach ($section['columns'] as $index => $column)
                    <th @class(['label' => $index === 0])>{{ $column['label'] }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($section['rows'] as $row)
                <tr class="{{ $row['style'] ?? 'normal' }}">
                    @foreach ($section['columns'] as $index => $column)
                        @php $drawn = $cell($row['cells'][$index] ?? null); @endphp
                        <td @class([
                            'label' => $index === 0,
                            'indent' => $index === 0 && ($row['indent'] ?? false),
                            'negative' => $drawn['negative'],
                        ])>{{ $drawn['text'] }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endforeach
</body>
</html>
