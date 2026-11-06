<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;

/** Where a landlord's online rent is paid out: their bank account, as a Chapa sub-account. */
class PayoutAccount extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['bank_code', 'bank_name', 'account_name', 'account_number', 'chapa_subaccount_id', 'status', 'error'];

    protected $hidden = ['account_number'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted'];
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && filled($this->chapa_subaccount_id);
    }

    public function maskedNumber(): string
    {
        $n = (string) $this->account_number;

        return strlen($n) > 4 ? str_repeat('•', strlen($n) - 4).substr($n, -4) : $n;
    }
}
