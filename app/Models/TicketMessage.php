<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    protected $fillable = ['ticket_id', 'sender_type', 'sender_id', 'message', 'attachment'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * sender_id بسته به sender_type به دو جدول متفاوت (users یا admins)
     * اشاره می‌کند، پس رابطه‌ی Eloquent معمولی جواب نمی‌دهد. این فقط
     * برای نمایش نام فرستنده در پنل استفاده می‌شود.
     */
    public function senderLabel(): string
    {
        if ($this->sender_type === 'admin') {
            return Admin::find($this->sender_id)?->name ?? 'پشتیبانی';
        }

        return $this->ticket?->user?->full_name ?? 'کاربر';
    }

    /**
     * برچسب فرستنده برای **مشتری** (B3.4): نام واقعی ادمین هرگز به مشتری نشان داده نمی‌شود
     * (`senderLabel()` فقط برای پنل ادمین است).
     */
    public function customerLabel(): string
    {
        return $this->sender_type === 'admin' ? 'پشتیبانی' : 'شما';
    }
}
