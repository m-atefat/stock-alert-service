<?php

namespace App\Services;

use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function register(string $name, string $email, string $password): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }

    public function attempt(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            throw new InvalidCredentialsException;
        }

        $matches = Hash::check($password, $user->password);

        if (! $matches) {
            throw new InvalidCredentialsException;
        }

        return $user;
    }
}
