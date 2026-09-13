<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طبق درخواست صریح: ورود به پنل نماینده باید مثل پنل اصلی با
 * ایمیل/رمزعبور باشد، نه فقط لینک یک‌بارمصرف ربات. users از قبل
 * password دارد (برای حساب سایت، بند ۲۵ سند نیازمندی اصلی) ولی هیچ
 * فیلد شناسه‌ی یکتای شبیه ایمیل نداشت؛ username_site برای این منظور
 * مناسب نیست چون معنایش «نام‌کاربری سایت» است، نه لزوماً ایمیل واقعی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('username_site');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
