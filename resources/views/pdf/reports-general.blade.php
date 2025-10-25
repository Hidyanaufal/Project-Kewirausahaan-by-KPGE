<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $data['company_name'] }} - {{ $data['report_title'] }}</title>
    <style>
          @font-face {
            font-family: 'Roboto';
            src: url('file:///usr/share/fonts/truetype/roboto/unhinted/RobotoTTF/Roboto-Regular.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
        }
        @font-face {
            font-family: 'Roboto';
            src: url('file:///usr/share/fonts/truetype/roboto/unhinted/RobotoTTF/Roboto-Bold.ttf') format('truetype');
            font-weight: bold;
            font-style: normal;
        }
        @font-face {
            font-family: 'Roboto';
            src: url('file:///usr/share/fonts/truetype/roboto/unhinted/RobotoTTF/Roboto-Italic.ttf') format('truetype');
            font-weight: normal;
            font-style: italic;
        }
        body {
            font-family: 'Roboto', sans-serif;
            text-align: center;
            font-size: 9px;
        }
        h1, h2, h3, p {
            margin: 0;
            padding: 0;
        }
        h1 {
            color: rgb(179, 2, 2);
            font-size: 20px
        }
        h3 {
            font-size: 15px;
        }
        table {
            width: 90%;
            border-collapse: collapse;
            margin-top: 30px;
        }
        table th, table td {
            padding: 0;
        }
        table th {
            border-bottom: 1px solid #000;
            text-align: center;
        }
    </style>
</head>
<body>
    <center>
        <h3>{{ $data['company_name'] }}</h3>
        <h1>{{ $data['report_title'] }}</h1>
        <h3>{{ $data['report_period'] }}</h3>
    </center>

    <center>
        <table>
            <thead>
                <tr style="color: rgb(6, 6, 174)">
                    @foreach ($data['columns'] as $col)
                        <th>{{ $col }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($data['rows'] as $row)
                    @php
                        $style = isset($row['style'])
                            ? collect($row['style'])->map(fn($v, $k) => "$k: $v")->join('; ')
                            : '';
                    @endphp
                    <tr>
                        @foreach ($row['data'] as $cell)
                            @php
                                $isMarkedString = Str::startsWith($cell, '__str');
                                if ($isMarkedString) {
                                    $cell = Str::replaceFirst('__str', '', $cell);
                                }
                            @endphp
                            <td style="text-align: {{ !$isMarkedString && is_numeric($cell) ? 'right' : 'left' }}">
                                <p style="{{ $style }}">
                                    {{ (!$isMarkedString && is_numeric($cell))
                                        ? number_format($cell, 2, ',', '.')
                                        : $cell }}
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
