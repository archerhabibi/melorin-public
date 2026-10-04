<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = ['user_id', 'reseller_id', 'type', 'subject', 'status', 'priority'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** null = فروشگاه اصلی (همه‌ی تیکت‌های ثبت‌شده از ربات) */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    // برای ستون «آخرین پیام» در جدول TicketResource؛ latestOfMany به‌جای
    // messages()->latest() تا در Filament بشود روی آن eager-load/sort کرد.
    public function latestMessage(): HasOne
    {
        return $this->hasOne(TicketMessage::class)->latestOfMany();
    }

    public function isOpen(): bool
    {
        return $this->status !== 'closed';
    }

    /** @return array<string, string> وضعیت → برچسب مشتری‌پسند */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_OPEN => 'در انتظار پاسخ پشتیبانی',
            self::STATUS_ANSWERED => 'پاسخ داده شد',
            self::STATUS_CLOSED => 'بسته شده',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    public function typeLabel(): string
    {
        return $this->type === 'reseller_request' ? 'درخواست نمایندگی' : 'پشتیبانی';
    }

    /** @return 'success'|'warning'|'info'|'neutral' */
    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_ANSWERED => 'success',
            self::STATUS_OPEN => 'info',
            default => 'neutral',
        };
    }
}
