<?php

use App\Actions\FilamentCompanies\InviteCompanyEmployee;
use App\Mail\CompanyEmployeeInvitationNotification;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    /** @var \Tests\TestCase $this */
    Mail::fake();
});

test('company invitation email includes company name in subject and from', function () {
    /** @var \Tests\TestCase $this */
    $user = $this->testUser;
    $company = $this->testCompany;

    $email = 'invitee@example.com';

    (new InviteCompanyEmployee())->invite($user, $company, $email, role: 'admin');

    Mail::assertSent(CompanyEmployeeInvitationNotification::class, function (CompanyEmployeeInvitationNotification $mail) use ($company, $email) {
        $envelope = $mail->envelope();

        return $mail->hasTo($email)
            && str_contains($envelope->subject, '[' . $company->name . ']')
            && ($envelope->from?->name === $company->name);
    });
});

