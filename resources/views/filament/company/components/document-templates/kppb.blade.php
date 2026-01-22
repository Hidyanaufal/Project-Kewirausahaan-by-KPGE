<div class="p-6 text-gray-800 bg-white rounded-md shadow-sm invoice-container">
    {{-- Header --}}
    <div class="mb-6 header">
        <div class="flex items-center">
            <!-- Logo -->
            <div class="w-24 h-24 mr-4">
                <img src="{{ asset('image/logo/LOGO PT. KARYA PERSADA PANAS BUMI.jpg') }}" alt="Logo Perusahaan" class="object-contain w-full h-full" />
            </div>
        
            <!-- Company Info -->
            <div class="text-sm font-bold">
                PT. KARYA PERSADA PANAS BUMI<br>
                <small class="block mt-1 text-xs font-normal">
                    <span class="block">Menara Cakrawala / Skyline Building Lt. 7,</span>
                    <span class="block">Jl. MH. Thamrin No. 9 Jakarta Pusat - 10340</span>
                </small>
                <div class="mt-1 text-sm font-normal">
                    Tlp. 021 3143133 Fax. 021 39833230
                </div>
            </div>
        </div>        
    </div>

    {{-- Title --}}
    <div class="my-2 text-center">
        <h2 class="text-lg font-bold">{{ $document->header }}</h2>
        <div class="mt-1 text-sm">No. {{ $document->number }}</div>
    </div>

    {{-- Metadata --}}
    <div class="space-y-4 metadata mt-20">
        <div class="flex mb-4 leading-relaxed w-[60%]">
            <div class="w-20 text-xs whitespace-nowrap">Kepada :</div>
            <div class="text-xs max-w-[50ch] break-words">
                @if($document->recipientDetails)
                    <p class="font-semibold">{!! nl2br(e($document->recipientDetails)) !!},</p>
                @endif
                <p class="font-semibold">{{ $document->client?->name ?? 'Client Not Found' }}</p>
                @if($document->client && ($formattedAddress = $document->client->getFormattedAddressHtml()))
                    {!! $formattedAddress !!}
                @endif
            </div>
        </div>
    </div>

    <div class="flex flex-col">
    {{-- Line Items --}}
    <div class="mb-6 line-items">
        <table class="w-full text-sm border-4 border-black border-double">
            <thead>
                <tr>
                    <th class="w-1/6 px-4 py-2 text-center border border-black">Tanggal</th>
                    <th class="w-3/6 px-4 py-2 text-left border border-black">Keterangan</th>
                    <th class="w-1/6 px-4 py-2 text-center border border-black">Harga Satuan</th>
                    <th class="w-1/6 px-4 py-2 text-center border border-black">Jumlah (Rp.)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($document->lineItems as $index => $item)
                <tr>
                    @if($index === 0)
                    <td class="px-4 py-2 text-center border border-black" rowspan="{{ count($document->lineItems) + 3 }}">
                        {{ \Carbon\Carbon::parse($document->dueDate)->format('d-M-y') }}
                    </td>
                    <td class="py-3 pl-6 font-semibold text-left border border-black">
                        @isset($document->project)
                        <div class="mb-6">
                            {{ $document->project }}
                            @if($item->description)
                            <div class="mt-1 font-normal text-gray-600 break-words whitespace-pre-wrap">{{ $item->description }}</div>
                            @endif
                        </div>
                        @endisset
                    </td>
                    <td class="py-3 pl-6 font-semibold text-left border border-black">
                    </td>
                </tr>
                <tr>
                    @endif
                    <td class="px-4 py-2 border border-black">
                        {{ $item->name }} unit {{ $item->quantity}}
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
                    <td class="px-2 py-6 border-b-2 border-black">
                        @if($document->referenceNumber || $document->dueDate)
                            <div class="text-sm text-gray-800">
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
                    <td class="py-3 pl-6 font-semibold text-left border border-black">
                    </td>
                    <tr>
                        <td colspan="2" class="px-3 py-2 text-right border-b-2 border-black">Keuntungan 5,8%</td>
                        <td class="px-3 py-2 text-right border-b-2 border-black">{{ $document->discount ?? 'Rp0' }}</td>
                    </tr>
                </tr>
        
                <!-- Profit -->
        
                <!-- Jumlah -->
                <tr>
                    <td colspan="3" class="px-3 py-2 text-right border border-black">Jumlah</td>
                    <td class="px-3 py-2 text-right border border-black">
                        {{ $document->subtotal }}
                    </td>
                </tr>
        
                <!-- PPN -->
                <tr>
                    <td colspan="3" class="px-3 py-2 text-right border border-black">PPN 11%</td>
                    <td class="px-3 py-2 text-right border border-black">
                        {{ $document->tax }}
                    </td>
                </tr>
        
                <!-- Total -->
                <tr>
                    <td colspan="3" class="px-3 py-2 font-bold text-right border border-black">Total setelah PPN</td>
                    <td class="px-3 py-2 font-bold text-right border border-black">
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
    </div>
    </div>

    {{-- Footer Notes --}}
    <div class="mt-auto text-sm footer">
        <div class="leading-relaxed">Pembayaran harap dilakukan melalui:</div>
        <div class="mb-2 text-sm font-semibold">
            PT. BANK MANDIRI (PERSERO) KCP JKT SARINAH<br>
            A/n. PT. KARYA PERSADA PANAS BUMI<br>
            ACCOUNT NO. 103-000-6116806
        </div>

        {{-- Signature --}}
        <div class="w-64 ml-auto text-left">
            PT. KARYA PERSADA PANAS BUMI<br>
            Jakarta, {{ \Carbon\Carbon::parse($document->date)->format('d F Y') }}<br><br><br>
            <div class="mt-4 font-bold">{{ $document->signatureName ?? 'Robby Wijayadi' }}</div>
            <div>{{ $document->signatureTitle ?? 'Direktur' }}</div>
        </div>
    </div>
</div>

<style>
    .invoice-container {
        font-family: Arial, sans-serif;
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
    .header {
        padding-bottom: 8px;
        border-bottom: 1px solid #ccc;
    }
    .header span {
        display: block;
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
