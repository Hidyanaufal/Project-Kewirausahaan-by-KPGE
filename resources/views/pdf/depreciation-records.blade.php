<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Lampiran Khusus - Daftar Penyusutan dan Amortisasi Fiskal</title>
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
            font-size: 9px;
            text-align: center;
            margin: 25px;
        }

        h3, h4, p {
            margin: 0;
            line-height: 1.4;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }

        th, td {
            border: 1px solid #000;
            padding: 3px 4px;
            vertical-align: middle;
        }

        th {
            background-color: #f3f3f3;
            font-weight: bold;
            text-align: center;
        }

        .no-border td {
            border: none;
            padding: 2px 4px;
        }

        .info-table {
            width: 100%;
            margin-top: 8px;
            border-collapse: collapse;
            font-size: 9px;
        }

        .info-table td {
            padding: 3px 6px;
            vertical-align: middle;
        }

        .info-table .label {
            width: 18%;
            text-align: left;
            font-weight: bold;
        }

        .info-table .value {
            width: 32%;
            text-align: left;
            border-bottom: 1px solid #ccc;
        }

        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .bold { font-weight: bold; }

        .section-title {
            font-weight: bold;
            text-decoration: underline;
            text-align: left;
            padding-top: 5px;
        }

        .spacer td {
            border: none;
            height: 6px;
        }

        .signature {
            margin-top: 25px;
            text-align: right;
            padding-right: 40px;
        }
    </style>
</head>
<body>
    <h3>LAMPIRAN KHUSUS</h3>
    <h4>SPT TAHUNAN PAJAK PENGHASILAN WAJIB PAJAK BADAN</h4>
    <p>DAFTAR PENYUSUTAN DAN AMORTISASI FISKAL</p>

    <br>

    <table class="no-border info-table">
        <tr>
            <td class="label">NPWP</td>
            <td class="value">{{ $company->profile->tax_id ?? '-' }}</td>
            <td class="label">Tahun Pajak</td>
            <td class="value text-left">{{ $report['fiscal_year'] ?? now()->year }}</td>
        </tr>
        <tr>
            <td class="label">Nama Wajib Pajak</td>
            <td colspan="3" class="value">{{ $company->name ?? '-' }}</td>
        </tr>
    </table>

    <br>

    <table>
        <thead>
            <tr>
                <th rowspan="2" width="3%">No</th>
                <th rowspan="2" width="20%">Kelompok / Jenis Harta</th>
                <th rowspan="2" width="10%">Bulan / Tahun Perolehan</th>
                <th rowspan="2" width="10%">Harga Perolehan<br>(Rp)</th>
                <th rowspan="2" width="12%">Nilai Sisa Buku Fiskal<br>Awal Tahun (Rp)</th>
                <th colspan="2" width="14%">Metode Penyusutan / Amortisasi</th>
                <th rowspan="2" width="12%">Penyusutan / Amortisasi<br>Fiskal Tahun Ini (Rp)</th>
                <th rowspan="2" width="12%">Catatan<br>(Nilai Sisa Buku)</th>
            </tr>
            <tr>
                <th width="7%">Komersial</th>
                <th width="7%">Fiskal</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($records->groupBy(fn($r) => $r->asset->group->name ?? 'Lain-lain') as $group => $groupRecords)
                <tr>
                    <td colspan="9" class="section-title">{{ strtoupper($group) }}</td>
                </tr>

                @foreach ($groupRecords as $i => $record)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td class="text-left">{{ $record->asset->name ?? '-' }}</td>
                        <td>{{ $record->asset->acquisition_date?->format('d M Y') ?? '-' }}</td>
                        <td class="text-right">{{ number_format($record->asset->acquisition_cost / 100, 0, ',', '.') }}</td>
                        <td class="text-right">{{ number_format($record->opening_fiscal_book_value / 100, 0, ',', '.') }}</td>
                        <td>{{ $record->commercial_method->getLabel()}}</td>
                        <td>{{ $record->fiscal_method->getLabel() }}</td>
                        <td class="text-right">{{ number_format($record->depreciation_expense / 100, 0, ',', '.') }}</td>
                        <td class="text-right">{{ number_format($record->closing_fiscal_book_value / 100, 0, ',', '.') }}</td>
                    </tr>
                @endforeach

                <tr>
                    <td colspan="7" class="text-right bold">Subtotal {{ $group }}</td>
                    <td colspan="2" class="text-right bold">
                        {{ number_format($groupRecords->sum('depreciation_expense'), 0, ',', '.') }}
                    </td>
                </tr>

                <tr class="spacer"><td colspan="9"></td></tr>
            @endforeach

            <tr class="bold">
                <td colspan="7" class="text-right">JUMLAH PENYUSUTAN FISKAL</td>
                <td colspan="2" class="text-right">
                    {{ number_format($records->sum('depreciation_expense'), 0, ',', '.') }}
                </td>
            </tr>
        </tbody>
    </table>

    <div class="signature">
        {{ now()->format('d M Y') }}<br><br><br>
        <span class="bold">WAJIB PAJAK / KUASA</span>
    </div>

</body>
</html>
