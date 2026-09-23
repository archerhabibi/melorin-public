<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ServerPanel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'panel_type', 'host', 'port', 'credentials', 'status',
        'capacity', 'account_limit_per_user', 'health_status',
        'active_accounts_count', 'extra_settings',
    ];

    protected $casts = [
        'credentials' => 'encrypted',
        'extra_settings' => 'array',
    ];

    public function protocols(): BelongsToMany
    {
        return $this->belongsToMany(Protocol::class, 'server_panel_protocol')
            ->withPivot('settings')->withTimestamps();
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_server_panel');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * رزروِ اتمیکِ یک واحد ظرفیت (بند ۶۵ سند v2.1 — Capacity).
     *
     * تا امروز `active_accounts_count` فقط *بعد* از ساخت موفق اکانت روی
     * پنل افزایش می‌یافت (`increment()`، خودش اتمیک است) — اما هیچ‌کجا
     * قبل از آن با `capacity` مقایسه نمی‌شد؛ یعنی مقدار «ظرفیت» که ادمین
     * برای هر پنل تنظیم می‌کرد اصلاً enforce نمی‌شد. مهم‌تر، فاصله‌ی
     * زمانیِ بین انتخابِ سرور (`select`) و افزایشِ شمارنده — که شاملِ یک
     * فراخوانی API خارجی و می‌تواند صدها میلی‌ثانیه طول بکشد — دقیقاً
     * همان پنجره‌ی Race بود: چند خرید هم‌زمان همه می‌توانستند همان یک
     * سرورِ «کم‌بارترین» را انتخاب کنند و همه هم موفق شوند، در حالی که
     * ظرفیتِ واقعی از قبل پر شده بود.
     *
     * راه‌حل: افزایشِ شمارنده به *قبل* از تماس با پنل منتقل شد (رزرو)،
     * و با یک UPDATE شرطیِ تک‌دستور انجام می‌شود — نه SELECT-بعد-UPDATE —
     * پس دو رزرو هم‌زمان روی همین ردیف سریالایز می‌شوند و هیچ‌وقت از
     * ظرفیت واقعی عبور نمی‌کنند. اگر تماس با پنل بعداً شکست بخورد،
     * `releaseCapacitySlot()` رزرو را پس می‌دهد تا آن واحد از دست نرود.
     */
    public function reserveCapacitySlot(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where(function ($q) {
                $q->whereNull('capacity')->orWhereColumn('active_accounts_count', '<', 'capacity');
            })
            ->update(['active_accounts_count' => DB::raw('active_accounts_count + 1')]) === 1;
    }

    /** بازگرداندنِ یک واحد ظرفیتِ رزروشده که مصرف نشد (ساخت اکانت شکست خورد). */
    public function releaseCapacitySlot(): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('active_accounts_count', '>', 0)
            ->decrement('active_accounts_count');
    }
}
