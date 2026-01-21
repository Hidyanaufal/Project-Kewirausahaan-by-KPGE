<x-company.document-template.container class="classic-template-container" :preview="$preview">
    <!-- Header Section -->
    <x-company.document-template.header class="classic-template-header">
        <div class="w-2/3 text-left">
            <div class="text-sm">
                <strong class="block text-sm">{{ $document->company->name }}</strong>
                @if($formattedAddress = $document->company->getFormattedAddressHtml())
                    {!! $formattedAddress !!}
                @endif
            </div>
        </div>

        <div class="flex justify-end w-1/3">
            @if($document->logo && $document->showLogo)
                <x-company.document-template.logo :src="$document->logo"/>
            @endif
        </div>
    </x-company.document-template.header>

    <x-company.document-template.metadata class="space-y-4 classic-template-metadata">
        <div class="flex items-center">
            <hr class="grow-[2] py-0.5 border-solid border-y-2" style="border-color: {{ $document->accentColor }};">
            <x-icons.document-header-decoration
                color="{{ $document->accentColor }}"
                text="{{ $document->header }}"
                class="w-60"
            />
            <hr class="grow-[2] py-0.5 border-solid border-y-2" style="border-color: {{ $document->accentColor }};">
        </div>
        @if ($document->subheader)
            <p class="text-sm text-center text-gray-600">{{ $document->subheader }}</p>
        @endif

        <div class="flex items-end justify-between">
            <!-- Billing Details -->
            <div class="text-sm max-w-[50ch] break-words">
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

    <div class="flex flex-col justify-center flex-grow">
    <!-- Line Items -->
    <x-company.document-template.line-items class="px-6 classic-template-line-items">
        <table class="w-full text-left table-fixed">
            <thead class="text-sm leading-relaxed">
            <tr>
                <th class="text-left w-[50%] py-4">{{ $document->columnLabel->items }}</th>
                <th class="text-center w-[10%] py-4">{{ $document->columnLabel->units }}</th>
                <th class="text-right w-[20%] py-4">{{ $document->columnLabel->price }}</th>
                <th class="text-right w-[20%] py-4">{{ $document->columnLabel->amount }}</th>
            </tr>
            </thead>
            <tbody class="text-sm border-gray-300 border-dotted border-y-2">
            @foreach($document->lineItems as $item)
                <tr>
                    <td class="py-3 font-semibold text-left">
                        {{ $item->name }}
                        @if($item->description)
                            <div class="mt-1 font-normal text-gray-600 line-clamp-2">{{ $item->description }}</div>
                        @endif
                    </td>
                    <td class="py-3 text-center">{{ $item->quantity }}</td>
                    <td class="py-3 text-right">{{ $item->unitPrice }}</td>
                    <td class="py-3 text-right">{{ $item->subtotal }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <!-- Financial Details and Notes -->
        <div class="flex justify-between pt-4 space-x-1 text-sm">
            <!-- Notes Section -->
            <div class="w-[60%] py-2">
                <p class="font-semibold">{{ $document->footer }}</p>
            </div>

            <!-- Financial Summary -->
            <div class="w-[40%]">
                <table class="w-full table-fixed whitespace-nowrap">
                    <tbody class="text-sm">
                    @if($document->subtotal)
                        <tr>
                            <td class="py-2 font-semibold text-right">Subtotal:</td>
                            <td class="py-2 text-right">{{ $document->subtotal }}</td>
                        </tr>
                    @endif
                    @if($document->discount)
                        <tr class="text-success-800">
                            <td class="py-2 text-right">Discount:</td>
                            <td class="py-2 text-right">
                                ({{ $document->discount }})
                            </td>
                        </tr>
                    @endif
                    @if($document->tax)
                        <tr>
                            <td class="py-2 text-right">Tax:</td>
                            <td class="py-2 text-right">{{ $document->tax }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="py-2 font-semibold text-right border-t">{{ $document->amountDue ? 'Total' : 'Grand Total' }}:</td>
                        <td class="py-2 text-right border-t">{{ $document->total }}</td>
                    </tr>
                    @if($document->amountDue)
                        <tr>
                            <td class="py-2 font-semibold text-right border-t-4 border-double">{{ $document->label->amountDue }}
                                ({{ $document->currencyCode }}):
                            </td>
                            <td class="py-2 text-right border-t-4 border-double">{{ $document->amountDue }}</td>
                        </tr>
                    @endif
                    </tbody>
                </table>
            </div>
        </div>
    </x-company.document-template.line-items>
    </div>

    <!-- Footer -->
    <x-company.document-template.footer class="p-6 text-sm classic-template-footer">
        <h4 class="mb-2 font-semibold">Terms & Conditions</h4>
        <p class="break-words line-clamp-4">{{ $document->terms }}</p>
    </x-company.document-template.footer>

    <style>
        .classic-template-container {
            display: flex;
            flex-direction: column;
            min-height: 297mm;
            padding-bottom: 3cm !important;
        }
        @media print {
            .classic-template-container {
                min-height: 0;
                display: flex;
                flex-direction: column;
                height: 100vh;
                padding-bottom: 3cm !important;
            }
        }
    </style>
</x-company.document-template.container>
