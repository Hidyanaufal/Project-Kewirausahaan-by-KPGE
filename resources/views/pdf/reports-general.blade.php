<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $data['company_name'] }} - {{ $data['report_title'] }}</title>
    <style>
        @font-face {
            font-family: 'Roboto';
            src: url('file:///usr/share/fonts/truetype/roboto/unhinted/RobotoTTF/Roboto-Regular.ttf') format('truetype'),
                 url('file:///usr/share/fonts/truetype/roboto/Roboto-Regular.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
        }
        @font-face {
            font-family: 'Roboto';
            src: url('file:///usr/share/fonts/truetype/roboto/unhinted/RobotoTTF/Roboto-Bold.ttf') format('truetype'),
                 url('file:///usr/share/fonts/truetype/roboto/Roboto-Bold.ttf') format('truetype');
            font-weight: bold;
            font-style: normal;
        }
        @font-face {
            font-family: 'Roboto';
            src: url('file:///usr/share/fonts/truetype/roboto/unhinted/RobotoTTF/Roboto-Italic.ttf') format('truetype'),
                 url('file:///usr/share/fonts/truetype/roboto/Roboto-Italic.ttf') format('truetype');
            font-weight: normal;
            font-style: italic;
        }
        body {
            font-family: 'Roboto', Arial, Helvetica, sans-serif;
            text-align: center;
            font-size: 9px;
        }
        h1, h2, h3, p {
            margin: 0;
            padding: 0;
        }
        h1 {
            color: rgb(179, 2, 2);
            font-size: 20px;
        }
        h3 {
            font-size: 15px;
        }
        table {
            width: 90%;
            border-collapse: collapse;
            margin-top: 30px;
        }
        table.report-table-fixed {
            table-layout: fixed;
            width: 100%;
        }
        table.report-table-fixed th,
        table.report-table-fixed td {
            word-wrap: break-word;
            overflow-wrap: break-word;
            vertical-align: top;
        }
        table th, table td {
            padding: 2px 6px;
            text-align: left;
        }
        table th {
            border-bottom: 1px solid #000;
            font-weight: normal;
        }
    </style>
</head>
<body>
    <center>
        <h3>{{ $data['company_name'] }}</h3>
        <h1>{{ $data['report_title'] }}</h1>
        <h3>{{ $data['report_period'] }}</h3>
    </center>

    @php
        $pdfTable = $data['pdf_table'] ?? null;
        $tableClass = $pdfTable ? 'report-table-fixed' : '';
    @endphp

    <center>
        <table class="{{ $tableClass }}">
            @if (! empty($pdfTable['column_widths']))
                <colgroup>
                    @foreach ($pdfTable['column_widths'] as $width)
                        <col style="width: {{ $width }}">
                    @endforeach
                </colgroup>
            @endif
            @php
                $balanceColumnIndex = $data['balance_column_index'] ?? null;
            @endphp
            <thead>
                <tr style="color: rgb(6, 6, 174)">
                    @foreach ($data['columns'] as $index => $col)
                        <th @if ($balanceColumnIndex !== null && $index === $balanceColumnIndex) style="text-align: right" @endif>{{ $col }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($data['rows'] as $row)
                    @php
                        $rowStyles = $row['style'] ?? [];
                        $rowCellStyles = $row['cell_styles'] ?? [];

                        if (isset($rowStyles['cell_styles']) && is_array($rowStyles['cell_styles'])) {
                            foreach ($rowStyles['cell_styles'] as $cellIndex => $cellStyle) {
                                if (! is_array($cellStyle)) {
                                    continue;
                                }
                                $rowCellStyles[$cellIndex] = array_merge($rowCellStyles[$cellIndex] ?? [], $cellStyle);
                            }
                            unset($rowStyles['cell_styles']);
                        }

                        $descriptionColumnIndex = $data['description_column_index'] ?? 0;
                        $balanceColumnIndex = $data['balance_column_index'] ?? null;
                        $indentDescriptionOnly = isset($rowStyles['padding-left']) && ! isset($rowStyles['font-weight']);
                    @endphp
                    <tr>
                        @foreach ($row['data'] as $index => $cell)
                            @php
                                $isMarkedString = Str::startsWith($cell, '__str');
                                if ($isMarkedString) {
                                    $cell = Str::replaceFirst('__str', '', $cell);
                                }

                                if ($indentDescriptionOnly) {
                                    $effectiveStyles = $index === $descriptionColumnIndex
                                        ? ['padding-left' => $rowStyles['padding-left']]
                                        : [];
                                } else {
                                    $effectiveStyles = $rowStyles;
                                }

                                $effectiveStyles = array_merge(
                                    $effectiveStyles,
                                    $rowCellStyles[$index] ?? []
                                );

                                if ($balanceColumnIndex !== null && $index === $balanceColumnIndex) {
                                    $effectiveStyles['text-align'] = 'right';
                                }

                                $style = collect($effectiveStyles)
                                    ->filter(fn ($v) => is_scalar($v))
                                    ->map(fn ($v, $k) => "{$k}: {$v}")
                                    ->join('; ');
                            @endphp
                            <td>
                                <p style="{{ $style }}">
                                    @if ($cell === null || $cell === '')
                                        &nbsp;
                                    @elseif (! $isMarkedString && is_numeric($cell))
                                        {{ number_format($cell, $data['amount_decimal_places'] ?? 2, ',', '.') }}
                                    @else
                                        {{ $cell }}
                                    @endif
                                </p>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </center>
</body>
</html>