<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('user:create {email} {--name=}')]
#[Description('Create a user with a random password (login via Microsoft or email/password)')]
class CreateUserCommand extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email: {$email}");

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("User already exists: {$email}");

            return self::FAILURE;
        }

        $password = Str::random(24);

        $user = User::create([
            'name' => $this->option('name') ?: Str::before($email, '@'),
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
        ]);

        $this->info("User created: {$user->email}");
        $this->line("Password: {$password}");

        return self::SUCCESS;
    }
}
