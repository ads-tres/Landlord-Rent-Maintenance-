<?php

namespace App\Services\Account;

use App\Models\Charge;
use App\Models\Expense;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\MoveOut;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RentChange;
use App\Models\RentRefund;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use RuntimeException;
use ZipArchive;

/**
 * Everything a landlord has in the app, as CSV files in one ZIP they can open in Excel.
 * Amounts are in birr. Runs as the landlord, so the usual scoping applies.
 */
class AccountExport
{
    public function write(User $landlord): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export file.');
        }
        $birr = fn ($santim) => number_format(((int) $santim) / 100, 2, '.', '');
        $date = fn ($d) => $d?->format('Y-m-d');

        $files = [
            'account.csv' => [['name', 'phone', 'address', 'language', 'calendar'], [[$landlord->name, $landlord->phone, $landlord->address, $landlord->locale, $landlord->calendar_pref]]],
            'properties.csv' => [['id', 'name', 'sub_city', 'woreda', 'address'], Property::query()->orderBy('id')->get()->map(fn ($p) => [$p->id, $p->name, $p->sub_city, $p->woreda, $p->address])],
            'units.csv' => [['id', 'property_id', 'label', 'bedrooms', 'status'], Unit::query()->orderBy('id')->get()->map(fn ($u) => [$u->id, $u->property_id, $u->label, $u->bedrooms, $u->status])],
            'tenants.csv' => [['id', 'full_name', 'phone', 'id_number', 'address', 'notes'], Tenant::query()->orderBy('id')->get()->map(fn ($t) => [$t->id, $t->full_name, $t->phone, $t->id_number, $t->address, $t->notes])],
            'leases.csv' => [['id', 'unit_id', 'tenant_id', 'start_date', 'end_date', 'terminated_on', 'monthly_rent', 'deposit', 'due_day', 'status', 'registered_at', 'registration_number'],
                Lease::query()->orderBy('id')->get()->map(fn ($l) => [$l->id, $l->unit_id, $l->tenant_id, $date($l->start_date), $date($l->end_date), $date($l->terminated_on), $birr($l->monthly_rent), $birr($l->deposit), $l->due_day, $l->status, $date($l->registered_at), $l->registration_number])],
            'rent_changes.csv' => [['id', 'lease_id', 'old_rent', 'new_rent', 'effective_date', 'cap_percent', 'notice_days', 'tenant_agreed'],
                RentChange::query()->orderBy('id')->get()->map(fn ($c) => [$c->id, $c->lease_id, $birr($c->old_rent), $birr($c->new_rent), $date($c->effective_date), $c->cap_percent, $c->notice_days, $c->tenant_agreed ? 'yes' : 'no'])],
            'charges.csv' => [['id', 'lease_id', 'period', 'type', 'amount_due', 'due_date', 'status'], Charge::query()->orderBy('id')->get()->map(fn ($c) => [$c->id, $c->lease_id, $c->period, $c->type, $birr($c->amount_due), $date($c->due_date), $c->status])],
            'payments.csv' => [['id', 'receipt', 'lease_id', 'tenant_id', 'paid_at', 'amount', 'method', 'reference', 'notes'],
                Payment::query()->orderBy('id')->get()->map(fn ($p) => [$p->id, $p->receiptNumber(), $p->lease_id, $p->tenant_id, $date($p->paid_at), $birr($p->amount), $p->method, $p->reference, $p->notes])],
            'refunds.csv' => [['id', 'lease_id', 'refunded_on', 'amount', 'reason'], RentRefund::query()->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->lease_id, $date($r->refunded_on), $birr($r->amount), $r->reason])],
            'expenses.csv' => [['id', 'property_id', 'unit_id', 'spent_on', 'category', 'amount', 'description', 'vendor'],
                Expense::query()->orderBy('id')->get()->map(fn ($e) => [$e->id, $e->property_id, $e->unit_id, $date($e->spent_on), $e->category, $birr($e->amount), $e->description, $e->vendor])],
            'repairs.csv' => [['id', 'unit_id', 'title', 'category', 'status', 'opened_at', 'closed_at', 'cost', 'resolution_note'],
                MaintenanceRequest::query()->orderBy('id')->get()->map(fn ($m) => [$m->id, $m->unit_id, $m->title, $m->category, $m->status, $m->opened_at?->toDateTimeString(), $m->closed_at?->toDateTimeString(), $m->cost ? $birr($m->cost) : null, $m->resolution_note])],
            'move_outs.csv' => [['id', 'lease_id', 'moved_out_on', 'status', 'deposit_held', 'deposit_applied', 'deposit_kept', 'refund_amount', 'balance_owed'],
                MoveOut::query()->orderBy('id')->get()->map(fn ($m) => [$m->id, $m->lease_id, $date($m->moved_out_on), $m->status, $birr($m->deposit_held), $birr($m->deposit_applied), $birr($m->deposit_kept), $birr($m->refund_amount), $birr($m->balance_owed)])],
        ];

        foreach ($files as $name => [$header, $rows]) {
            $zip->addFromString($name, $this->csv($header, $rows));
        }
        $zip->addFromString('README.txt', __('account.export_readme', ['app' => config('app.name'), 'date' => now()->toDateString()], $landlord->locale ?: 'en'));
        $zip->close();

        return $path;
    }

    /** UTF-8 with a BOM so Excel shows Amharic correctly. */
    private function csv(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => $v === null ? '' : (string) $v, $row));
        }
        rewind($out);

        return stream_get_contents($out);
    }
}
