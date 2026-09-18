<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تنظیم سراسری «سیاست شکست Provisioning» — گزینه‌ای که پنل ادمین باید
 * بتواند بین دو رفتار انتخاب کند:
 *
 *   retry (پیش‌فرض) — همان چیزی که تا امروز پیاده بود: سفارش در
 *     provision_failed می‌ماند («پول گرفته شده، تحویل نشده»)، هیچ
 *     بازگشتی خودکار انجام نمی‌شود، و جبران با retryProvisioning()
 *     (بدون کسر دوباره) یا رسیدگی دستی ادمین انجام می‌شود.
 *
 *   refund — بازگشت خودکار وجه به محض شکست Provisioning: مشتری
 *     main_price/Customers_price را پس می‌گیرد و در فروش نمایندگی،
 *     نماینده هم reseller_price را پس می‌گیرد (همان دو-طرفه‌ی
 *     RefundService). سفارش به‌جای provision_failed به‌طور مستقیم
 *     refunded می‌رود.
 *
 * چرا یک تنظیم و نه یک تصمیم قطعیِ کد: هر دو رفتار در عمل درست است و
 * بستگی به مدل کسب‌وکار دارد — بعضی پلتفرم‌ها ترجیح می‌دهند مشتری
 * بلافاصله پولش را پس بگیرد، بعضی ترجیح می‌دهند ادمین قبل از هر
 * بازگشتی وضعیت را ببیند. سند معماری (بند ۲۲) هم صریحاً همین گزینه‌ی
 * دوم («وضعیت Pending/Retry که سیستم عمداً تعریف کرده») را مجاز
 * می‌داند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_settings', function (Blueprint $table) {
            $table->id();
            $table->string('failure_policy', 20)->default('retry');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_settings');
    }
};
