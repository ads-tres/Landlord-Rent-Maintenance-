<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Account\AccountDeletion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('accounts:purge')]
#[Description('Delete landlord accounts whose deletion was requested more than 30 days ago')]
class PurgeDeletedAccounts extends Command
{
    public function handle(AccountDeletion $deletion): int
    {
        $count = 0;
        User::query()
            ->where('role', User::ROLE_LANDLORD)
            ->whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<=', now()->subDays(AccountDeletion::GRACE_DAYS))
            ->get()
            ->each(function (User $landlord) use ($deletion, &$count) {
                $deletion->purge($landlord);
                $count++;
            });

        $this->info("Deleted {$count} account(s).");

        return self::SUCCESS;
    }
}
