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
    <div class="flex justify-between text-xs mb-6">
        <div class="flex mb-2 gap-5">
            <div class="text-xs">Kepada :</div>
            <div class="text-xs">
                <p class="font-semibold">{{ $document->client?->name ?? 'Client Not Found' }}</p>
                @if ($document->client && ($formattedAddress = $document->client->getFormattedAddressHtml()))
                    {!! $formattedAddress !!}
                @endif
            </div>
        </div>
        <div class="space-y-1 text-xs">
            @if ($document?->detail?->contract_number)
                <div class="flex gap-5">
                    <div class="w-32">Nomor Kontrak</div>
                    <div>: {{ $document->detail->contract_number }}</div>
                </div>
            @endif

            @if ($document?->detail?->po_reference_number)
                <div class="flex gap-5">
                    <div class="w-32">Ref. Nomor PO</div>
                    <div>: {{ $document->detail->po_reference_number }}</div>
                </div>
            @endif

            @if ($document?->detail?->po_date)
                <div class="flex gap-5">
                    <div class="w-32">Tanggal PO</div>
                    <div>: {{ \Carbon\Carbon::parse($document->detail->po_date)->format('d F Y') }}</div>
                </div>
            @endif

            @if ($document?->detail?->spk_number)
                <div class="flex gap-5">
                    <div class="w-32">SPK No.</div>
                    <div>: {{ $document->detail->spk_number }}</div>
                </div>
            @endif

            @if ($document?->detail?->spk_date)
                <div class="flex gap-5">
                    <div class="w-32">Tanggal SPK</div>
                    <div>: {{ \Carbon\Carbon::parse($document->detail->spk_date)->format('d F Y') }}</div>
                </div>
            @endif
        </div>
    </div>

    {{-- Line Items --}}
    <div class="mb-2 line-items">
        <table class="w-full text-xs leading-tight border-1 border-black border-collapse">
            <thead>
                <tr>
                    <th style="background: {{ $document->accentColor }}" class="w-1/6 px-2 py-1 text-center">Tanggal</th>
                    <th style="background: {{ $document->accentColor }}" class="w-4/6 px-2 py-1 text-left">Keterangan</th>
                    <th style="background: {{ $document->accentColor }}" class="w-1/6 px-2 py-1 text-center">Jumlah (Rp.)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($document->lineItems as $index => $item)
                    <tr>
                        @if ($index === 0)
                            <td class="px-2 py-1 text-center border border-black align-top"
                                rowspan="{{ count($document->lineItems) + 2 }}">
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
                    <tr>
                @endif
                <td class="py-1 pl-2 text-left border border-black">
                    {{ $item->name }}
                    @if ($item->description)
                        <div class="text-[10px] text-gray-600 leading-snug">
                            {{ $item->description }}
                        </div>
                    @endif
                </td>
                <td class="py-1 pr-2 text-right border border-black whitespace-nowrap">
                    {{ $item->subtotal }}
                </td>
                </tr>
                @endforeach

                <tr>
                    <td class="px-2 py-1" colspan="2">
                        @if ($document->referenceNumber || $document->dueDate)
                            <div class="text-[11px] text-gray-800 leading-snug">
                                @if ($document->referenceNumber)
                                    <strong>Ref PO</strong><br>
                                    No. PO: {{ $document->referenceNumber }}<br>
                                @endif
                                @if ($document->dueDate)
                                    Tanggal: {{ \Carbon\Carbon::parse($document->dueDate)->format('d F Y') }}
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>

                <!-- Profit -->
                <tr>
                    <td colspan="2" class="px-2 text-right">Profit {{ $document->profitPercentage }}%</td>
                    <td class="px-2 text-right">
                        {{ $document->profit }}
                    </td>
                </tr>

                <!-- Jumlah -->
                <tr>
                    <td colspan="2" class="px-2 text-right border-t border-black">Jumlah</td>
                    <td class="px-2 text-right border-t border-black">
                        {{ $document->subtotal }}
                    </td>
                </tr>


                <!-- PPN -->
                <tr>
                    <td colspan="2" class="px-2 text-right">PPN 11%</td>
                    <td class="px-2 text-right">
                        {{ $document->tax }}
                    </td>
                </tr>

                <!-- Total -->
                <tr>
                    <td colspan="2" class="px-2 font-semibold text-right">Total</td>
                    <td class="px-2 font-semibold text-right">
                        {{ $document->total }}
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

        <div class="leading-relaxed" style="margin-top: 50px">Pembayaran harap dilakukan melalui:</div>
        <div class="mb-2 text-xs font-semibold">
            {{ $document->bankName }}<br>
            A/n. {{ $document->accountName }}<br>
            ACCOUNT NO. {{ $document->accountNumber }}
        </div>

        {{-- Signature --}}
        <div class="w-64 ml-auto text-left">
            {{ $document->accountName }}<br>
            Jakarta, {{ \Carbon\Carbon::parse($document->date)->format('d F Y') }}<br><br><br>
            <div class="mt-6 font-semibold">{{ $document->beneficiaryName }}</div>
            {{-- <div>{{ $document->beneficiaryTitle }}</div> --}}
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
</style>
