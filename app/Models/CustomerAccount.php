<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * عضویت یک Identity (User) در یک فروشگاه مشخص — بند ۵ بلوپرینت.
 *
 * تفاوت کلیدی با معماری قبلی:
 *     User            = این شخص کیست
 *     CustomerAccount = این شخص در کدام فروشگاه، چه مشتری‌ای است
 *
 * یک نفر می‌تواند هم‌زمان مشتری فروشگاه اصلی و چند نماینده باشد، و هر
 * عضویت کیف‌پول، سفارش، اکانت و کمیسیون کاملاً جدا دارد. این‌ها هرگز
 * merge نمی‌شوند (بند ۳۶) — حتی وقتی سیستم مطمئن است پشت هر سه یک آدم
 * است. دلیل: پولی که مشتری به نماینده‌ی A داده، به هیچ عنوان نباید در
 * فروشگاه نماینده‌ی B یا فروشگاه اصلی قابل خرج‌کردن باشد.
 */
class CustomerAccount extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'store_type', 'reseller_id', 'status', 'display_name', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        // scope_key عمداً یک ستون معمولی است، نه Generated Column
        // (دلیل فنی‌اش در migration مربوطه توضیح داده شده). پس هر مسیری
        // که از طریق مدل رکورد می‌سازد یا store_type/reseller_id را
        // عوض می‌کند، اینجا مقدارش خودکار هم‌گام نگه داشته می‌شود. هر
        // insert خامِ DB::table('customer_accounts') از این رویداد رد
        // نمی‌شود و باید scope_key را خودش صریحاً بسازد.
        static::saving(function (self $account): void {
            $account->scope_key = $account->store_type === 'reseller'
                ? 'reseller:'.$account->reseller_id
                : 'main';
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /**
     * Wallet این عضویت: (user_id، scope_key). scope_key به هر نمونه وابسته
     * است، پس این رابطه فقط برای lazy-load مناسب است (نه eager-load).
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'user_id', 'user_id')->where('scope_key', $this->scope_key);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isMainStore(): bool
    {
        return $this->store_type === 'main';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** مهمان هنوز به هیچ Identity ثبت‌شده‌ای وصل نشده (بند ۳۵) */
    public function isGuest(): bool
    {
        return is_null($this->user_id);
    }

    public function scopeForStore(Builder $query, string $storeType, ?int $resellerId = null): Builder
    {
        $query->where('store_type', $storeType);

        return $resellerId
            ? $query->where('reseller_id', $resellerId)
            : $query->whereNull('reseller_id');
    }
}
