<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طبق درخواست صریح: «نماینده باید بتواند سبد فروش ربات خودش را فعال و
 * یا غیرفعال کند.»
 *
 * توجه به تفاوت این کلید با Category::available_to_resellers:
 *  - available_to_resellers → تصمیمِ مدیر Core، سراسری برای همه‌ی
 *    نمایندگان. اگر false باشد، سبد اصلاً در پنل/ربات هیچ نماینده‌ای
 *    دیده نمی‌شود (رفتار تأییدشده در v3.0.5، بدون تغییر).
 *  - این جدول → تصمیمِ خودِ نماینده برای ربات خودش، و فقط در
 *    محدوده‌ی سبدهایی که Core از قبل اجازه داده. یعنی یک AND دولایه
 *    است، نه یک override؛ نماینده هرگز نمی‌تواند سبدی را که Core بسته،
 *    باز کند.
 *
 * نبودِ رکورد = فعال. این پیش‌فرض عمدی است تا رفتار نمایندگان فعلی
 * (که هیچ رکوردی ندارند) با این مهاجرت تغییر نکند — دقیقاً همان
 * الگوی default=true که در available_to_resellers هم استفاده شد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_category_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['reseller_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_category_settings');
    }
};
