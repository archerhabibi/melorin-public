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
            // چون دیگر هیچ Generated Columnی پایه‌اش user_id نیست (به
            // پایین مراجعه کنید)، cascadeOnDelete دوباره مجاز و منطقی
            // است: اگر ردیف کاربر واقعاً حذف شود، عضویت‌هایش هم حذف شوند.
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
            // دو CustomerAccount و در نتیجه دو کیف‌پول موازی بسازد.
            //
            // به‌جای دو Stored Generated Column (نسخه‌ی قبلی)، یک ستون
            // معمولی scope_key داریم که مقدارش را اپلیکیشن (مدل
            // CustomerAccount + migrationهای backfill) موقع ساخت رکورد
            // پر می‌کند:
            //   store_type = main       → scope_key = "main"
            //   store_type = reseller   → scope_key = "reseller:{id}"
            //
            // چرا generated column نه: اگر scope_key را storedAs() بر
            // پایه‌ی reseller_id تعریف کنیم، همان محدودیت MySQL که در
            // Migration قبلی روی user_id خطا داد این‌بار روی reseller_id
            // می‌افتد — چون reseller_id هم یک FK با nullOnDelete (SET
            // NULL) دارد، و SET NULL روی پایه‌ی یک generated column مجاز
            // نیست. عمداً scope_key را یک ستون ساده نگه داشتیم تا FK بالا
            // دست‌نخورده بماند.
            //
            // نکته‌ی مهم برای توسعه‌دهنده‌های بعدی: چون scope_key دیگر
            // خودکار (DB-level) پر نمی‌شود، هر مسیری که مستقیماً با
            // DB::table('customer_accounts')->insert(...) رکورد می‌سازد
            // (نه از طریق مدل Eloquent) باید scope_key را صریحاً پاس
            // بدهد — وگرنه NULL می‌ماند و آن ردیف از محافظت unique خارج
            // می‌شود (بدون خطا، به‌صورت خاموش). این کار در Migration ۳ و
            // ۴ (backfill) انجام شده؛ مدل CustomerAccount هم آن را در
            // رویداد creating/saving خودکار می‌سازد.
            $table->string('scope_key');

            $table->unique(
                ['user_id', 'scope_key'],
                'customer_accounts_user_scope_unique'
            );
            $table->index(['store_type', 'reseller_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_accounts');
    }
};
