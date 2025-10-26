<?php

namespace App\Contracts;

interface EnhancedReport
{
    public function buildReportEnhanced(
        ?string $template = null,
        string $format = 'pdf',
    ): array;
}
