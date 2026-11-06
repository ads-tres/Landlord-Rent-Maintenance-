<?php

namespace App\Console\Commands;

use App\Models\ChapaTransaction;
use App\Services\Chapa\OnlineRent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Re-checks pending Chapa payments in case a webhook was missed; gives up after a day. */
#[Signature('chapa:reconcile')]
#[Description('Verify pending Chapa transactions with Chapa')]
class ChapaReconcile extends Command
{
    public function handle(OnlineRent $online): int
    {
        $confirmed = 0;

        ChapaTransaction::query()->withoutGlobalScopes()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(10))
            ->each(function (ChapaTransaction $tx) use ($online, &$confirmed) {
                $result = $online->confirm($tx->tx_ref);
                if ($result?->status === 'success') {
                    $confirmed++;
                } elseif ($result?->status === 'pending' && $tx->created_at->lt(now()->subDay())) {
                    $tx->update(['status' => 'failed']);
                }
            });

        $this->info("Confirmed {$confirmed} payment(s).");

        return self::SUCCESS;
    }
}
