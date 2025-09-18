<x-company.document-template.container class="default-template-container" :preview="$preview">

    {{-- Header --}}
    <x-company.document-template.header class="border-b default-template-header">
        <div class="flex items-center mb-6">
            <!-- Logo -->
            <div>
                <img src="{{ asset('image/logo/LOGO_PT._JAYATAMA_ENERGI_SEJAHTERA.png') }}" alt="Logo Perusahaan" class="object-contain w-full h-full" />
            </div>
        
            <!-- Company Info -->
            <div class="font-bold">
                PT. JAYATAMA ENERGI SEJAHTERA<br>
                <small class="block mt-1 text-xs font-normal">
                    Bizzcentrum, Jl. H Agus Salim No.50, Kebon Sirih,<br>
                    Menteng, Jakarta Pusat, 10340
                </small>
                <div class="mt-1 text-sm font-normal">
                    pt.jayatamaenergi.sejahtera@gmail.com
                </div>
            </div>
        </div>        
    </x-company.document-template.header>

    <div class="mt-2 text-center">
        <h2 class="my-8 font-bold text-md">{{ $document->header }}</h2>
        <div class="mb-8">No. {{ $document->number }}</div>
    </div>

    {{-- Metadata --}}
    <x-company.document-template.metadata class="space-y-4 default-template-metadata">
        <div class="flex mb-8 leading-relaxed">
            <div class="w-20">Kepada :</div>
            <div class="text-sm">
                <p class="text-sm font-bold">{{ $document->client?->name ?? 'Client Not Found' }}</p>
                @if($document->client && ($formattedAddress = $document->client->getFormattedAddressHtml()))
                    {!! $formattedAddress !!}
                @endif
            </div>
        </div>
    </x-company.document-template.metadata>

    {{-- Line Items --}}
    <x-company.document-template.line-items class="px-6 default-template-line-items">
        <table class="w-full mb-6 text-sm border-2 border-separate border-black border-double border-spacing-0">
            <thead>
                <tr>
                    <th class="w-1/6 px-2 py-2 text-center whitespace-normal border border-black">Tanggal / Date</th>
                    <th class="w-4/6 px-4 py-2 text-left whitespace-normal border border-black">Keterangan / Description</th>
                    <th class="w-1/6 px-2 py-2 text-center whitespace-normal border border-black">Jumlah / Amount (Rp.)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($document->lineItems as $index => $item)
                <tr>
                    @if($index === 0)
                    <td class="px-4 py-2 text-center border border-black" rowspan="{{ count($document->lineItems) + 1 }}">
                        {{ \Carbon\Carbon::parse($document->dueDate)->format('d-M-y') }}
                    </td>
                    @endif
                    <td class="py-3 pl-6 font-semibold text-left border border-black">
                        {{ $item->name }}
                        @if($item->description)
                        <div class="mt-1 font-normal text-gray-600">{{ $item->description }}</div>
                        @endif
                    </td>
                    <td class="py-3 pr-6 text-right border border-black">
                        {{ $item->subtotal }}
                    </td>
                </tr>
                @endforeach
        
                <tr>
                    <td class="px-2 py-6" colspan="2">
                        @if($document->referenceNumber || $document->dueDate)
                            <div class="text-sm leading-relaxed text-gray-800">
                                @if($document->referenceNumber)
                                    <strong>Ref PO</strong><br>
                                    No. PO: {{ $document->referenceNumber }}<br>
                                @endif
                                @if($document->dueDate)
                                    Tanggal: {{ \Carbon\Carbon::parse($document->dueDate)->format('d F Y') }}
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
        
                <!-- Jumlah -->
                <tr>
                    <td colspan="2" class="px-3 py-2 text-right border border-black">Jumlah</td>
                    <td class="px-3 py-2 text-right border border-black">
                        Rp {{ $document->subtotal }}
                    </td>
                </tr>

                <!-- DPP -->
                <tr>
                    <td colspan="2" class="px-3 py-2 text-right border border-black">DPP Lain-Lain</td>
                    <td class="px-3 py-2 text-right border border-black">
                        Rp {{ $document->dpp }}
                    </td>
                </tr>
        
                <!-- PPN -->
                <tr>
                    <td colspan="2" class="px-3 py-2 text-right border border-black">PPN 11%</td>
                    <td class="px-3 py-2 text-right border border-black">
                        Rp {{ $document->tax }}
                    </td>
                </tr>
        
                <!-- Total -->
                <tr>
                    <td colspan="2" class="px-3 py-2 font-bold text-right border border-black">Total setelah PPN</td>
                    <td class="px-3 py-2 font-bold text-right border border-black">
                        Rp {{ $document->amountDue }}
                    </td>
                </tr>
        
                <!-- Terbilang -->
                <tr>
                    <td colspan="3" class="px-3 py-2 border border-black">
                        <strong>Terbilang:</strong>
                        <em>{{ $document->terbilang }}</em>
                    </td>
                </tr>
            </tbody>
            {{-- <tfoot class="text-sm border-t-4 border-double summary-section">
                @if($document->subtotal)
                <tr>
                    <td class="pl-6"></td>
                    <td class="text-right">Jumlah:</td>
                    <td class="pr-6 text-right">{{ $document->subtotal }}</td>
                </tr>
                @endif
        
                <tr>
                    <td class="pl-6"></td>
                    <td class="text-right">DPP Lain-Lain:</td>
                    <td class="pr-6 text-right">{{ $document->discount }}</td>
                </tr>
        
                @if($document->tax)
                <tr>
                    <td class="pl-6"></td>
                    <td class="font-semibold text-right">PPN 11%:</td>
                    <td class="pr-6 text-right">{{ $document->tax }}</td>
                </tr>
                @endif
        
                <tr>
                    <td class="pl-6"></td>
                    <td class="font-semibold text-right">{{ 'Grand Total' }}:</td>
                    <td class="pr-6 text-right">{{ $document->amountDue }}</td>
                </tr>
        
                <tr>
                    <td colspan="3" class="px-4 py-3 border border-black">
                        <strong>Terbilang:</strong> 
                        <em>{{ $document->terbilang }}</em>
                    </td>
                </tr>
            </tfoot> --}}
        </table>
    </x-company.document-template.line-items>

    {{-- Footer Notes --}}
    <x-company.document-template.footer class="flex flex-col px-6 pb-6 text-sm default-template-footer">
        <div class="text-sm leading-relaxed">Pembayaran harap dilakukan melalui:</div>
        <div class="mb-12 text-sm font-bold leading-relaxed">
            BANK MANDIRI KCP RS PUSAT OTAK NASIONAL<br>
            A/n. PT JAYATAMA ENERGI SEJAHTERA<br>
            ACCOUNT NO. 166-00-0576920-3
        </div>

        {{-- Signature --}}
        {{-- {{ $document->company->name }} --}}
        <div class="w-64 ml-auto text-left">
            PT Jayatama Energi Sejahtera<br>
            Jakarta, {{ \Carbon\Carbon::parse($document->date)->format('d F Y') }}<br><br><br>
            <div class="mt-12 font-bold">{{ $document->signatureName ?? 'Santi' }}</div>
            <div>{{ $document->signatureTitle ?? 'Direktur' }}</div>
        </div>

    </x-company.document-template.footer>

</x-company.document-template.container>
