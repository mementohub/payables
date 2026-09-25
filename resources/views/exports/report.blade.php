{{--
    Raportul pe hârtie: antet cu logo, tabelul tăiat pe file de coloane și un
    subsol cu data tipăririi. Stilurile stau aici, în pagină, fiindcă dompdf nu
    iese pe rețea după fișiere.
--}}
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>{{ $document->title }}</title>
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
        tr.group td { background: {{ $brand['muted'] }}; font-weight: bold; }
        tr.section td, tr.total td {
            font-weight: bold;
            border-top: 1px solid {{ $brand['accent'] }};
            border-bottom: none;
        }
        td.negative { color: {{ $brand['negative'] }}; }

        .page-break { page-break-before: always; }
        .slice { color: {{ $brand['subtle'] }}; font-size: 7px; margin: 0 0 4px; }
        .notes { margin-top: 10px; color: {{ $brand['subtle'] }}; font-size: 7px; }
        .notes p { margin: 0 0 2px; }

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
    {{ $company }} · {{ $document->title }}
    <span class="right">Tipărit {{ $printedAt }}</span>
</div>

@foreach ($chunks as $chunk)
    <div @class(['page-break' => ! $loop->first])>
        <table class="head">
            <tr>
                @if ($logo)
                    <td class="logo"><img src="{{ $logo }}" alt=""></td>
                @endif
                <td>
                    <h1>{{ $document->title }}</h1>
                    <div class="sub">{{ $document->subtitle }}</div>
                </td>
                <td class="company">{{ $company }}</td>
            </tr>
        </table>
        <div class="rule"></div>

        @if (count($chunks) > 1)
            <p class="slice">Coloanele {{ $chunk['from'] }} din {{ $chunk['to'] }}</p>
        @endif

        <table class="grid">
            <thead>
            <tr>
                @foreach ($chunk['columns'] as $index => $column)
                    <th @class(['label' => $index === 0])>{{ $column['label'] }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($document->rows as $row)
                <tr class="{{ $row['style'] ?? 'normal' }}">
                    @foreach ($chunk['indexes'] as $position => $index)
                        @php
                            $cell = $row['cells'][$index] ?? null;
                            $value = is_array($cell) ? ($cell['value'] ?? null) : $cell;
                            $type = is_array($cell) ? ($cell['type'] ?? 'text') : 'text';
                        @endphp
                        <td @class([
                            'label' => $position === 0,
                            'negative' => $type !== 'text' && is_numeric($value) && $value < 0,
                        ])>
                            @if ($value === null || $value === '')
                            @elseif ($type === 'percent')
                                {{ number_format((float) $value * 100, 1, ',', '.') }}%
                            @elseif ($type !== 'text')
                                {{ number_format((float) $value, 0, ',', '.') }}
                            @else
                                {{ $value }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>

        @if ($loop->last && $document->notes !== [])
            <div class="notes">
                @foreach ($document->notes as $note)
                    <p>{{ $note }}</p>
                @endforeach
            </div>
        @endif
    </div>
@endforeach
</body>
</html>
