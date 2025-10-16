<table class="w-full table-auto min-w-[50rem] divide-y divide-gray-200 dark:divide-white/5">
    <thead>
        <tr class="bg-gray-50 dark:bg-gray-800">
            <th class="px-3 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">ID</th>
            <th class="px-3 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">Account</th>
            <th class="px-3 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">Debit</th>
            <th class="px-3 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">Credit</th>
            <th class="px-3 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">Description</th>
            <th class="px-3 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">Timestamp</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
        @php
            $totalDebit = 0;
            $totalCredit = 0;
            $groupedEntries = $journalEntries->groupBy(function ($entry) {
                // Group by the created_at date formatted to minute level
                return \Carbon\Carbon::parse($entry->created_at)->format('Y-m-d H:i');
            });
        @endphp

        @foreach($groupedEntries as $timestamp => $entries)
            @foreach($entries as $journalEntry)
                <tr>
                    <!-- ID -->
                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">
                        {{ $journalEntry->id }}
                    </td>

                    <!-- Account Name -->
                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">
                        {{ $journalEntry->account->name }}
                    </td>

                    <!-- Debit -->
                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">
                        @if ($journalEntry->type->value == 'debit')
                            @php 
                                $totalDebit += $journalEntry->amount; 
                            @endphp
                            <span style="color: #388E3C;">{{ number_format($journalEntry->amount / 100) }}</span>
                        @else
                            <span class="text-transparent">-</span>
                        @endif
                    </td>

                    <!-- Credit -->
                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">
                        @if ($journalEntry->type->value == 'credit')
                            @php 
                                $totalCredit += $journalEntry->amount; 
                            @endphp
                            <span style="color: #C62828;">{{ number_format($journalEntry->amount / 100) }}</span>
                        @else
                            <span class="text-transparent">-</span>
                        @endif
                    </td>

                    <!-- Description -->
                    <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-300">
                        <span class="text-transparent">{{ $journalEntry->description }}</span>
                    </td>

                    <!-- Timestamp -->
                    <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-300">
                        {{ \Carbon\Carbon::parse($journalEntry->created_at)->format('Y-m-d H:i') }}
                    </td>
                </tr>
            @endforeach
        @endforeach
    </tbody>
    <tfoot>
        <tr class="bg-gray-100 dark:bg-gray-700">
            <td colspan="2" class="px-3 py-2 text-sm font-semibold text-left text-gray-900 dark:text-gray-100">Total</td>
            <td class="px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ number_format($totalDebit / 100) }}
            </td>
            <td class="px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ number_format($totalCredit / 100) }}
            </td>
            <td class="px-3 py-2 text-sm"></td>
            <td class="px-3 py-2 text-sm"></td>
        </tr>
    </tfoot>
</table>
