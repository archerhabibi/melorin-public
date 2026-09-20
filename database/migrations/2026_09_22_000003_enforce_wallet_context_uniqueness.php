<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاز ۱۳ — بند ۲۷: «برای هر User در هر Context فقط یک Wallet».
 *
 * unique روی (user_id, scope_key) است، نه (user_id, store_type,
 * reseller_id): چون reseller_id در Main برابر NULL است و در MySQL دو NULL
 * با هم برابر حساب نمی‌شوند، آن unique هیچ‌وقت جلوی دو Main Wallet را
 * نمی‌گرفت. scope_key ("main" یا "reseller:{id}") هیچ‌وقت NULL نیست.
 *
 * اگر Migration قبلی چیزی را ناقص ادغام کرده باشد، اینجا صریحاً خطا
 * می‌دهد و هیچ داده‌ای تغییر نمی‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->unique(['user_id', 'scope_key'], 'wallets_user_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique('wallets_user_scope_unique');
        });
    }
};
