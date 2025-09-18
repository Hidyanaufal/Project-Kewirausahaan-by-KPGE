<x-company.document-template.container
    class="default-template-container"
    :preview="$preview">

    {{-- Header --}}
    <x-company.document-template.header class="border-b default-template-header">
        <div class="flex items-center mb-6">
            <!-- Logo -->
            <div class="w-24 h-24 mr-4">
                <img
                    src="{{ asset('image/logo/LOGO PT. KARYA PERSADA PANAS BUMI.jpg') }}"
                    alt="Logo Perusahaan"
                    class="object-contain w-full h-full"/>
            </div>

            <!-- Company Info -->
            <div class="font-bold">
                PT. KARYA PERSADA PANAS BUMI (KPP)<br>
                <small class="block mt-1 text-xs font-normal">
                    Menara Cakrawala / Skyline Building Lt. 7,<br>
                    Jl. MH. Thamrin No. 9 Jakarta Pusat - 10340
                </small>
                <div class="mt-1 text-sm font-normal">
                    Tlp. 021-39833222 Fax. 021-39833230
                </div>
            </div>
        </div>
    </x-company.document-template.header>

    <div class="mt-2 text-center">
        <h2 class="my-8 font-bold text-md">{{ $document->header }}</h2>
        <div class="mb-8">No.
            {{ $document->number }}</div>
    </div>

    {{-- Metadata --}}
    <x-company.document-template.metadata
        class="space-y-4 default-template-metadata">
        <div class="flex mb-8 leading-relaxed">
            <div class="w-20">Kepada :</div>
            <div class="text-sm">
                <p class="text-sm font-bold">{{ $document->client?->name ?? 'Client Not Found' }}</p>
                @if($document->client && ($formattedAddress =
                $document->client->getFormattedAddressHtml())) {!! $formattedAddress !!} @endif
            </div>
        </div>
    </x-company.document-template.metadata>

            {{-- Line Items --}}
<x-company.document-template.line-items class="px-6 default-template-line-items">
    <table class="w-full mb-6 text-sm border border-collapse border-black">
        <thead>
            <tr>
                <th class="w-1/6 px-2 py-2 text-center border border-black">Tanggal / Date</th>
                <th class="w-3/6 px-4 py-2 text-left border border-black">Keterangan / Description</th>
                <th class="w-1/6 px-2 py-2 text-center border border-black">Harga Satuan</th>
                <th class="w-1/6 px-2 py-2 text-center border border-black">Jumlah / Amount (Rp.)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($document->lineItems as $index => $item)
            <tr>
                @if($index === 0)
                <td class="px-4 py-2 border border-black" rowspan="{{ count($document->lineItems) + 3 }}">
                    {{ \Carbon\Carbon::parse($document->dueDate)->format('d-M-y') }}
                </td>
                <td class="py-3 pl-6 font-semibold text-left border border-black">
                    @isset($document->project)
                    <div class="mb-6">
                        {{ $document->project }}
                        @if($item->description)
                        <div class="mt-1 font-normal text-gray-600">{{ $item->description }}</div>
                        @endif
                    </div>
                    @endisset
                </td>
            </tr>
            <tr>
                @endif
                <td class="px-4 py-2 border border-black">
                    {{ $item->name }}
                </td>
                <td class="py-3 pr-6 text-right border border-black">
                    {{ $item->subtotal }}
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
                <tr>
                    <td colspan="2" class="px-3 py-2 text-right border-b-2 border-black">Keuntungan 5,8%</td>
                    <td class="px-3 py-2 text-right border-b-2 border-black">Rp {{ $document->discount }}</td>
                </tr>
            </tr>

                    <!-- Jumlah -->
            <tr>
                <td colspan="3" class="px-3 text-right border-t">Jumlah</td>
                <td class="px-3 text-right border-t">
                    Rp
                    {{ $document->subtotal }}
                </td>
            </tr>

                    <!-- PPN -->
                    <tr>
                        <td colspan="3" class="px-3 text-right ">PPN 11%</td>
                        <td class="px-3 text-right ">
                            Rp
                            {{ $document->tax }}
                        </td>
                    </tr>

                    <!-- Total -->
                    <tr>
                        <td colspan="3" class="px-3 font-bold text-right ">Total setelah PPN</td>
                        <td class="px-3 font-bold text-right ">
                            Rp
                            {{ $document->amountDue }}
                        </td>
                    </tr>

                    <!-- Terbilang -->
                    <tr>
                        <td colspan="4" class="px-3 py-2 border border-black">
                            <strong>Terbilang:</strong>
                            <em>{{ $document->terbilang }}</em>
                        </td>
                    </tr>
                </tbody>
            </table>
        </x-company.document-template.line-items>

                    {{-- Footer Notes --}}
                    <x-company.document-template.footer
                        class="flex flex-col px-6 pb-6 text-sm default-template-footer">
                        <div class="text-sm leading-relaxed">Pembayaran harap dilakukan melalui:</div>
                        <div class="mb-12 text-sm font-bold leading-relaxed">
                            PT. BANK MANDIRI (PERSERO) KCP JKT SARINAH<br>
                                A/n. PT. KARYA PERSADA PANAS BUMI<br>
                                    ACCOUNT NO. 103-000-6116806
                                </div>

                                {{-- Signature --}}
                                {{-- {{ $document->company->name }}
                                --}}
                                <div class="w-64 ml-auto text-left">
                                    PT. KARYA PERSADA PANAS BUMI<br>
                                        Jakarta,
                                        {{ \Carbon\Carbon::parse($document->date)->format('d F Y') }}
                                        <br>
                                            <br>
                                                <br>
                                                <br>
                                                    <div class="mt-4 font-bold">{{ $document->signatureName ?? 'Robby Wijayadi' }}</div>
                                                    <div>{{ $document->signatureTitle ?? 'Direktur' }}</div>
                                                </div>

                                            </x-company.document-template.footer>

                                        </x-company.document-template.container>