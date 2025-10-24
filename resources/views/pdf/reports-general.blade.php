<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $data['company_name'] }} - {{ $data['report_title'] }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px 100px;
            text-align: center;
        }
        h1, h2, h3, p {
            margin: 0;
            padding: 0;
        }
        h1 {
            color: rgb(179, 2, 2);
        }
        table {
            width: 75%;
            border-collapse: collapse;
            margin-top: 50px;
        }
        table th, table td {
            padding: 0px;
        }
        table th{
            padding: 0px;
            border-bottom: 2px solid #000;
        }
        .category-header {
            font-weight: bold;
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
