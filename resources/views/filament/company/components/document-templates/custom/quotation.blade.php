@php
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
        <h2 class="text-lg font-bold uppercase">{{ $document->header }}</h2>
        @if ($document->subheader)
            <p class="italic text-sm">{{ $document->subheader }}</p>
        @endif
    </div>

    {{-- Metadata --}}
    <div class="flex justify-between text-xs mb-6 mt-20">
        <div>
            <p><strong>From: </strong></p>
            <p class="font-bold">{{ $document->company?->name }}</p>
            <p>{{ $document->company?->addressLine1}}</p>
            <p>{{ $document->company?->addressLine2}}</p>
            <p>{{ $document->company?->city}}</p>
        </div>
        <div class="text-xs">
            <table class="w-full">
                <tbody>
                    <tr>
                        <td class="text-right whitespace-nowrap pr-2">No.</td>
                        <td class="text-left w-2">:</td>
                        <td class="text-left">{{ $document->number ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="text-right whitespace-nowrap pr-2">Date</td>
                        <td class="text-left w-2">:</td>
                        <td class="text-left whitespace-nowrap">{{ \Carbon\Carbon::parse($document->date)->format('d F Y') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="text-xs mb-6 mt-12 w-[60%] max-w-[50ch] break-words">
        <p><strong>To: </strong></p>
        @if ($document->recipientDetails)
            <p class="font-bold">{!! nl2br(e($document->recipientDetails)) !!},</p>
        @endif
        <p class="font-bold">{{ $document->client?->name }}</p>
        @if ($document->client && ($formattedAddress = $document->client->getFormattedAddressHtml()))
            {!! $formattedAddress !!}
        @endif
        {{-- <p class="mt-6"><strong>UP:</strong></p>
        <p class="font-bold">{{ $document?->detail?->project?->pic_client_name ?? '-' }}</p> --}}
    </div>

    <div class="text-xs">
        Dengan ini kami sampaikan penawaran spesifikasi barang/pekerjaan jasa dan harga yang bapak/ibu butuhkan :
    </div>

    <div class="flex flex-col">
    {{-- Line Items --}}
    <div class="mb-2 line-items">
        <table class="w-full text-xs leading-tight border-1 border-black border-collapse">
            <thead>
                <tr>
                    <th style="background: {{ $document->accentColor }}" class="px-2 py-1 text-left">No.</th>
                    <th style="background: {{ $document->accentColor }}" class="w-2/6 px-2 py-1 text-left">Description
                    </th>
                    <th style="background: {{ $document->accentColor }}" class="px-2 py-1 text-center">Qty</th>
                    <th style="background: {{ $document->accentColor }}" class="w-1/6 px-2 py-1 text-center">Satuan
                    </th>
                    <th style="background: {{ $document->accentColor }}" class="w-1/6 px-2 py-1 text-center">Unit Price
                    </th>
                    <th style="background: {{ $document->accentColor }}" class="w-3/6 px-2 py-1 text-center">Amount
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="6">
                        @isset($document->project)
                            <div class="py-1 pl-2 font-semibold text-left">
                                {{ $document->project }}
                            </div>
                            <div class="py-1 pl-2 text-xs text-left">
                                {{ $document?->detail?->project?->description }}
                            </div>
                        @endisset
                    </td>
                </tr>
                @foreach ($document->lineItems as $index => $item)
                    <tr>
                        <td class="py-1 pr-2 text-right border border-black whitespace-nowrap">
                            {{ $index + 1 }}.
                        </td>
                        <td class="py-1 pl-2 text-left border border-black break-words whitespace-pre-wrap">
                            {{ $item->name }}
                        </td>
                        <td class="py-1 text-center border border-black whitespace-nowrap">
                            {{ $item->quantity }}
                        </td>
                        <td class="py-1 text-center border border-black whitespace-nowrap">
                            {{ $item->unit }}
                        </td>
                        <td class="py-1 pr-2 text-right border border-black whitespace-nowrap">
                             {{ formatMoneyCeiling($item->unitPrice) }}
                        </td>
                        <td class="py-1 pr-2 text-right border border-black whitespace-nowrap">
                            {{ formatMoneyCeiling($item->subtotal) }}
                        </td>
                    </tr>
                @endforeach

                <!-- Pajak -->
                @if ($document->tax)
                    <tr>
                        <td colspan="5" class="px-2 text-right">PPN 11%</td>
                        <td class="px-2 text-right">
                            {{ formatMoneyCeiling($document->tax) }}
                        </td>
                    </tr>
                @endif
                
                <!-- Jumlah -->
                @if ($document->subtotal)
                    <tr>
                        <td colspan="5" class="px-2 text-right border-t border-black">Jumlah</td>
                        <td class="px-2 text-right border-t border-black">
                            {{ formatMoneyCeiling($document->subtotal) }}
                        </td>
                    </tr>
                @endif

                <!-- Profit -->
                @if ($document->profit)
                    <tr>
                        <td colspan="5" class="px-2 text-right">Profit {{ $document->profitPercentage }}%</td>
                        <td class="px-2 text-right">
                            {{ formatMoneyCeiling($document->profit) }}
                        </td>
                    </tr>
                @endif

                <!-- Total -->
                <tr>
                    <td colspan="5" class="px-2 font-semibold text-right">Total</td>
                    <td class="px-2 font-semibold text-right">
                        {{ formatMoneyCeiling($document->total) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    </div>


    {{-- Footer Notes --}}
    <div class="mt-2 text-xs footer">
        @if ($document->terms)
            <div class="leading-relaxed">Terms and Condition:</div>
             <div class="prose prose-sm max-w-none text-xs">
                {!! $document->terms !!}
            </div>
        @endif

        @if ($document->footer)
            <div class="mb-4 text-xs" style="font-style: italic">
                {{ $document->footer }}
            </div>
        @endif



    </div>

        {{-- Signature --}}
        <div class="flex justify-between text-xs mt-auto pt-8">
            {{-- Left side (Pihak Penerima) --}}
            <div class="text-center w-1/2">
                <p>{{ $document->client?->name }}</p>
                <div class="mt-20">
                    <p>(.................................................)</p>
                </div>
            </div>

            {{-- Right side (Pihak Pengirim) --}}
            <div class="text-center w-1/2">
                <p>{{ $document->company->name }}</p>
                <div class="mt-2">
                    <div class="h-16"></div>
                </div>
                <p class="mt-2 font-bold">{{ $document->beneficiaryName }}</p>
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
