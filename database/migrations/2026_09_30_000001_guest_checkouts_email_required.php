<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فاز ۴ (Master 2.7 §3، G2/G8؛ شکاف C2): مدل Guest جدید.
 *
 *   guest_email  → NOT NULL   (تنها فیلد الزامی)
 *   guest_name   → nullable   (اختیاری)
 *   guest_phone  → nullable   (اختیاری)
 *
 * ردیف‌های قدیمی بدون email مربوط به مدل DEPRECATED (X1–X4) هستند؛ Guest
 * فقط یک نشست کوتاه‌مدت (TTL ۴۵ دقیقه) است و هیچ رکورد مالی/هویتی به آن
 * وابسته نیست (User/Order به guest_checkouts FK ندارند)، پس حذفِ ردیف‌های
 * بدون email امن است و از شکستنِ ALTER (NOT NULL روی داده‌ی NULL) جلوگیری
 * می‌کند. این Migration داده‌ی مالی را لمس نمی‌کند.
 *
 * ->change() در Laravel 11 روی MySQL/SQLite بدون doctrine/dbal کار می‌کند
 * ولی همه‌ی modifierها باید دوباره ذکر شوند.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('guest_checkouts')->whereNull('guest_email')->delete();

        Schema::table('guest_checkouts', function (Blueprint $table) {
            $table->string('guest_email', 190)->change();
            $table->string('guest_name', 100)->nullable()->change();
            $table->string('guest_phone', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // best-effort: داده‌ی حذف‌شده برنمی‌گردد (ردیف‌های موقت بودند).
        DB::table('guest_checkouts')->whereNull('guest_name')->update(['guest_name' => '']);
        DB::table('guest_checkouts')->whereNull('guest_phone')->update(['guest_phone' => '']);

        Schema::table('guest_checkouts', function (Blueprint $table) {
            $table->string('guest_email', 190)->nullable()->change();
            $table->string('guest_name', 100)->change();
            $table->string('guest_phone', 32)->change();
        });
    }
};
