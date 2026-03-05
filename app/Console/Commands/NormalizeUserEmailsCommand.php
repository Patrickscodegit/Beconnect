<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NormalizeUserEmailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:normalize-emails {--dry-run : Show changes without writing to database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize existing user emails to lowercase/trimmed values';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $users = User::query()
            ->select(['id', 'email'])
            ->orderBy('id')
            ->get()
            ->filter(function (User $user): bool {
                $normalized = Str::lower(trim((string) $user->email));

                return $normalized !== (string) $user->email;
            })
            ->values();

        if ($users->isEmpty()) {
            $this->info('All user emails are already normalized.');

            return Command::SUCCESS;
        }

        $this->info(sprintf(
            '%d user email(s) require normalization.%s',
            $users->count(),
            $dryRun ? ' (dry run)' : ''
        ));

        $rows = $users->take(20)->map(function (User $user): array {
            return [
                (string) $user->id,
                (string) $user->email,
                Str::lower(trim((string) $user->email)),
            ];
        })->all();

        $this->table(['User ID', 'Current Email', 'Normalized Email'], $rows);

        if ($users->count() > 20) {
            $this->line('... output truncated to first 20 users.');
        }

        if ($dryRun) {
            $this->line('Dry run complete. Re-run without --dry-run to apply changes.');

            return Command::SUCCESS;
        }

        DB::transaction(function () use ($users): void {
            foreach ($users as $user) {
                $user->forceFill([
                    'email' => Str::lower(trim((string) $user->email)),
                ])->save();
            }
        });

        $this->info(sprintf('Normalized %d user email(s).', $users->count()));

        return Command::SUCCESS;
    }
}
