<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاز C — سقف بدهی نماینده (بند ۲۰ بلوپرینت).
 *
 * تا امروز اعتبار نماینده هرگز نمی‌توانست منفی شود: کیف‌پول زیر صفر
 * برود یعنی InsufficientBalanceException. این یعنی نماینده دقیقاً در
 * لحظه‌ای که اعتبارش تمام می‌شود، فروشش کاملاً قطع می‌شود — حتی وسط
 * شب که کسی نیست شارژ را تأیید کند.
 *
 * مدل بلوپرینت: به هر نماینده یک سقف بدهی مشخص می‌دهیم. موجودی می‌تواند
 * تا `-debt_limit` منفی شود و نه یک ریال بیشتر:
 *
 *     balance = -450 ، debt_limit = 500 ، cost = 50  →  -500  مجاز
 *     balance = -450 ، debt_limit = 500 ، cost = 51  →  -501  مسدود
 *
 * پیش‌فرض صفر عمدی است: رفتار دقیقاً مثل امروز باقی می‌ماند (هیچ
 * بدهی‌ای مجاز نیست) تا وقتی ادمین آگاهانه برای یک نماینده سقف تعیین
 * کند. یک migration نباید به‌طور خودکار به کسی اعتبار بدهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->decimal('debt_limit', 15, 2)->default(0)->after('min_sale_price_rule');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn('debt_limit');
        });
    }
};
