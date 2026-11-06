<?php

namespace App\Services\Expenses;

use App\Models\AssetService;
use App\Models\Expense;
use App\Models\MaintenanceRequest;
use App\Models\UtilityBill;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps automatic expenses in step with what caused them: a repair's cost, an equipment
 * service's cost, and the vacant units' share of a shared meter bill. One expense per source;
 * a zero cost removes it.
 */
class ExpenseRecorder
{
    public function fromRepair(MaintenanceRequest $repair): void
    {
        $repair->loadMissing('unit');
        $this->sync($repair, (int) $repair->cost, [
            'property_id' => $repair->unit->property_id,
            'unit_id' => $repair->unit_id,
            'category' => 'repair',
            'spent_on' => ($repair->closed_at ?? now())->toDateString(),
            'description' => mb_strimwidth($repair->title, 0, 250, '…'),
        ], $repair->landlord_id);
    }

    public function fromService(AssetService $service): void
    {
        $asset = $service->asset()->withoutGlobalScopes()->first();
        $this->sync($service, (int) $service->cost, [
            'property_id' => $asset->property_id,
            'category' => 'maintenance',
            'spent_on' => $service->serviced_on->toDateString(),
            'description' => mb_strimwidth($asset->name.($service->notes ? ': '.$service->notes : ''), 0, 250, '…'),
        ], $service->landlord_id);
    }

    public function fromUtilityBill(UtilityBill $bill): void
    {
        $bill->loadMissing('meter');
        $this->sync($bill, $bill->landlord_share, [
            'property_id' => $bill->meter->property_id,
            'category' => 'utility',
            'spent_on' => $bill->due_date->toDateString(),
            'description' => mb_strimwidth($bill->meter->name.' ('.$bill->period.')', 0, 250, '…'),
        ], $bill->landlord_id);
    }

    private function sync(Model $source, int $amount, array $fields, int $landlordId): void
    {
        $existing = Expense::query()->withoutGlobalScopes()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->first();

        if ($amount <= 0) {
            $existing?->delete();

            return;
        }

        $expense = $existing ?? new Expense(['source_type' => $source->getMorphClass(), 'source_id' => $source->getKey()]);
        $expense->fill($fields + ['amount' => $amount]);
        $expense->landlord_id = $landlordId;
        $expense->save();
    }
}
