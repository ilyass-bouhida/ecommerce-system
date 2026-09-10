<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EmailNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateOwnerCommand extends Command
{
    protected $signature = 'app:create-owner';

    protected $description = 'Interactively create the initial OWNER account';

    public function handle(): int
    {
        $name = trim((string) $this->ask('Name'));
        $email = EmailNormalizer::normalize((string) $this->ask('Email'));
        $password = (string) $this->secret('Password');
        $confirm = (string) $this->secret('Password confirmation');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => User::ROLE_OWNER,
            'is_active' => true,
        ]);

        $this->info("OWNER created: {$user->email}");

        return self::SUCCESS;
    }
}
