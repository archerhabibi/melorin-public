<?php

namespace App\Models;

use App\Services\Resellers\ResellerService;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'telegram_id', 'phone', 'username_site', 'email', 'password', 'full_name',
        'status', 'referrer_id', 'joined_from', 'reseller_id',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function wallet(): MorphOne
    {
        return $this->morphOne(Wallet::class, 'owner');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referredUsers(): HasMany
    {
        return $this->hasMany(User::class, 'referrer_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function resellerAccount(): HasOne
    {
        // if this user IS a reseller (owns a reseller record)
        return $this->hasOne(Reseller::class);
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

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function commissionsEarned(): HasMany
    {
        return $this->hasMany(Commission::class, 'referrer_id');
    }

    /**
     * آیا این کاربر همان کسی است که در ربات تلگرام با فرستادن «ادمین»
     * به منوی مدیریت دسترسی دارد (بند ۳.۱ سند نیازمندی)؟ این یک فلگ
     * دیتابیسی نیست — منبعِ حقیقتش config('telegram.admin_ids') است
     * (UpdateRouter::handleAdminCommand همین‌جا را چک می‌کند)، پس این
     * accessor فقط همان چک را در دسترس UserResource هم قرار می‌دهد تا
     * این کاربران در پنل وب از مشتریان عادی قابل‌تفکیک باشند — بدون
     * migration و بدون دو منبع حقیقتِ ناهماهنگ.
     */
    public function isBotAdmin(): bool
    {
        return in_array((string) $this->telegram_id, config('telegram.admin_ids', []), true);
    }

    /**
     * پنل نماینده (guard: reseller) فقط برای کسی باز است که واقعاً
     * owner/admin حداقل یک Reseller باشد — امنیت اینجا فقط UI نیست،
     * چون panel->authMiddleware همین متد را قبل از رندر هر صفحه چک
     * می‌کند (طبق «اصل طلایی امنیت»، بند ۲ سند نیازمندی Reseller).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'reseller') {
            return true;
        }

        return ResellerAdmin::query()->where('user_id', $this->id)->exists();
    }

    /**
     * طبق Filament Multi-Tenancy: «Tenant» همان Reseller است — همین‌جا
     * است که URL پنل بر اساس resellers.slug ساخته می‌شود (درخواست
     * صریح: /parismobile به‌جای یک مسیر ثابت مشترک برای همه). چون R3
     * (چند Manager روی یک Reseller) هنوز فعال نیست، هر owner دقیقاً یک
     * Tenant دارد؛ ساختار HasTenants همان چیزی است که بعداً افزودن چند
     * Reseller برای یک owner (اگر لازم شد) را بدون تغییر معماری ممکن می‌کند.
     */
    public function getTenants(Panel $panel): Collection
    {
        return Reseller::query()
            ->whereIn('id', ResellerAdmin::query()->where('user_id', $this->id)->pluck('reseller_id'))
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Reseller) {
            return false;
        }

        return app(ResellerService::class)->isAdminOf($tenant, $this);
    }
}
