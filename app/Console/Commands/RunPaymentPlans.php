<?php

namespace App\Console\Commands;

use App\Services\Arrears\ArrearsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('arrears:plans')]
#[Description('Remind tenants of payment plan instalments due tomorrow; close completed and broken plans')]
class RunPaymentPlans extends Command
{
    public function handle(ArrearsService $arrears): int
    {
        $c = $arrears->runPlans();
        $this->info("Reminded {$c['reminded']}, completed {$c['completed']}, broken {$c['broken']}.");

        return self::SUCCESS;
    }
}
