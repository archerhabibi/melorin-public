<?php

namespace App\Services\Core\Guest;

use App\Models\GuestCheckout;
use App\Models\Product;
use App\Services\Core\Store\StoreContext;
use Illuminate\Support\Str;

/**
 * فاز W3 بند ۱ + بخش ۹.۳ — Guest Checkout Token.
 *
 * دقیقاً هم‌سطح با PurchaseService/PaymentService/WalletService (زیر
 * app/Services/Core)، نه داخل کانال Website — چون طبق بند ۷/۸/۹
 * زیرسند، Guest یک مفهوم Core است («یک هویت موقت Website» ولی همان
 * جایی تعریف می‌شود که User/CustomerAccount تعریف شده‌اند)، و در فاز
 * بعدی (Identity Resolution/Claiming) باید توسط چند کانال (Website،
 * احتمالاً پنل ادمین) به یک شکل مصرف شود — دقیقاً همان دلیلی که
 * IdentityService را Core نگه داشته، نه داخل هیچ کانال خاص.
 *
 * این سرویس **فقط بند ۱** از فاز W3 است: ساخت/اعتبارسنجی/انقضای
 * توکن. تکمیل خرید واقعی (بند ۳: Payment→Order→Provisioning) و
 * Identity Resolution بعد از خرید (بند ۴، ۵) عمداً اینجا نیستند —
 * چون نیاز به یک تصمیم معماری جدا دارند: PurchaseService::purchase()
 * امروز یک CustomerAccount الزامی می‌گیرد (بند ۷ زیرسند: «CustomerAccount
 * شرط لازم Guest Checkout نیست» یعنی خودِ این امضا باید عوض شود یا یک
 * مسیر جایگزین بگیرد) — تصمیمی که نباید ضمنی و داخل همین پچ گرفته شود.
 */
class GuestCheckoutService
{
    /** بند ۹.۳: «اگر ظرف مثلاً ۳۰-۶۰ دقیقه پرداخت نشود منقضی می‌شود». */
    protected const TTL_MINUTES = 45;

    /**
     * @throws \InvalidArgumentException اگر guest_name/guest_phone خالی باشند
     */
    public function start(
        Product $product,
        StoreContext $store,
        string $guestName,
        string $guestPhone,
        ?string $guestEmail,
    ): GuestCheckout {
        $guestName = trim($guestName);
        $guestPhone = trim($guestPhone);

        if ($guestName === '' || $guestPhone === '') {
            throw new \InvalidArgumentException('نام و شماره تماس مهمان الزامی است.');
        }

        // بند ۱۰: فقط سه فیلد مجاز؛ IP/User-Agent/Device Signal عمداً
        // پارامتر این متد نیستند تا کسی بعداً به‌عنوان «هویت» به آن‌ها
        // تکیه نکند.
        return GuestCheckout::create([
            'token' => Str::random(48),
            'product_id' => $product->id,
            'reseller_id' => $store->resellerId(),
            'guest_name' => $guestName,
            'guest_phone' => $guestPhone,
            'guest_email' => $guestEmail ? trim($guestEmail) : null,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }

    /**
     * توکن معتبر برای همین Context را برمی‌گرداند، یا null. منقضی‌شدن
     * lazily اینجا اعمال می‌شود (بدون نیاز به Scheduled Job برای این
     * حجم کم) — اگر رکورد pending ولی expires_at گذشته باشد، همین‌جا
     * status را به expired تغییر می‌دهیم تا مصرف دوباره‌اش قفل بماند.
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
}
