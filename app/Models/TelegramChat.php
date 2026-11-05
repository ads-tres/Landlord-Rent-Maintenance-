<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramChat extends Model
{
    protected $primaryKey = 'chat_id';

    public $incrementing = false;

    protected $fillable = ['chat_id', 'state', 'state_data', 'state_expires_at', 'locale'];

    protected function casts(): array
    {
        return ['state_data' => 'array', 'state_expires_at' => 'datetime'];
    }

    public static function for(int $chatId): self
    {
        return static::query()->firstOrCreate(['chat_id' => $chatId]);
    }

    public function currentState(): ?string
    {
        return $this->state && $this->state_expires_at?->isFuture() ? $this->state : null;
    }

    public function setState(?string $state, array $data = [], int $minutes = 30): void
    {
        $this->update([
            'state' => $state,
            'state_data' => $state ? $data : null,
            'state_expires_at' => $state ? now()->addMinutes($minutes) : null,
        ]);
    }
}
