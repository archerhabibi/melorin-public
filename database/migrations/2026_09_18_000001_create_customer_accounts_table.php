<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration ۱ از بلوپرینت (بند ۵۵) — پایه‌ی کل معماری Multi-Store.
 *
 * تا امروز «مشتری» یعنی خودِ User، و عضویتش در فروشگاه با
 * users.reseller_id مشخص می‌شد. این یعنی یک نفر نمی‌توانست هم‌زمان هم
 * مشتری فروشگاه اصلی باشد و هم مشتری نماینده‌ی A و هم نماینده‌ی B —
 * چون فقط یک reseller_id داشت و کیف‌پولش هم یکی بود.
 *
 * از این به بعد (بند ۳ و ۵ بلوپرینت):
 *   User            → فقط Identity مرکزی (کیست)
 *   CustomerAccount → عضویت این Identity در یک فروشگاه مشخص
 *
 * یعنی علی می‌تواند سه CustomerAccount کاملاً مستقل داشته باشد:
 *   (main, null) / (reseller, A) / (reseller, B)
 * هرکدام با کیف‌پول، سفارش، اکانت و کمیسیون جدا.
 *
 * نکته‌ی مهم: users.reseller_id عمداً حذف نمی‌شود (بند ۴.۱ بلوپرینت) —
 * تا وقتی همه‌ی مسیرها به CustomerAccount منتقل و در عمل تأیید شوند،
 * ستون قدیمی به‌عنوان مرجع پشتیبان باقی می‌ماند. حذفش کار Migration ۱۰
 * است، نه این یکی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->id();

            // nullable چون مهمان (Guest Checkout، بند ۳۵) هنوز Identity
            // ثبت‌شده ندارد؛ بعد از ثبت‌نام/شناسایی به یک User وصل می‌شود.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->enum('store_type', ['main', 'reseller'])->default('main');

            // فقط وقتی store_type=reseller پر می‌شود. nullOnDelete نه
            // cascade: اگر نماینده‌ای حذف شود، سابقه‌ی مالی مشتریانش
            // نباید ناگهان از دیتابیس پاک شود.
            $table->foreignId('reseller_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('status', ['active', 'disabled', 'blocked'])->default('active');
            $table->string('display_name')->nullable();
            $table->json('metadata')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // قلب یکپارچگی این جدول: هر Identity در هر فروشگاه فقط یک
            // عضویت دارد. بدون این، دو بار /start زدن هم‌زمان می‌توانست
            // دو CustomerAccount و در نتیجه دو کیف‌پول موازی بسازد —
            // همان دسته باگی که پیدا کردنش بعد از وقوع تقریباً غیرممکن
            // است. MySQL چند ردیف با NULL را نقض unique نمی‌داند، پس
            // مهمان‌ها (user_id = null) آزادانه ساخته می‌شوند.
            $table->unsignedBigInteger('main_user_id')
                ->nullable()
                ->storedAs("CASE WHEN store_type = 'main' THEN user_id ELSE NULL END");

            $table->unsignedBigInteger('reseller_user_id')
                ->nullable()
                ->storedAs("CASE WHEN store_type = 'reseller' THEN user_id ELSE NULL END");

            $table->unique('main_user_id', 'customer_accounts_main_user_unique');

            $table->unique(
                ['reseller_id', 'reseller_user_id'],
                'customer_accounts_reseller_user_unique'
            );
            $table->index(['store_type', 'reseller_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_accounts');
    }
};
