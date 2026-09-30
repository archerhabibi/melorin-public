<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاز W3 بند ۱ (Roadmap) + بخش ۹.۳ TBD Decision Register:
 * «Guest Checkout Token — بدون نیاز به CustomerAccount برای شروع خرید».
 *
 * این جدول هیچ معادلی در VPNMarket ندارد (بند ۳ Roadmap: «در VPNMarket
 * هیچ Guest Checkout واقعی وجود ندارد») — طراحی کاملاً بر پایه‌ی جدول
 * تصمیمات بخش ۹.۳ است، نه قرض‌گرفته از پروژه‌ی مرجع.
 *
 * ستون‌ها دقیقاً همان چیزی که بند ۹.۳ و بند ۱۰ زیرسند مجاز کرده‌اند:
 * - token: یادداشت بند ۹.۳ («توکن تصادفی») — تصادفی و یکتا، در Cookie
 *   امضاشده نگه‌داری می‌شود (نه در جدول به‌عنوان Session کامل مرورگر).
 * - guest_name/guest_phone/guest_email: دقیقاً همان سه فیلد مجاز بند
 *   ۱۰ («Guest data باید محدود به نیازهای Checkout و Service Delivery
 *   باشد ... IP، Cookie، Session، Device Signals به‌تنهایی Proof of
 *   Identity نیستند»؛ به همین دلیل نه IP نه User-Agent اینجا ذخیره
 *   نمی‌شود).
 * - expires_at: بند ۹.۳ («اگر ظرف مثلاً ۳۰-۶۰ دقیقه پرداخت نشود منقضی
 *   می‌شود؛ عدد دقیق در Implementation»). این پچ ۴۵ دقیقه را در
 *   GuestCheckoutService ثابت کرده — قابل‌تغییر با UX نهایی، نه اینجا.
 * - reseller_id: nullable، برای رعایت StoreContext هم در Main هم در
 *   فروشگاه نماینده (بند ۹.۴: «Reseller Website باید فقط Context همان
 *   Reseller را استفاده کند»).
 * - status: pending (هنوز پرداخت نشده) → consumed (خرید کامل شد، فقط
 *   وقتی پچ بعدی مسیر پرداخت واقعی را وصل کند به کار می‌آید) یا expired.
 *
 * customer_account_id و user_id عمداً اینجا نیستند: طبق بند ۷ («Guest
 * یک هویت موقت است ... برای خرید الزاماً CustomerAccount ندارد») این
 * جدول باید بدون هیچ وابستگی به آن دو مدل معتبر باشد؛ اتصال بعد از
 * خرید موفق (Identity Resolution، بند ۹) در جدول دیگری ثبت می‌شود، نه
 * با افزودن یک ستون nullable اینجا که مرز Guest/User را مبهم می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_checkouts', function (Blueprint $table) {
            $table->id();

            $table->string('token', 64)->unique();

            $table->foreignId('product_id')->constrained();
            $table->foreignId('reseller_id')->nullable()->constrained();

            $table->string('guest_name', 100);
            $table->string('guest_phone', 32);
            $table->string('guest_email', 190)->nullable();

            // pending, consumed, expired
            $table->string('status', 16)->default('pending');

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_checkouts');
    }
};
