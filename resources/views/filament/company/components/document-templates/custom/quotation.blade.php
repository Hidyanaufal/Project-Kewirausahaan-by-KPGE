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
    <div class="flex justify-between text-xs mb-6">
        <div>
            <p><strong>From: </strong></p>
            <p class="font-bold">{{ $document->company?->name }}</p>
            <p>{{ $document->company?->addressLine1}}</p>
            <p>{{ $document->company?->addressLine2}}</p>
            <p>{{ $document->company?->city}}</p>
        </div>
        <div class="space-y-1 text-xs">
            <div class="flex gap-2">
                <div class="w-10">No. </div>
                <div>: {{ $document->number ?? '-' }}</div>
            </div>
            <div class="flex gap-2">
                <div class="w-10">Date </div>
                <div>: {{ \Carbon\Carbon::parse($document->date)->format('d F Y') }}</div>
            </div>
        </div>
    </div>

    <div class="text-xs mb-6">
        <p><strong>To: </strong></p>
        <p class="font-bold">{{ $document->client?->name }}</p>
        @if ($document->client && ($formattedAddress = $document->client->getFormattedAddressHtml()))
            {!! $formattedAddress !!}
        @endif
        <p class="mt-6"><strong>UP:</strong></p>
        <p class="font-bold">{{ $document?->detail?->project?->pic_client_name ?? '-' }}</p>
    </div>

    <div class="text-xs">
        Dengan ini kami sampaikan penawaran spesifikasi barang/pekerjaan jasa dan harga yang bapak/ibu butuhkan :
    </div>

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
                        <td class="py-1 pl-2 text-left border border-black whitespace-nowrap">
                            {{ $item->name }}
                        </td>
                        <td class="py-1 text-center border border-black whitespace-nowrap">
                            {{ $item->quantity }}
                        </td>
                        <td class="py-1 text-center border border-black whitespace-nowrap">
                            {{ $item->unit }}
                        </td>
                        <td class="py-1 pr-2 text-right border border-black whitespace-nowrap">
                             {{ $item->unitPrice }}
                        </td>
                        <td class="py-1 pr-2 text-right border border-black whitespace-nowrap">
                            {{ $item->subtotal }}
                        </td>
                    </tr>
                @endforeach

                <!-- Pajak -->
                @if ($document->tax)
                    <tr>
                        <td colspan="5" class="px-2 text-right">PPN 11%</td>
                        <td class="px-2 text-right">
                            {{ $document->tax }}
                        </td>
                    </tr>
                @endif
                
                <!-- Jumlah -->
                @if ($document->subtotal)
                    <tr>
                        <td colspan="5" class="px-2 text-right border-t border-black">Jumlah</td>
                        <td class="px-2 text-right border-t border-black">
                            {{ $document->subtotal }}
                        </td>
                    </tr>
                @endif

                <!-- Profit -->
                @if ($document->profit)
                    <tr>
                        <td colspan="5" class="px-2 text-right">Profit {{ $document->profitPercentage }}%</td>
                        <td class="px-2 text-right">
                            {{ $document->profit }}
                        </td>
                    </tr>
                @endif

                <!-- Total -->
                <tr>
                    <td colspan="5" class="px-2 font-semibold text-right">Total</td>
                    <td class="px-2 font-semibold text-right">
                        {{ $document->total }}
                    </td>
                </tr>
            </tbody>
        </table>
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

        {{-- Signature --}}
        <div class="flex justify-between text-xs" style="margin-top: 50px">
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
</div>

<style>
    .invoice-container {
        font-family: '{{ $document->font->getLabel() }}', sans-serif;
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
