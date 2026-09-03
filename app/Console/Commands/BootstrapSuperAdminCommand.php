<?php

namespace App\Console\Commands;

use App\Actions\Administration\BootstrapSuperAdmin;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class BootstrapSuperAdminCommand extends Command
{
    protected $signature = 'admin:bootstrap-super-admin
        {email : Email address of an existing verified user}
        {--reason= : Operational reason recorded in the platform audit log}';

    protected $description = 'Promote the first verified Procura super administrator';

    public function handle(BootstrapSuperAdmin $bootstrap): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error('No user exists with that email address.');

            return self::FAILURE;
        }

        try {
            $bootstrap->execute($user, (string) $this->option('reason'));
        } catch (InvalidArgumentException|LogicException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s is now the first Procura super administrator.',
            $user->email,
        ));

        return self::SUCCESS;
    }
}
