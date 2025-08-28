<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user - Patrick
        User::firstOrCreate([
            'email' => 'patrick@belgaco.be',
        ], [
            'name' => 'Patrick',
            'password' => bcrypt('password'), // Change this to your preferred password
            'email_verified_at' => now(),
        ]);

        $this->command->info('Admin user created: patrick@belgaco.be');
    }
}
