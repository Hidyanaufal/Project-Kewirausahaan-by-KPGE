@php
    function rupiahToNumber(?string $str, bool $asFloat = false) {
        // 1) buang semua kecuali digit, minus, koma dan titik
        $s = trim($str);
        $s = preg_replace('/[^\d\-\.,]/u', '', $s);

        // 2) catat tanda minus (jika ada)
        $isNegative = (strpos($s, '-') !== false);
        $s = str_replace('-', '', $s);

        // 3) hilangkan separator ribuan (titik)
        $s = str_replace('.', '', $s);

        // 4) ubah koma desimal ke titik (standar PHP)
        $s = str_replace(',', '.', $s);

        // 5) cast sesuai request
        $value = $asFloat ? (float) $s : (int) round((float) $s);

        return $isNegative ? -$value : $value;
    }

    function numberToRupiah($number, $withSymbol = true) {
    // pastiin number valid
    if (!is_numeric($number)) return null;

    // format: ribuan pakai titik, desimal pakai koma
    $formatted = number_format($number, 0, ',', '.');

    return $withSymbol ? 'Rp' . $formatted : $formatted;
}

    function formatMoneyCeiling(?string $formattedMoney) {
        if (!$formattedMoney) return null;
        
        // Parse: "Rp12.500,25" -> 12500.25
        $numeric = str_replace(['Rp', ' '], '', $formattedMoney);
        $numeric = str_replace('.', '', $numeric); // Remove thousands separator
        $numeric = str_replace(',', '.', $numeric); // Convert decimal comma to dot
        $numeric = (float) $numeric;
        
        // Apply ceiling (round up)
        $rounded = ceil($numeric);
        
        // Format back: Rp12.501 (no decimals)
        return 'Rp' . number_format($rounded, 0, ',', '.');
    }

@endphp

