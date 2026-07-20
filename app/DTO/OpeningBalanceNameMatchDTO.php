<?php

namespace App\DTO;

class OpeningBalanceNameMatchDTO
{
    /**
     * @param  list<array{id: int, code: string, name: string, similarity: float}>  $candidates
     */
    public function __construct(
        public int $rowNumber,
        public string $excelName,
        public ?string $excelCode,
        public ?string $excelAccountType,
        public int $balanceMinor,
        public string $status,
        public string $decisionStatus,
        public ?string $action = null,
        public ?int $accountId = null,
        public ?bool $renameToExcel = null,
        public array $candidates = [],
        public ?string $suggestedCode = null,
        public ?string $skipReason = null,
        public ?string $excelParentName = null,
        public ?string $excelParentAccountNo = null,
        public ?string $excelParentAccountType = null,
        public ?int $excelParentRowNumber = null,
        public ?int $parentAccountId = null,
        public ?string $parentAction = null,
        public ?string $parentStatus = null,
        public ?string $parentSystemCode = null,
        public ?string $parentSystemName = null,
        public ?string $codeSource = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toMappingRow(): array
    {
        $row = [
            'row_number' => $this->rowNumber,
            'excel_name' => $this->excelName,
            'excel_code' => $this->excelCode,
            'excel_account_type' => $this->excelAccountType,
            'balance_minor' => $this->balanceMinor,
            'status' => $this->status,
            'decision_status' => $this->decisionStatus,
            'action' => $this->action,
            'account_id' => $this->accountId,
            'rename_to_excel' => $this->renameToExcel,
        ];

        if ($this->candidates !== []) {
            $row['candidates'] = $this->candidates;
        }

        if ($this->suggestedCode !== null) {
            $row['suggested_code'] = $this->suggestedCode;
            $row['code'] = $this->suggestedCode;
        }

        if ($this->excelAccountType !== null && $this->status === 'new') {
            $row['account_type'] = $this->excelAccountType;
        }

        if ($this->skipReason !== null) {
            $row['skip_reason'] = $this->skipReason;
        }

        if ($this->status === 'new' || $this->excelParentName !== null) {
            $row['excel_parent_name'] = $this->excelParentName;
            $row['excel_parent_account_no'] = $this->excelParentAccountNo;
            $row['excel_parent_account_type'] = $this->excelParentAccountType;
            $row['excel_parent_row_number'] = $this->excelParentRowNumber;
            $row['parent_account_id'] = $this->parentAccountId;
            $row['parent_action'] = $this->parentAction;
            $row['parent_status'] = $this->parentStatus;
            $row['parent_system_code'] = $this->parentSystemCode;
            $row['parent_system_name'] = $this->parentSystemName;
            $row['code_source'] = $this->codeSource;
        }

        return $row;
    }
}
