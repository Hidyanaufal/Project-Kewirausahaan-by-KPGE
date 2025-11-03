<?php

namespace App\Imports;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class UserImport implements ToModel, WithHeadingRow, WithChunkReading, ShouldQueue
{
    protected int $companyId;

    public function __construct(int $companyId)
    {
        $this->companyId = $companyId;
    }

    public function chunkSize(): int
    {
        return 200;
    }

    public function model(array $row)
    {
        Log::info("User Import - Processing row", $row);

        if (empty($row['email']) || empty($row['name'])) {
            Log::warning("User Import - Skipped row (missing email or name)", $row);
            return null;
        }

        // Cek apakah user sudah ada berdasarkan email
        $existingUser = User::where('company_id', $this->companyId)
            ->where('email', $row['email'])
            ->first();

        if ($existingUser) {
            Log::info("User Import - Skipped, user already exists: {$row['email']}");
            return null;
        }

        // Buat password (pakai yang di Excel atau random)
        $password = !empty($row['password'])
            ? Hash::make($row['password'])
            : Hash::make(Str::random(10));

        $user = User::create([
            'company_id' => $this->companyId,
            'name' => $row['name'],
            'email' => $row['email'],
            'password' => $password,
            'is_super_admin' => filter_var($row['is_super_admin'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'current_company_id' => $this->companyId,
        ]);

        Log::info("User Import - User created: {$user->email}");

        // Assign role jika ada di Excel
        if (!empty($row['role'])) {
            try {
                $role = Role::where('name', $row['role'])->first();
                DB::table('model_has_roles')->insert([
                    'model_type' => User::class,
                    'model_id' => $user->id,
                    'role_id' => $role->id,
                    'company_id' => $this->companyId
                ]);
                Log::info("User Import - Role '{$row['role']}' assigned to {$user->email}");
            } catch (\Throwable $e) {
                Log::error("User Import - Failed to assign role '{$row['role']}' to {$user->email}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Assign akses ke seluruh company by default
        $companies = Company::query()
            ->withoutGlobalScopes()
            ->get();
        foreach ($companies as $company) {
            DB::table('company_user')->insert([
                'company_id' => $company->id,
                'user_id' => $user->id,
            ]);
        }

        return $user;
    }
}
