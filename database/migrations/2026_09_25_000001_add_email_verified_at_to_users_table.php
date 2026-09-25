<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاز W1 — دو پیش‌نیاز ستونی که در جدول اصلی users نبودند (آن Migration
 * فقط برای پنل نماینده اضافه شده بود، نه برای Website):
 *
 *   - email_verified_at → تصمیم ۹.۲ (Email verification: Soft) — بنر
 *     یادآوری غیرمسدودکننده به این ستون نیاز دارد.
 *   - remember_token → تصمیم ۹.۲ («مرا به‌خاطر بسپار» اختیاری، تا ۳۰
 *     روز طبق تصمیم ۹.۶) — Laravel Authenticatable به‌صورت پیش‌فرض این
 *     نام ستون را انتظار دارد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->rememberToken();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'remember_token']);
        });
    }
};