<div class="p-6 text-gray-800 bg-white rounded-md shadow-sm invoice-container">
    {{-- Header --}}
    <div class="mb-4">
        <div class="flex items-center">
            <!-- Logo -->
            @if ($document->logo && $document->showLogo)
                <div class="h-16 w-[250px]">
                    <x-company.document-template.logo :src="$document->logo" />
                </div>
            @endif
        </div>
    </div>

    {{-- Title --}}
    <div class="my-4 text-center">
        <h2 class="text-lg font-bold">{{ $document->header }}</h2>
        @if ($document->subheader)
            <p class="text-xs text-gray-600">{{ $document->subheader }}</p>
        @endif
        <div class="mt-1 text-xs">No. {{ $document->number }}</div>
    </div>

    {{-- Metadata --}}
    <div class="flex justify-between items-start text-xs mb-6 mt-20">
        <div class="flex mb-2 gap-5 w-[60%]">
            <div class="text-xs min-w-[60px] whitespace-nowrap">Kepada :</div>
            <div class="text-xs max-w-[50ch] break-words">
                @if ($document->recipientDetails)
                    <p class="font-semibold">{!! nl2br(e($document->recipientDetails)) !!},</p>
                @endif
                <p class="font-semibold">{{ $document->client?->name ?? 'Client Not Found' }}</p>
                @if ($document->client && ($formattedAddress = $document->client->getFormattedAddressHtml()))
                    {!! $formattedAddress !!}
                @endif
            </div>
        </div>
        <div class="text-xs w-[40%]">
            <table class="w-full">
                <tbody>
                    @if ($document?->detail?->contract_number)
                        <tr>
                            <td class="text-right whitespace-nowrap pr-2 w-32">Nomor Kontrak</td>
                            <td class="text-left w-2">:</td>
                            <td class="text-left">{{ $document->detail->contract_number }}</td>
                        </tr>
                    @endif

                    @if ($document?->detail?->po_reference_number)
                        <tr>
                            <td class="text-right whitespace-nowrap pr-2">Ref. Nomor PO</td>
                            <td class="text-left w-2">:</td>
                            <td class="text-left">{{ $document->detail->po_reference_number }}</td>
                        </tr>
                    @endif

                    @if ($document?->detail?->po_date)
                        <tr>
                            <td class="text-right whitespace-nowrap pr-2">Tanggal PO</td>
                            <td class="text-left w-2">:</td>
                            <td class="text-left whitespace-nowrap">{{ \Carbon\Carbon::parse($document->detail->po_date)->format('d F Y') }}</td>
                        </tr>
                    @endif

                    @if ($document?->detail?->spk_number)
                        <tr>
                            <td class="text-right whitespace-nowrap pr-2">SPK No.</td>
                            <td class="text-left w-2">:</td>
                            <td class="text-left">{{ $document->detail->spk_number }}</td>
                        </tr>
                    @endif

                    @if ($document?->detail?->spk_date)
                        <tr>
                            <td class="text-right whitespace-nowrap pr-2">Tanggal SPK</td>
                            <td class="text-left w-2">:</td>
                            <td class="text-left whitespace-nowrap">{{ \Carbon\Carbon::parse($document->detail->spk_date)->format('d F Y') }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex flex-col">
    {{-- Line Items --}}
    <div class="mb-2 line-items">
        <table class="w-full text-xs leading-tight border-1 border-black border-collapse">
            {{-- <thead> --}}
                <tr>
                    <th style="background: {{ $document->accentColor }}" class="w-1/6 px-2 py-1 text-center">Tanggal</th>
                    <th style="background: {{ $document->accentColor }}" class="w-4/6 px-2 py-1 text-left">Keterangan</th>
                    <th style="background: {{ $document->accentColor }}" class="w-1/6 px-2 py-1 text-center">Jumlah (Rp.)</th>
                </tr>
            {{-- </thead> --}}
            <tbody>
                @if ($document?->detail?->hide_offering_items)
                    {{-- Mode: Hide Offering Items - Tampilkan langsung total sejajar dengan project --}}
                    <tr>
                        <td class="px-2 py-1 text-center border border-black align-top">
                            {{ \Carbon\Carbon::parse($document->dueDate)->format('d-M-y') }}
                        </td>
                        <td class="py-1 pl-2 font-semibold text-left border border-black">
                            @isset($document->project)
                                <div>
                                    {{ $document->project }}
                                    @if ($document?->detail?->project?->description)
                                        <div class="mt-0.5 text-[10px] text-gray-600 leading-snug">
                                            {{ $document?->detail?->project?->description }}
                                        </div>
                                    @endif
                                </div>
                            @endisset
                        </td>
                        <td class="py-1 pr-2 text-right border border-black whitespace-nowrap">
                            {{ formatMoneyCeiling($document->subtotal) }}
                        </td>
                    </tr>
                @else
                    {{-- Mode: Show Offering Items - Tampilkan semua offering items --}}
                    @foreach ($document->lineItems as $index => $item)
                        <tr>
                            @if ($index === 0)
                                <td class="px-2 py-1 text-center border border-black align-top"
                                    rowspan="{{ count($document->lineItems) + 1 }}">
                                    {{ \Carbon\Carbon::parse($document->dueDate)->format('d-M-y') }}
                                </td>
                                <td class="py-1 pl-2 font-semibold text-left border border-black" colspan="2">
                                    @isset($document->project)
                                        <div>
                                            {{ $document->project }}
                                            @if ($document?->detail?->project?->description)
                                                <div class="mt-0.5 text-[10px] text-gray-600 leading-snug">
                                                    {{ $document?->detail?->project?->description }}
                                                </div>
                                            @endif
                                        </div>
                                    @endisset
                                </td>
                        </tr>
                    @endif
                    <td class="py-1 pl-2 text-left border border-black">
                        {{ $item->name }}
                        @if ($item->description)
                            <div class="text-[10px] text-gray-600 leading-snug break-words ">
                                {{ $item->description }}
                            </div>
                        @endif
                    </td>
                    <td class="py-1 pr-2 text-right border border-black whitespace-nowrap">
                        {{ formatMoneyCeiling($item->subtotal) }}
                    </td>
                    @endforeach
                @endif

                <!-- Profit -->
                <tr>
                    <td colspan="2" class="px-2 text-right border-t border-black">Profit {{ $document->profitPercentage }}%</td>
                    <td class="px-2 text-right border-t border-black">
                        {{ formatMoneyCeiling($document->profit) }}
                    </td>
                </tr>

                <!-- Jumlah -->
                <tr>
                    <td colspan="2" class="px-2 text-right border-t border-black">Jumlah</td>
                    <td class="px-2 text-right border-t border-black">
                        {{ formatMoneyCeiling(numberToRupiah(rupiahToNumber($document->subtotal) + rupiahToNumber($document->profit))) }}
                    </td>
                </tr>

                <!-- PPN -->
                <tr>
                    <td colspan="2" class="px-2 text-right">PPN 11%</td>
                    <td class="px-2 text-right">
                        {{ formatMoneyCeiling($document->tax) }}
                    </td>
                </tr>

                <!-- Total -->
                <tr>
                    <td colspan="2" class="px-2 font-semibold text-right">Total</td>
                    <td class="px-2 font-semibold text-right">
                        {{ formatMoneyCeiling($document->total) }}
                    </td>
                </tr>

                <!-- Terbilang -->
                <tr>
                    <td colspan="3" class="px-2 py-1 text-xs border border-black">
                        <strong>Terbilang:</strong>
                        <em>{{ $document->terbilang }}</em>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    </div>

    {{-- Footer Notes --}}
    <div class="mt-1 text-xs">
        @if ($document->terms)
            <div class="leading-relaxed">Terms and Condition:</div>
            <div class="prose prose-sm max-w-none text-xs">
                {!! $document->terms !!}
            </div>
        @endif

        @if ($document->footer)
            <div class="mb-4 text-xs italic">
                {{ $document->footer }}
            </div>
        @endif
    </div>

        {{-- Section Bank Info & Signature sejajar kanan kiri --}}
        <div class="flex justify-between mt-6 text-xs">
            {{-- Bank Info --}}
            <div class="w-1/2">
                <div class="leading-relaxed">Pembayaran harap dilakukan melalui:</div>
                <div class="font-semibold mt-1">
                    {{ $document->bankName }}<br>
                    A/n. {{ $document->accountName }}<br>
                    ACCOUNT NO. {{ $document->accountNumber }}
                </div>
            </div>

            {{-- Signature --}}
            <div class="w-2/2 text-left">
                {{ $document->accountName }}<br>
                Jakarta, {{ \Carbon\Carbon::parse($document->date)->format('d F Y') }}<br><br><br>
                <div class="mt-20 font-semibold">{{ $document->beneficiaryName }}</div>
            </div>
        </div>

</div>

<style>
    .invoice-container {
        font-family: '{{ $document->font->getLabel() }}', sans-serif;
        width: 210mm;
        min-height: 297mm;
        background: white;
        margin: 0 auto;
        padding: 1cm 1cm 3cm 1cm;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        display: flex;
        flex-direction: column;
    }
    @media print {
        @page {
            size: A4;
            margin: 0;
        }
        body {
            margin: 0;
            background: white;
            -webkit-print-color-adjust: exact;
        }
        .invoice-container {
            width: 100%;
            height: 100vh;
            min-height: 100vh;
            margin: 0;
            padding: 1cm 1cm 3cm 1cm !important;
            box-shadow: none;
            border: none;
        }
    }

    .line-items table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .line-items th,
    .line-items td {
        padding: 8px;
        border: 1px solid #000;
    }

    .line-items th {
        text-align: center;
        background-color: #f2f2f2;
    }

    .footer {
        margin-top: 20px;
    }

    /* Custom CSS for RichEditor HTML content */
    .prose ul {
        list-style-type: disc;
        margin-left: 20px;
        margin-top: 8px;
        margin-bottom: 8px;
    }

    .prose ol {
        list-style-type: decimal;
        margin-left: 20px;
        margin-top: 8px;
        margin-bottom: 8px;
    }

    .prose li {
        margin-bottom: 4px;
        line-height: 1.5;
    }

    .prose p {
        margin-top: 8px;
        margin-bottom: 8px;
    }

    .prose strong {
        font-weight: bold;
    }

    .prose em {
        font-style: italic;
    }

    .prose h1, .prose h2, .prose h3, .prose h4, .prose h5, .prose h6 {
        font-weight: bold;
        margin-top: 12px;
        margin-bottom: 8px;
    }

    .prose h1 {
        font-size: 1.5em;
    }

    .prose h2 {
        font-size: 1.3em;
    }

    .prose h3 {
        font-size: 1.1em;
    }
</style>
