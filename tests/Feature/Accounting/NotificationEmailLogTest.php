<?php

use App\Jobs\SendBillApprovedNotification;
use App\Jobs\SendBillSubmissionNotification;
use App\Jobs\SendInvoiceApprovedNotification;
use App\Jobs\SendInvoiceSubmissionNotification;
use App\Mail\BillApprovedNotification;
use App\Mail\BillSubmissionNotification;
use App\Mail\InvoiceApprovedNotification;
use App\Mail\InvoiceSubmissionNotification;
use App\Models\Accounting\Bill;
use App\Models\Accounting\Invoice;
use App\Services\NotificationRecipientService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    /** @var \Tests\TestCase $this */
    $this->withOfferings();
    Mail::fake();
    Log::spy();
});

function assertNotificationEmailLog(string $message, array $expectedContext): void
{
    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $actualMessage, array $context) use ($message, $expectedContext) {
            if ($actualMessage !== $message) {
                return false;
            }

            foreach ($expectedContext as $key => $value) {
                if (($context[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        });
}

test('bill submission email writes a send log', function () {
    /** @var \Tests\TestCase $this */
    $company = $this->testCompany;
    $user = $this->testUser;

    $bill = Bill::withoutEvents(fn () => Bill::factory()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));

    $recipientService = Mockery::mock(NotificationRecipientService::class);
    $recipientService->shouldReceive('getAdminUsersFor')
        ->once()
        ->with($bill->company_id)
        ->andReturn(collect([$user]));

    (new SendBillSubmissionNotification($bill->id))->handle($recipientService);

    Mail::assertSent(BillSubmissionNotification::class, fn (BillSubmissionNotification $mail) => $mail->hasTo($user->email)
        && $mail->bill->is($bill));

    assertNotificationEmailLog('SendBillSubmissionNotification: notification email sent.', [
        'notification_type' => 'submission',
        'document_type' => 'bill',
        'document_id' => $bill->id,
        'document_number' => $bill->bill_number,
        'company_id' => $bill->company_id,
        'project_id' => $bill->project_id,
        'recipient_user_id' => $user->id,
        'recipient_email' => $user->email,
        'requestor_id' => $user->id,
        'requestor_email' => $user->email,
    ]);
});

test('bill approval email writes a send log', function () {
    /** @var \Tests\TestCase $this */
    $company = $this->testCompany;
    $user = $this->testUser;

    $bill = Bill::withoutEvents(fn () => Bill::factory()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));

    (new SendBillApprovedNotification($bill->id))->handle();

    Mail::assertSent(BillApprovedNotification::class, fn (BillApprovedNotification $mail) => $mail->hasTo($user->email)
        && $mail->bill->is($bill));

    assertNotificationEmailLog('SendBillApprovedNotification: notification email sent.', [
        'notification_type' => 'approved',
        'document_type' => 'bill',
        'document_id' => $bill->id,
        'document_number' => $bill->bill_number,
        'company_id' => $bill->company_id,
        'project_id' => $bill->project_id,
        'recipient_user_id' => $user->id,
        'recipient_email' => $user->email,
        'requestor_id' => $user->id,
        'requestor_email' => $user->email,
    ]);
});

test('invoice submission email writes a send log', function () {
    /** @var \Tests\TestCase $this */
    $company = $this->testCompany;
    $user = $this->testUser;

    $invoice = Invoice::withoutEvents(fn () => Invoice::factory()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));

    $recipientService = Mockery::mock(NotificationRecipientService::class);
    $recipientService->shouldReceive('getAdminUsersFor')
        ->once()
        ->with($invoice->company_id)
        ->andReturn(collect([$user]));

    (new SendInvoiceSubmissionNotification($invoice->id))->handle($recipientService);

    Mail::assertSent(InvoiceSubmissionNotification::class, fn (InvoiceSubmissionNotification $mail) => $mail->hasTo($user->email)
        && $mail->invoice->is($invoice));

    assertNotificationEmailLog('SendInvoiceSubmissionNotification: notification email sent.', [
        'notification_type' => 'submission',
        'document_type' => 'invoice',
        'document_id' => $invoice->id,
        'document_number' => $invoice->invoice_number,
        'company_id' => $invoice->company_id,
        'project_id' => $invoice->project_id,
        'recipient_user_id' => $user->id,
        'recipient_email' => $user->email,
        'requestor_id' => $user->id,
        'requestor_email' => $user->email,
    ]);
});

test('invoice approval email writes a send log', function () {
    /** @var \Tests\TestCase $this */
    $company = $this->testCompany;
    $user = $this->testUser;

    $invoice = Invoice::withoutEvents(fn () => Invoice::factory()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));

    (new SendInvoiceApprovedNotification($invoice->id))->handle();

    Mail::assertSent(InvoiceApprovedNotification::class, fn (InvoiceApprovedNotification $mail) => $mail->hasTo($user->email)
        && $mail->invoice->is($invoice));

    assertNotificationEmailLog('SendInvoiceApprovedNotification: notification email sent.', [
        'notification_type' => 'approved',
        'document_type' => 'invoice',
        'document_id' => $invoice->id,
        'document_number' => $invoice->invoice_number,
        'company_id' => $invoice->company_id,
        'project_id' => $invoice->project_id,
        'recipient_user_id' => $user->id,
        'recipient_email' => $user->email,
        'requestor_id' => $user->id,
        'requestor_email' => $user->email,
    ]);
});
