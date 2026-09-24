<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاز A3 سند v2.1 (بند ۶۹) — اقلامِ ۵ تا ۷: error، started_at، finished_at.
 *
 * پچِ قبلی (اقلامِ ۱ تا ۴) عمداً این سه ستون را نداشت. حالا که خودِ
 * جدول و مسیر ساختِ رکورد جا افتاده، این سه ستون طبقِ همان ترتیبِ
 * فازبندی اضافه می‌شوند:
 *
 * - started_at: لحظه‌ی شروعِ همان تلاش (هم‌زمان با ساختِ رکورد در
 *   ProvisioningService::beginAttempt — نه created_at، چون created_at
 *   مالِ خودِ ردیفِ دیتابیس است و اگر روزی این رکورد از یک Queue/Job با
 *   تأخیر درج شود، دیگر معادلِ لحظه‌ی واقعیِ شروعِ تلاش نیست).
 * - finished_at: لحظه‌ای که تلاش به succeeded یا failed می‌رسد.
 * - error: پیامِ خطا برای تلاش‌های failed (همان reason-ای که تا امروز
 *   فقط روی orders.failure_reason ذخیره می‌شد — حالا per-attempt هم
 *   نگه داشته می‌شود، چون failure_reason با هر retry جدید بازنویسی
 *   می‌شود و تاریخچه‌ی تلاش‌های قبلی را پاک می‌کند).
 *
 * هر سه nullable هستند: تلاشِ در وضعیتِ started هنوز نه error دارد و نه
 * finished_at؛ تلاشِ succeeded هرگز error نمی‌گیرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_attempts', function (Blueprint $table) {
            $table->text('error')->nullable()->after('status');
            $table->timestamp('started_at')->nullable()->after('error');
            $table->timestamp('finished_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_attempts', function (Blueprint $table) {
            $table->dropColumn(['error', 'started_at', 'finished_at']);
        });
    }
};
