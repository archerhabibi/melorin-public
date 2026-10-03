<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B3.2 — زمان آخرین هم‌گام‌سازی «مصرف» از پنل.
 *
 * تا B3.1 ستون traffic_used_gb فقط هنگام ساخت/تمدید صفر می‌شد و هیچ مسیری آن را از پنل
 * به‌روز نمی‌کرد؛ پس «حجم باقی‌مانده» و هشدار ۹۰٪ داشبورد همیشه بر اساس مصرفِ صفر بود.
 * این ستون به مشتری می‌گوید عدد چقدر تازه است و به Job دوره‌ای می‌گوید کدام اکانت‌ها قدیمی‌ترند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dateTime('usage_synced_at')->nullable()->after('traffic_used_gb');
            $table->index('usage_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['usage_synced_at']);
            $table->dropColumn('usage_synced_at');
        });
    }
};
