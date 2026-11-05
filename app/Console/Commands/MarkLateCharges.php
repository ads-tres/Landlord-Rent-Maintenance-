<?php

namespace App\Console\Commands;

use App\Models\Charge;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Daily: unpaid or part-paid charges whose due date has passed become late. */
#[Signature('rent:mark-late')]
#[Description('Mark unpaid charges past their due date as late')]
class MarkLateCharges extends Command
{
    public function handle(): int
    {
        $count = Charge::query()
            ->withoutGlobalScopes()
            ->whereIn('status', [Charge::STATUS_DUE, Charge::STATUS_PARTIAL])
            ->whereDate('due_date', '<', today())
            ->update(['status' => Charge::STATUS_LATE, 'updated_at' => now()]);

        $this->info("Marked {$count} charge(s) late.");

        return self::SUCCESS;
    }
}
