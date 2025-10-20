<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Lampiran Khusus - Daftar Penyusutan dan Amortisasi Fiskal</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            margin: 30px;
        }

        h3, h4, p {
            text-align: center;
            margin: 0;
            line-height: 1.4;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid #000;
            padding: 4px 5px;
            vertical-align: middle;
        }

        th {
            background-color: #f2f2f2;
            font-weight: bold;
            text-align: center;
        }

        .no-border, .no-border td {
            border: none;
            padding: 2px;
        }

        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .bold { font-weight: bold; }
        .section-title {
            font-weight: bold;
            text-decoration: underline;
            padding-top: 6px;
        }

        .spacer {
            height: 6px;
            border: none;
        }
    </style>
</head>
<body>

    <h3>LAMPIRAN KHUSUS</h3>
    <h4>SPT TAHUNAN PAJAK PENGHASILAN WAJIB PAJAK BADAN</h4>
    <p>DAFTAR PENYUSUTAN DAN AMORTISASI FISKAL</p>

    <br>

    <table class="no-border">
        <tr>
            <td width="15%" class="text-left">NPWP :</td>
            <td width="40%">{{ $company->profile->tax_id ?? '-' }}</td>
            <td width="20%" class="text-left">Tahun Pajak :</td>
            <td>{{ $report['fiscal_year'] ?? now()->year }}</td>
        </tr>
        <tr>
            <td class="text-left">Nama Wajib Pajak :</td>
            <td colspan="3">{{ $company->name ?? '-' }}</td>
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
                <th rowspan="2" width="12%">Penyusutan / Amortisasi Fiskal Tahun Ini (Rp)</th>
                <th rowspan="2" width="12%">Catatan<br>(Nilai Sisa Buku)</th>
            </tr>
            <tr>
                <th width="7%">Komersial</th>
                <th width="7%">Fiskal</th>
            </tr>
        </thead>

        <tbody>
            {{-- Contoh grouping berdasarkan kelompok --}}
            @foreach ($records->groupBy(fn($r) => $r->asset->category->name ?? 'Lain-lain') as $group => $groupRecords)
                <tr>
                    <td colspan="9" class="text-left bold section-title">
                        {{ strtoupper($group) }}
                    </td>
                </tr>

                @foreach ($groupRecords as $i => $record)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td class="text-left">{{ $record->asset->name ?? '-' }}</td>
                        <td>{{ $record->asset->acquired_at?->format('d M Y') ?? '-' }}</td>
                        <td class="text-right">{{ number_format($record->asset->purchase_price / 100, 0, ',', '.') }}</td>
                        <td class="text-right">{{ number_format($record->opening_fiscal_book_value / 100, 0, ',', '.') }}</td>
                        <td>{{ $record->commercial_method ?? 'Garis lurus' }}</td>
                        <td>{{ $record->fiscal_method ?? 'Garis lurus' }}</td>
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

    <br><br>

    <table class="no-border" style="margin-top: 30px;">
        <tr>
            <td class="text-right" style="padding-right: 30px;">
                {{ now()->format('d M Y') }}<br><br><br>
                <span class="bold">WAJIB PAJAK / KUASA</span>
            </td>
        </tr>
    </table>

</body>
</html>
