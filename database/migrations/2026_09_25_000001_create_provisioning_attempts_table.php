<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاز A3 سند v2.1 (بند ۶۹ — Provisioning Attempt Tracking).
 *
 * تا امروز تنها ردِ یک تلاش Provisioning، شمارنده‌ی ساده‌ی
 * orders.provision_attempts بود؛ از روی آن نمی‌شد فهمید هر تلاش دقیقاً
 * به کدام Operation (idempotency) متعلق بوده یا نتیجه‌ی هر تلاش به‌طور
 * جداگانه چه بوده — فقط عدد نهاییِ تلاش‌ها روی خودِ سفارش دیده می‌شد.
 *
 * این Migration فقط بخشِ اول سند را پیاده می‌کند (اقلامِ ۱ تا ۴ از
 * فازبندی: ایجادِ مدل + ثبتِ operation_id/attempt_number/status).
 * ستون‌های error/started_at/finished_at (اقلامِ ۵ تا ۷) و پوشش تست
 * Retry/Duplicate Retry (اقلامِ ۸ تا ۱۰) عمداً در این پچ نیستند و در
 * فاز بعدی با یک Migration مستقل (ALTER) اضافه می‌شوند — دقیقاً همان
 * الگویی که فازهای قبلی این پروژه برای افزودنِ تدریجیِ ستون به همین
 * جدول‌ها استفاده کرده‌اند (مثلاً افزودنِ next_provision_retry_at در
 * فاز ۱۱، جدا از خودِ ستونِ provision_attempts).
 *
 * order_id (نه در سند، ولی ضروری): بدون آن نمی‌شود تلاش‌های یک سفارش را
 * پیدا کرد. operation_id عمداً nullable است — تلاش‌های claimForRetry
 * دستی (کلیک ادمین) همیشه یک Operation ندارند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('attempt_number');

            // started, succeeded, failed
            $table->string('status', 32);

            $table->timestamps();

            $table->index(['order_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_attempts');
    }
};
