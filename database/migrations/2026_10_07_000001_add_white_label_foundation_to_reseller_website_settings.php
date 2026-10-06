<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B5.7 — White Label Foundation: دو تنظیم SEO که Website Contract (§9.7) از قبل وعده داده بود ولی
 * هیچ‌جا ذخیره نمی‌شد: «صفحات نماینده پیش‌فرض noindex، مگر خودش بخواهد».
 *
 *  - allow_indexing: پیش‌فرض false (= همان رفتار فعلی؛ هیچ فروشگاهی بی‌اجازه ایندکس نمی‌شود).
 *  - meta_description: توضیح کوتاه برای موتور جست‌وجو/اشتراک‌گذاری؛ NULL = بدون توضیح.
 *
 * ردیف‌های موجود بدون backfill درست‌اند (false/NULL = رفتار قبلی).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: نصبِ دوباره/به‌روزرسانیِ نیمه‌کاره نباید با «Duplicate column» بشکند.
        if (! Schema::hasTable('reseller_website_settings')) {
            return;
        }

        Schema::table('reseller_website_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('reseller_website_settings', 'allow_indexing')) {
                $table->boolean('allow_indexing')->default(false)->after('about_text');
            }
            if (! Schema::hasColumn('reseller_website_settings', 'meta_description')) {
                $table->string('meta_description', 300)->nullable()->after('allow_indexing');
            }
        });
    }

    public function down(): void
    {
        $existing = array_values(array_filter(
            ['allow_indexing', 'meta_description'],
            fn (string $column) => Schema::hasColumn('reseller_website_settings', $column),
        ));

        if ($existing !== []) {
            Schema::table('reseller_website_settings', fn (Blueprint $table) => $table->dropColumn($existing));
        }
    }
};
