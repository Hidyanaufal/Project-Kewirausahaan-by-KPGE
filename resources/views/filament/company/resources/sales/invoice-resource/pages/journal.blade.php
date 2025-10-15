<table class="w-full table-auto min-w-[50rem] divide-y divide-gray-200 dark:divide-white/5">
    <thead>
        <tr class="bg-gray-50 dark:bg-gray-800">
            <th class="px-3 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">Account</th>
            <th class="px-3 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">Debit</th>
            <th class="px-3 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">Credit</th>
            <th class="px-3 py-2 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">Description</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
        @php
            $totalDebit = 0;
            $totalCredit = 0;
            $groupedEntries = $journalEntries->groupBy('account_id'); // Grouping by account_id
        @endphp

        @foreach($groupedEntries as $accountId => $entries)
            @php
                $accountDebit = 0;
                $accountCredit = 0;
            @endphp

            @foreach($entries as $journalEntry)
                <tr>
                    @if ($loop->first) <!-- Display account name only for the first entry in the group -->
                        <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100" rowspan="{{ count($entries) }}">{{ $journalEntry->account->name }}</td>
                    @endif

                    <!-- Debit -->
                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">
                        @if ($journalEntry->type->value == 'debit')
                            @php $accountDebit += $journalEntry->amount; @endphp
                            <span>{{ number_format($journalEntry->amount / 100) }}</span>
                        @else
                            <span class="text-transparent">-</span>
                        @endif
                    </td>

                    <!-- Credit -->
                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">
                        @if ($journalEntry->type->value == 'credit')
                            @php $accountCredit += $journalEntry->amount; @endphp
                            <span>{{ number_format($journalEntry->amount / 100) }}</span>
                        @else
                            <span class="text-transparent">-</span>
                        @endif
                    </td>

                    <!-- Description -->
                    <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-300">
                        <span class="text-transparent">{{ $journalEntry->description }}</span>
                    </td>
                </tr>
            @endforeach

            @php
                $totalDebit += $accountDebit;
                $totalCredit += $accountCredit;
            @endphp

            <!-- Display total debit and credit for this account -->
            <tr class="font-semibold bg-gray-50 dark:bg-gray-800">
                <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">Total for {{ $journalEntry->account->name }}</td>
                <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{{ number_format($accountDebit / 100) }}</td>
                <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{{ number_format($accountCredit / 100) }}</td>
                <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100"></td>
            </tr>
            @endforeach
        </tbody>
    <tfoot>
        <tr class="bg-gray-100 dark:bg-gray-700">
            <td class="px-3 py-2 text-sm font-semibold text-left text-gray-900 dark:text-gray-100">Grand Total</td>
            <td class="px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ number_format($totalDebit / 100) }}</td>
            <td class="px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ number_format($totalCredit / 100) }}</td>
            <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100"></td>
        </tr>
    </tfoot>
</table>
