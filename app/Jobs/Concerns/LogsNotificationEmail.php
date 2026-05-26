<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Log;

trait LogsNotificationEmail
{
    protected function logNotificationEmailSent(
        string $notificationType,
        string $documentType,
        int $documentId,
        ?string $documentNumber,
        ?int $companyId,
        ?int $projectId,
        ?int $recipientUserId,
        string $recipientEmail,
        ?int $requestorId = null,
        ?string $requestorEmail = null,
        array $extraContext = [],
    ): void {
        Log::info(class_basename(static::class) . ': notification email sent.', array_merge([
            'notification_type' => $notificationType,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'document_number' => $documentNumber,
            'company_id' => $companyId,
            'project_id' => $projectId,
            'recipient_user_id' => $recipientUserId,
            'recipient_email' => $recipientEmail,
            'requestor_id' => $requestorId,
            'requestor_email' => $requestorEmail,
        ], $extraContext));
    }
}
