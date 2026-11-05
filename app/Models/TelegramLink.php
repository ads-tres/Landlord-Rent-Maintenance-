<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Not landlord-scoped: the bot works without a signed-in user and looks links up by chat.
 * API code filters by landlord_id itself.
 */
class TelegramLink extends Model
{
    protected $fillable = ['landlord_id', 'linkable_type', 'linkable_id', 'chat_id', 'telegram_user_id', 'locale', 'consented_at'];

    protected function casts(): array
    {
        return ['consented_at' => 'datetime', 'chat_id' => 'integer', 'telegram_user_id' => 'integer'];
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Messages go to Telegram only once the person has agreed (landlords agree by linking). */
    public function isActive(): bool
    {
        return $this->consented_at !== null;
    }

    public static function activeFor(Model $model): ?self
    {
        return static::query()
            ->where('linkable_type', $model->getMorphClass())
            ->where('linkable_id', $model->getKey())
            ->whereNotNull('consented_at')
            ->first();
    }
}
