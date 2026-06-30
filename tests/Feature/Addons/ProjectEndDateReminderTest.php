<?php

use App\Enums\Addons\ProjectStatus;
use App\Enums\Addons\RoleDefault;
use App\Jobs\SendProjectEndDateReminderNotification;
use App\Jobs\SendProjectEndDateRemindersJob;
use App\Mail\ProjectEndDateReminderNotification;
use App\Models\Addons\Project;
use App\Models\Addons\ProjectType;
use App\Models\Common\Client;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    /** @var \Tests\TestCase $this */
    $this->withOfferings();
    Mail::fake();
    Carbon::setTestNow(Carbon::create(2026, 6, 22, 7, 0, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

function assignAdminRoleForReminderTests(int $companyId, User $user): void
{
    $role = Role::withoutGlobalScopes()->create([
        'name' => RoleDefault::Admin->value,
        'guard_name' => 'web',
        'company_id' => $companyId,
    ]);

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => User::class,
        'model_id' => $user->id,
        'company_id' => $companyId,
    ]);
}

function createApprovedProjectForReminderTests(
    int $companyId,
    int $picId,
    User $requestor,
    ?Carbon $endDate = null,
): Project {
    $projectType = ProjectType::query()->firstOrCreate([
        'company_id' => $companyId,
        'name' => 'Pengadaan',
    ], [
        'code' => 'Jasa',
    ]);

    $client = Client::query()
        ->where('company_id', $companyId)
        ->first()
        ?? Client::factory()->create([
            'company_id' => $companyId,
        ]);

    return Project::query()->create([
        'company_id' => $companyId,
        'project_type_id' => $projectType->id,
        'client_id' => $client->id,
        'pic_id' => $picId,
        'requestor_id' => $requestor->id,
        'number' => 'Jasa26-REM',
        'name' => 'Reminder Test Project',
        'status' => ProjectStatus::Approved,
        'end_date' => $endDate,
    ]);
}

test('end date reminder job dispatches notification for projects that ended yesterday', function () {
    /** @var \Tests\TestCase $this */
    Queue::fake();

    $requestor = User::factory()->create([
        'email' => 'requestor-reminder@example.com',
    ]);

    $project = createApprovedProjectForReminderTests(
        $this->testCompany->id,
        $this->testUser->id,
        $requestor,
        company_today()->subDay(),
    );

    (new SendProjectEndDateRemindersJob)->handle();

    Queue::assertPushed(
        SendProjectEndDateReminderNotification::class,
        fn (SendProjectEndDateReminderNotification $job) => $job->projectId === $project->id
    );
});

test('end date reminder job does not dispatch for projects ending today', function () {
    /** @var \Tests\TestCase $this */
    Queue::fake();

    $requestor = User::factory()->create([
        'email' => 'requestor-today@example.com',
    ]);

    createApprovedProjectForReminderTests(
        $this->testCompany->id,
        $this->testUser->id,
        $requestor,
        company_today(),
    );

    (new SendProjectEndDateRemindersJob)->handle();

    Queue::assertNotPushed(SendProjectEndDateReminderNotification::class);
});

test('end date reminder sends email to requestor and admin', function () {
    /** @var \Tests\TestCase $this */
    $requestor = User::factory()->create([
        'email' => 'requestor-reminder-send@example.com',
    ]);

    $admin = User::factory()->create([
        'email' => 'admin-reminder@example.com',
    ]);

    assignAdminRoleForReminderTests($this->testCompany->id, $admin);

    $project = createApprovedProjectForReminderTests(
        $this->testCompany->id,
        $this->testUser->id,
        $requestor,
        company_today()->subDay(),
    );

    (new SendProjectEndDateReminderNotification($project->id))->handle(app(\App\Services\NotificationRecipientService::class));

    Mail::assertSent(ProjectEndDateReminderNotification::class, 2);
    Mail::assertSent(
        ProjectEndDateReminderNotification::class,
        fn (ProjectEndDateReminderNotification $mail) => $mail->hasTo($requestor->email)
            && $mail->project->is($project)
    );
    Mail::assertSent(
        ProjectEndDateReminderNotification::class,
        fn (ProjectEndDateReminderNotification $mail) => $mail->hasTo($admin->email)
            && $mail->project->is($project)
    );

    expect($project->fresh()->end_date_reminder_sent_at)->not->toBeNull();
});

test('end date reminder is not sent twice', function () {
    /** @var \Tests\TestCase $this */
    $requestor = User::factory()->create([
        'email' => 'requestor-once@example.com',
    ]);

    $project = createApprovedProjectForReminderTests(
        $this->testCompany->id,
        $this->testUser->id,
        $requestor,
        company_today()->subDay(),
    );

    $project->update([
        'end_date_reminder_sent_at' => company_now(),
    ]);

    (new SendProjectEndDateReminderNotification($project->id))->handle(app(\App\Services\NotificationRecipientService::class));

    Mail::assertNothingSent();
});

test('end date reminder deduplicates requestor who is also admin', function () {
    /** @var \Tests\TestCase $this */
    $requestor = User::factory()->create([
        'email' => 'requestor-admin@example.com',
    ]);

    assignAdminRoleForReminderTests($this->testCompany->id, $requestor);

    $project = createApprovedProjectForReminderTests(
        $this->testCompany->id,
        $this->testUser->id,
        $requestor,
        company_today()->subDay(),
    );

    (new SendProjectEndDateReminderNotification($project->id))->handle(app(\App\Services\NotificationRecipientService::class));

    Mail::assertSent(ProjectEndDateReminderNotification::class, 1);
    Mail::assertSent(
        ProjectEndDateReminderNotification::class,
        fn (ProjectEndDateReminderNotification $mail) => $mail->hasTo($requestor->email)
    );
});
