<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * bot_token با encrypted cast ذخیره می‌شود (IV تصادفی هر بار)، پس
 * WHERE bot_token = ? در سطح دیتابیس اصلاً کار نمی‌کند — تنها راه، حلقه
 * زدن روی همه‌ی نمایندگان و رمزگشاییِ یکی‌یکی برای هر Update ورودی
 * وب‌هوک است، که هم کند است هم لازم نیست. webhook_slug یک شناسه‌ی
 * تصادفیِ رمزنگاری‌نشده و indexed است که فقط برای مسیریابی URL استفاده
 * می‌شود؛ bot_token واقعی هم‌چنان فقط برای ساخت Api و صدا زدن API
 * واقعیِ تلگرام به کار می‌رود، هرگز در URL ظاهر نمی‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('webhook_slug')->nullable()->unique()->after('bot_token');
        });

        DB::table('resellers')->whereNull('webhook_slug')->orderBy('id')->each(function ($reseller) {
            DB::table('resellers')->where('id', $reseller->id)->update(['webhook_slug' => Str::random(40)]);
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn('webhook_slug');
        });
    }
};
