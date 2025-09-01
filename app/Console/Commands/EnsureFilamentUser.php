<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class EnsureFilamentUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'filament:ensure-user {--email=patrick@belgaco.be} {--password=password} {--name=Patrick}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ensure Filament admin user exists (recreates if deleted)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->option('email');
        $password = $this->option('password');
        $name = $this->option('name');

        // Check if user exists
        $user = User::where('email', $email)->first();
        
        if ($user) {
            $this->info("✅ Filament user already exists: {$email}");
            
            // Ensure email is verified
            if (!$user->email_verified_at) {
                $user->email_verified_at = now();
                $user->save();
                $this->info("📧 Email verification updated");
            }
            
            return Command::SUCCESS;
        }

        // Create the user
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);

        $this->info("🎉 Filament user created successfully!");
        $this->line("📧 Email: {$email}");
        $this->line("🔑 Password: {$password}");
        $this->line("👤 Name: {$name}");

        return Command::SUCCESS;
    }
}
