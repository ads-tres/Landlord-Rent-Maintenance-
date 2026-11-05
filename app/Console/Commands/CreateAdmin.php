<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('admin:create {phone : Ethiopian mobile number} {--name= : Display name}')]
#[Description('Create (or promote) an admin who can log in to /admin with an SMS code')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $phone = PhoneNumber::normalize((string) $this->argument('phone'));
        if (! $phone) {
            $this->error('Enter an Ethiopian mobile number, e.g. 0911234567.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['phone' => $phone]);
        if ($user->exists && $user->role === User::ROLE_LANDLORD && $user->properties()->withoutGlobalScopes()->exists()) {
            $this->error('This number belongs to a landlord with data. Use a separate number for the admin.');

            return self::FAILURE;
        }

        $user->fill(['role' => User::ROLE_ADMIN, 'name' => $this->option('name') ?: ($user->name ?: 'Admin')])->save();
        $this->info("Admin ready: {$phone}. Log in at ".url('/admin'));

        return self::SUCCESS;
    }
}
