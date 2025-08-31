<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FilamentUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Filament admin user
        $user = User::firstOrCreate(
            ['email' => 'patrick@belgaco.be'],
            [
                'name' => 'Patrick',
                'email' => 'patrick@belgaco.be',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // Ensure email is verified
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        $this->command->info('Filament user ready: patrick@belgaco.be (password: password)');
    }
}
