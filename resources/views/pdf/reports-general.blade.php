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
        h1, h2, h3 {
            text-align: center;
            margin: 0;
            padding: 0;
        }
        h1 {
            color: rgb(179, 2, 2); /* Red color for title */
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        table th, table td {
            padding: 0px;
        }
        table th {
            text-align: center;
        }
        table td {
            text-align: right;
        }
        .category-header {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h3>{{ $data['company_name'] }}</h3>
    <h1>{{ $data['report_title'] }}</h1>
    <h3>{{ $data['report_period'] }}</h3>

    <table>
        <thead>
            <tr style="color: rgb(6, 6, 174)">
                <th>Description</th>
                <th>{{ $data['report_period'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['categories'] as $category)
                <tr class="category-header">
                    <td>{{ $category['name'] }}</td>
                </tr>
                @foreach ($category['items'] as $item)
                    <tr>
                        <td>{{ $item['name'] }}</td>
                        <td>{{ number_format($item['value'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</body>
</html>
