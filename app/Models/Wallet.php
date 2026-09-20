<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wallet = User + StoreContext (سند معماری بند ۲۱ تا ۲۸، Rule 5).
 *
 *     user_id, store_type, reseller_id, balance
 *
 * Wallet یک موجودیت عمومی است: نه «customer_wallet» و نه «reseller_wallet».
 * Wallet صاحبِ نماینده برای پرداخت reseller_price همان Wallet او در
 * Main Context است (store_type=main, reseller_id=null).
 *
 * فقط WalletService حق ساخت و تغییر موجودی را دارد.
 *
 * ستون‌های owner_type / owner_id / customer_account_id قدیمی‌اند: nullable
 * هستند، دیگر خوانده یا نوشته نمی‌شوند و در مرحله‌ی «contract» بعدی حذف
 * می‌شوند. عمداً هنوز در $fillable مانده‌اند تا تست‌های مهاجرت‌های
 * تاریخی بتوانند ردیف قدیمی‌شکل بسازند.
 */
class Wallet extends Model
{
    protected $fillable = [
        'user_id', 'store_type', 'reseller_id', 'scope_key', 'balance',
        // legacy — فقط برای داده‌ی تاریخی
        'owner_type', 'owner_id', 'customer_account_id',
    ];

    protected $casts = ['balance' => 'decimal:2'];

    protected static function booted(): void
    {
        // scope_key عمداً یک ستون معمولی است (نه Generated Column): reseller_id
        // یک FK با nullOnDelete است و MySQL اجازه‌ی SET NULL روی پایه‌ی
        // generated column را نمی‌دهد. هر مسیری که از مدل بگذرد، خودکار
        // هم‌گام می‌شود؛ insert خام باید خودش scope_key را بسازد.
        static::saving(function (self $wallet): void {
            if ($wallet->user_id === null) {
                return; // ردیف قدیمیِ هنوز مهاجرت‌نکرده
            }

            $storeType = $wallet->store_type ?: 'main';

            if ($storeType === 'reseller' && ! $wallet->reseller_id) {
                throw new \InvalidArgumentException('Wallet در Context نماینده باید reseller_id داشته باشد.');
            }

            $wallet->store_type = $storeType;
            $wallet->scope_key = static::scopeKeyFor($storeType, $wallet->reseller_id);
        });
    }

    /** main → "main"، reseller → "reseller:{id}" */
    public static function scopeKeyFor(string $storeType, ?int $resellerId): string
    {
        return $storeType === 'reseller' ? 'reseller:'.$resellerId : 'main';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function isMain(): bool
    {
        return $this->store_type === 'main';
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
