<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

class CreateFuudgoAdmin extends Command
{
    protected $signature = 'fuudgo:admin';
    protected $description = 'Create the first or an additional FuudGo administrator account';

    public function handle(): int
    {
        $name = trim((string) $this->ask('Administrator name'));
        $email = strtolower(trim((string) $this->ask('Administrator email')));
        if (User::where('email', $email)->exists()) {
            $this->components->error('That email is already assigned to an account.');
            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (at least 12 characters, mixed case and a number)');
        $confirmation = (string) $this->secret('Confirm password');
        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            ['name' => ['required', 'string', 'min:2', 'max:120'], 'email' => ['required', 'email', 'max:254'], 'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }
            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => 'admin', 'email_verified_at' => now()])->save();
        $this->components->info("Administrator account created for {$email}. No password was displayed or stored in plaintext.");

        return self::SUCCESS;
    }
}
