<?php

namespace App\DTO;

class AccountParentResolutionDTO
{
    public function __construct(
        public string $parentStatus,
        public ?string $parentAction,
        public ?int $parentAccountId,
        public ?string $parentSystemCode,
        public ?string $parentSystemName,
        public ?string $excelParentAccountNo,
        public ?string $excelParentName,
        public ?string $excelParentAccountType,
        public ?int $excelParentRowNumber,
        public string $suggestedCode,
        public string $codeSource,
        public ?string $excelCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'parent_status' => $this->parentStatus,
            'parent_action' => $this->parentAction,
            'parent_account_id' => $this->parentAccountId,
            'parent_system_code' => $this->parentSystemCode,
            'parent_system_name' => $this->parentSystemName,
            'excel_parent_account_no' => $this->excelParentAccountNo,
            'excel_parent_name' => $this->excelParentName,
            'excel_parent_account_type' => $this->excelParentAccountType,
            'excel_parent_row_number' => $this->excelParentRowNumber,
            'suggested_code' => $this->suggestedCode,
            'code_source' => $this->codeSource,
            'excel_code' => $this->excelCode,
        ];
    }
}
