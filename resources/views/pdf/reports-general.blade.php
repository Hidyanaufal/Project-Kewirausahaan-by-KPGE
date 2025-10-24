<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $data['company_name'] }} - {{ $data['report_title'] }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
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
            padding: 0px;
            font-size: 10px;
        }
        table th{
            padding: 0px;
            border-bottom: 2px solid #000;
        }
        .category-header {
            font-weight: bold;
            font-size: 11px;
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
                    <th style="text-align: left">Description</th>
                    <th style="text-align: right">{{ $data['report_period_date'] }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['categories'] as $category)
                    @if (isset($category['items']))
                        <tr class="category-header">
                            <td>{{ $category['name'] }}</td>
                        </tr>
                        @foreach ($category['items'] as $item)
                            <tr>
                                <td>
                                    <p style="padding-left: 20px">
                                        {{ $item['name'] }}
                                    </p>
                                </td>
                                <td style="text-align: right;">{{ number_format($item['value'], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    @else
                        <tr class="category-header">
                            <td>{{ $category['name'] }}</td>
                            <td style="text-align: right;">{{ number_format($category['value'], 2, ',', '.') }}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </center>
</body>
</html>
