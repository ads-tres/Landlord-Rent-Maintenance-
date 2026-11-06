<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SmsMessage extends Model
{
    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_TELEGRAM = 'telegram';

    public const KIND_RECEIPT = 'receipt';

    public const KIND_NEW_REPAIR = 'new_repair';

    public const KIND_UTILITY_BILL = 'utility_bill';

    public const KIND_ANNOUNCEMENT = 'announcement';

    public const KIND_LISTING = 'listing';

    public const KIND_REMINDER_BEFORE = 'reminder_before';

    public const KIND_REMINDER_DUE = 'reminder_due';

    public const KIND_REPAIR_UPDATE = 'repair_update';

    public const KIND_REPAIR_LINK = 'repair_link';

    protected $fillable = ['landlord_id', 'to', 'channel', 'kind', 'related_type', 'related_id', 'body', 'segments', 'status', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'segments' => 'integer'];
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * SMS segments: 160 characters in GSM-7, 70 in Unicode (Amharic),
     * or 153 / 67 per part once a message is split.
     */
    public static function segmentsFor(string $body): int
    {
        $unicode = (bool) preg_match('/[^\x00-\x7F]/u', $body);
        $length = mb_strlen($body);
        [$single, $multi] = $unicode ? [70, 67] : [160, 153];

        return $length <= $single ? 1 : (int) ceil($length / $multi);
    }
}
