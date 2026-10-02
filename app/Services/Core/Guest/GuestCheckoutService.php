<?php

namespace App\Services\Core\Guest;

use App\Models\GuestCheckout;
use App\Models\Product;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Support\Str;

/**
 * Guest Checkout — Master 2.7 §3 (Contract نهایی).
 *
 * Guest یک نشست موقت برای *شروع* Checkout است، نه هویت و نه مجوز خرید:
 *   - G2: فقط email الزامی است؛ name/phone اختیاری.
 *   - G3: هیچ User/CustomerAccount/Wallet/Order از Guest ساخته نمی‌شود.
 *   - G7: تطابق با User موجود ⇒ فقط Audit + هدایت به Login؛ هرگز Merge/Login خودکار.
 *   - G9: pending → consumed | expired؛ حذف فیزیکی ۶۰ روز بعد (DATA-RETENTION.md).
 *
 * (مدل قدیمی «ساخت User از Guest + Auth::login» DEPRECATED است.)
 */
class GuestCheckoutService
{
    /** G9: TTL نشست (پارامتر Implementation). */
    protected const TTL_MINUTES = 45;

    /** G9 / D-4: نگهداری ردیف‌های expired و consumed پیش از حذف فیزیکی. */
    public const RETENTION_DAYS = 60;

    public function __construct(
        protected IdentityService $identity,
        protected AuditService $audit,
    ) {}

    /**
     * @throws \InvalidArgumentException اگر email خالی/نامعتبر باشد
     */
    public function start(
        Product $product,
        StoreContext $store,
        string $email,
        ?string $name = null,
        ?string $phone = null,
    ): GuestCheckout {
        $email = Str::lower(trim($email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('ایمیل مهمان الزامی و باید معتبر باشد.');
        }

        // G8: فقط همین فیلدها؛ IP/User-Agent/Device عمداً پارامتر نیستند.
        return GuestCheckout::create([
            'token' => Str::random(48),
            'product_id' => $product->id,
            'reseller_id' => $store->resellerId(),
            'guest_email' => $email,
            'guest_name' => $this->nullIfBlank($name),
            'guest_phone' => $this->nullIfBlank($phone),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }

    /**
     * توکن معتبر برای همین Context را برمی‌گرداند، یا null (G5). انقضا lazily
     * اعمال می‌شود؛ رکورد pending با expires_at گذشته → expired.
     */
    public function findActive(string $token, StoreContext $store): ?GuestCheckout
    {
        $guest = GuestCheckout::query()
            ->where('token', $token)
            ->where('reseller_id', $store->resellerId())
            ->first();

        if (! $guest) {
            return null;
        }

        if ($guest->status === 'pending' && $guest->expires_at->isPast()) {
            $guest->update(['status' => 'expired']);
        }

        return $guest->status === 'pending' ? $guest : null;
    }

    /**
     * G7: آیا email (یا phone در صورت ارائه) با User موجود مطابقت دارد؟
     * اگر بله Audit `identity.guest_collision_detected` ثبت می‌شود.
     * تطابق **Proof نیست** (R9)؛ فراخواننده فقط باید به Login هدایت کند.
     */
    public function detectCollision(GuestCheckout $guest): ?User
    {
        $existing = $this->identity->findIdentity(
            email: $guest->guest_email,
            phone: $guest->guest_phone,
        );

        if ($existing) {
            $this->audit->record('identity.guest_collision_detected', $existing);
        }

        return $existing;
    }

    /**
     * G6/G9: پس از تکمیل همان خرید، نشست مصرف می‌شود. UPDATE شرطی است تا
     * دو درخواست هم‌زمان هر دو «موفق» نشوند.
     */
    public function consume(GuestCheckout $guest): bool
    {
        return GuestCheckout::query()
            ->whereKey($guest->id)
            ->where('status', 'pending')
            ->update(['status' => 'consumed', 'updated_at' => now()]) === 1;
    }

    /**
     * G9 / DATA-RETENTION.md (شکاف C11): حذف فیزیکی ۶۰ روز پس از انقضا/مصرف.
     *   - consumed: ۶۰ روز از لحظه‌ی مصرف (updated_at)
     *   - expired و pending‌ِ گذشته از expires_at: ۶۰ روز از expires_at
     * هیچ داده‌ی مالی به guest_checkouts وابسته نیست.
     *
     * @return int تعداد ردیف حذف‌شده
     */
    public function pruneExpired(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        $deleted = GuestCheckout::query()
            ->where(function ($q) use ($cutoff) {
                $q->where(fn ($c) => $c->where('status', 'consumed')->where('updated_at', '<=', $cutoff))
                    ->orWhere(fn ($e) => $e->whereIn('status', ['expired', 'pending'])->where('expires_at', '<=', $cutoff));
            })
            ->delete();

        // قاعده‌ی DATA-RETENTION: هر Job حذف یک Audit یک‌خطی (فقط تعداد) می‌نویسد.
        $this->audit->record('system.guest_checkouts_pruned', null, after: ['deleted' => $deleted]);

        return $deleted;
    }

    protected function nullIfBlank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
