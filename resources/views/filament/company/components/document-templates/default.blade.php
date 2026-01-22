<x-company.document-template.container class="default-template-container" :preview="$preview">

    <x-company.document-template.header class="border-b default-template-header">
        <div class="w-1/3">
            @if($document->logo && $document->showLogo)
                <x-company.document-template.logo :src="$document->logo"/>
            @endif
        </div>

        <div class="w-2/3 text-right">
            <div class="space-y-4">
                <div>
                    <h1 class="text-3xl font-light uppercase">{{ $document->header }}</h1>
                    @if ($document->subheader)
                        <p class="text-sm text-gray-600">{{ $document->subheader }}</p>
                    @endif
                </div>
                <div class="text-sm">
                    <strong class="block text-sm">{{ $document->company->name }}</strong>
                    @if($formattedAddress = $document->company->getFormattedAddressHtml())
                        {!! $formattedAddress !!}
                    @endif
                </div>
            </div>
        </div>
    </x-company.document-template.header>

    <x-company.document-template.metadata class="space-y-4 default-template-metadata mt-20">
        <div class="flex items-end justify-between">
            <!-- Billing Details -->
            <div class="text-xs w-[60%] max-w-[50ch] break-words">
                <h3 class="mb-1 font-medium text-gray-600">BILL TO</h3>
                @if($document->recipientDetails)
                    <p class="text-sm font-bold">{!! nl2br(e($document->recipientDetails)) !!},</p>
                @endif
                <p class="text-sm font-bold">{{ $document->client?->name ?? 'Client Not Found' }}</p>
                @if($document->client && ($formattedAddress = $document->client->getFormattedAddressHtml()))
                    {!! $formattedAddress !!}
                @endif
            </div>

            <div class="text-sm">
                <table class="min-w-full">
                    <tbody>
                    <tr>
                        <td class="pr-2 font-semibold text-right">{{ $document->label->number }}:</td>
                        <td class="pl-2 text-left">{{ $document->number }}</td>
                    </tr>
                    @if($document->referenceNumber)
                        <tr>
                            <td class="pr-2 font-semibold text-right">{{ $document->label->referenceNumber }}:</td>
                            <td class="pl-2 text-left">{{ $document->referenceNumber }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="pr-2 font-semibold text-right">{{ $document->label->date }}:</td>
                        <td class="pl-2 text-left">{{ $document->date }}</td>
                    </tr>
                    <tr>
                        <td class="pr-2 font-semibold text-right">{{ $document->label->dueDate }}:</td>
                        <td class="pl-2 text-left">{{ $document->dueDate }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </x-company.document-template.metadata>

    <div class="flex flex-col">
    <!-- Line Items Table -->
    <x-company.document-template.line-items class="default-template-line-items">
        <table class="w-full text-left table-fixed">
            <thead class="text-sm leading-relaxed" style="background: {{ $document->accentColor }}">
            <tr class="text-white">
                <th class="text-left pl-6 w-[50%] py-2">{{ $document->columnLabel->items }}</th>
                <th class="text-center w-[10%] py-2">{{ $document->columnLabel->units }}</th>
                <th class="text-right w-[20%] py-2">{{ $document->columnLabel->price }}</th>
                <th class="text-right pr-6 w-[20%] py-2">{{ $document->columnLabel->amount }}</th>
            </tr>
            </thead>
            <tbody class="text-sm border-b-2 border-gray-300">
            @foreach($document->lineItems as $item)
                <tr>
                    <td class="py-3 pl-6 font-semibold text-left">
                        {{ $item->name }}
                        @if($item->description)
                            <div class="mt-1 font-normal text-gray-600 line-clamp-2">{{ $item->description }}</div>
                        @endif
                    </td>
                    <td class="py-3 text-center">{{ $item->quantity }}</td>
                    <td class="py-3 text-right">{{ $item->unitPrice }}</td>
                    <td class="py-3 pr-6 text-right">{{ $item->subtotal }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="text-sm summary-section">
            @if($document->subtotal)
                <tr>
                    <td class="py-2 pl-6" colspan="2"></td>
                    <td class="py-2 font-semibold text-right">Subtotal:</td>
                    <td class="py-2 pr-6 text-right">{{ $document->subtotal }}</td>
                </tr>
            @endif
            @if($document->discount)
                <tr class="text-success-800">
                    <td class="py-2 pl-6" colspan="2"></td>
                    <td class="py-2 text-right">Discount:</td>
                    <td class="py-2 pr-6 text-right">
                        ({{ $document->discount }})
                    </td>
                </tr>
            @endif
            @if($document->tax)
                <tr>
                    <td class="py-2 pl-6" colspan="2"></td>
                    <td class="py-2 text-right">Tax:</td>
                    <td class="py-2 pr-6 text-right">{{ $document->tax }}</td>
                </tr>
            @endif
            <tr>
                <td class="py-2 pl-6" colspan="2"></td>
                <td class="py-2 font-semibold text-right border-t">{{ $document->amountDue ? 'Total' : 'Grand Total' }}:</td>
                <td class="py-2 pr-6 text-right border-t">{{ $document->total }}</td>
            </tr>
            @if($document->amountDue)
                <tr>
                    <td class="py-2 pl-6" colspan="2"></td>
                    <td class="py-2 font-semibold text-right border-t-4 border-double">{{ $document->label->amountDue }}
                        ({{ $document->currencyCode }}):
                    </td>
                    <td class="py-2 pr-6 text-right border-t-4 border-double">{{ $document->amountDue }}</td>
                </tr>
            @endif
            </tfoot>
        </table>
    </x-company.document-template.line-items>
    </div>

    <!-- Footer Notes -->
    <x-company.document-template.footer class="flex flex-col p-6 text-sm default-template-footer">
        <div>
            <h4 class="mb-2 font-semibold">Terms & Conditions</h4>
            <p class="break-words line-clamp-4">{{ $document->terms }}</p>
        </div>

        @if($document->footer)
            <div class="py-4 mt-auto text-center">
                <p class="font-semibold">{{ $document->footer }}</p>
            </div>
        @endif
    </x-company.document-template.footer>

    <style>
        .default-template-container {
            display: flex;
            flex-direction: column;
            min-height: 297mm;
            padding-bottom: 3cm !important;
        }
        @media print {
            .default-template-container {
                min-height: 0;
                display: flex;
                flex-direction: column;
                height: 100vh;
                padding-bottom: 3cm !important;
            }
        }
    </style>
</x-company.document-template.container>
