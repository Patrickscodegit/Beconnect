<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DiagnoseUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:diagnose {email} {--password=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose why a user cannot login';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $inputEmail = trim((string) $this->argument('email'));
        $normalizedEmail = Str::lower($inputEmail);
        $providedPassword = $this->option('password');

        $exactUser = User::query()->where('email', $inputEmail)->first();
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();

        if (! $user) {
            $this->error("No user found for email: {$inputEmail}");
            $this->line('Checked exact and case-insensitive email lookup.');

            return Command::FAILURE;
        }

        $hashInfo = password_get_info((string) $user->password);
        $isRecognizedHash = ($hashInfo['algo'] ?? 0) !== 0;

        $this->info('User diagnostic report');
        $this->table(
            ['Field', 'Value'],
            [
                ['User ID', (string) $user->id],
                ['Name', (string) $user->name],
                ['Stored email', (string) $user->email],
                ['Input email', $inputEmail],
                ['Normalized input', $normalizedEmail],
                ['Role', (string) $user->role],
                ['Status', (string) $user->status],
                ['Email verified', $user->email_verified_at ? 'yes' : 'no'],
                ['Exact email match', $exactUser ? 'yes' : 'no'],
                ['Hash recognized', $isRecognizedHash ? 'yes' : 'no'],
                ['Hash algorithm', (string) ($hashInfo['algoName'] ?? 'unknown')],
                ['Hash length', (string) strlen((string) $user->password)],
            ]
        );

        if ($providedPassword !== null) {
            $passwordMatches = Hash::check((string) $providedPassword, (string) $user->password);
            $this->line('Password check (--password): '.($passwordMatches ? 'MATCH' : 'NO MATCH'));
        } else {
            $this->line('Password check skipped (pass --password to verify credentials).');
        }

        if ((string) $user->email !== Str::lower((string) $user->email)) {
            $this->warn('Stored email is not lowercase. Consider normalizing to avoid PostgreSQL case-sensitivity issues.');
        }

        if ((string) $user->status !== 'active') {
            $this->warn("User status is '{$user->status}'. Login is blocked until status is 'active'.");
        }

        return Command::SUCCESS;
    }
}
