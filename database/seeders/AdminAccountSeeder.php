<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class AdminAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('The requested development admin account may only be seeded locally or in tests.');
        }

        DB::transaction(function () {
            $existing = User::where('username', 'admin')->orWhere('email', 'admin-login@analista.test')->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                $account = $existing->first();
                if ($existing->count() !== 1 || $account->username !== 'admin'
                    || $account->email !== 'admin-login@analista.test' || $account->role !== 'admin') {
                    throw new LogicException('The admin login identifier belongs to an existing account. No accounts were changed.');
                }

                return;
            }

            User::create([
                'name' => 'Admin',
                'username' => 'admin',
                'email' => 'admin-login@analista.test',
                'password' => 'Admin',
                'role' => 'admin',
                'email_verified_at' => now(),
                'is_suspended' => false,
                'accepting_requests' => false,
            ]);
        });
    }
}
