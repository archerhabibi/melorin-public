<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند ۲۶ (Idempotency) و بند ۴۸ (Operation/Outbox) بلوپرینت.
 *
 * مسئله‌ای که حل می‌کند: امروز اگر یک درخواست حساس دوبار برسد — تلگرام
 * همان Update را دوباره بفرستد، کاربر دوبار روی دکمه بزند، یا یک Job
 * دوباره اجرا شود — سیستم دوبار کسر می‌کند و دوبار اکانت می‌سازد.
 * محافظت فعلی فقط یک کلید Cache در وب‌هوک است که (الف) اتمیک نیست و
 * (ب) فقط لایه‌ی تلگرام را می‌پوشاند، نه خودِ عملیات مالی.
 *
 * ساختار: هر عملیات حساس یک کلید idempotency یکتا دارد. اولین درخواست
 * ردیف را می‌سازد؛ درخواست دوم به unique می‌خورد و به‌جای اجرای دوباره،
 * نتیجه‌ی همان عملیات قبلی را برمی‌گرداند.
 *
 * چرا یکتایی در دیتابیس و نه در Cache: تنها لایه‌ای که در برابر دو
 * درخواست کاملاً هم‌زمان روی دو پروسه‌ی PHP تضمین واقعی می‌دهد، همین
 * unique index است. Cache::add هم بهتر از has/put است ولی در برابر
 * ری‌استارت/تخلیه‌ی کش تضمینی ندارد؛ برای کسر پول این کافی نیست.
 *
 * result_payload: خروجی عملیات موفق (مثلاً order_id و account_id) تا
 * درخواست تکراری بتواند دقیقاً همان پاسخ اول را پس بدهد، نه یک خطا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();

            $table->string('idempotency_key')->unique();

            // purchase, provision, renewal, payment_confirmation, refund, webhook
            $table->string('type', 64);

            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending');

            $table->nullableMorphs('reference');

            $table->json('payload')->nullable();
            $table->json('result_payload')->nullable();

            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            // برای Retry زمان‌بندی‌شده (بند ۲۵: تلاش مجدد Provisioning)
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};
