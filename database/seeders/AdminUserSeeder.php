<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Create (or promote) the admin user from the admin config / .env.
     */
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn(
                'AdminUserSeeder ignorado: defina ADMIN_EMAIL e ADMIN_PASSWORD no .env.'
            );

            return;
        }

        // Set guarded attributes explicitly (is_admin / email_verified_at are
        // not mass-assignable) and let the model's hashed cast hash the password.
        $user = User::firstOrNew(['email' => $email]);
        $user->name = config('admin.name', 'Admin');
        $user->password = $password;
        $user->is_admin = true;
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->save();

        $this->command?->info("Usuário admin garantido: {$email}");
    }
}
