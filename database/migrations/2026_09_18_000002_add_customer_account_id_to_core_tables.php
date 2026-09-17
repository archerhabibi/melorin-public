<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration ۲ از بلوپرینت (بند ۵۵) — اتصال جداول مالی به CustomerAccount.
 *
 * عمداً همه‌ی ستون‌ها nullable هستند و هیچ ستون قدیمی‌ای حذف یا NOT NULL
 * نمی‌شود. دلیل: این یک Migration «افزایشی» است و باید روی دیتابیس
 * production در حال کار، بدون هیچ downtime و بدون شکستن حتی یک مسیر
 * فعلی اجرا شود. پرکردن مقادیر کار Migration ۳ (backfill) است و
 * سوییچ‌کردن منطق به این ستون‌ها کار فازهای بعدی.
 *
 * wallets یک نکته‌ی ویژه دارد (بند ۷ و ۸ بلوپرینت): کیف‌پول امروز
 * polymorphic است (owner = User یا Reseller). معماری هدف این است:
 *     کیف‌پول مشتری  → owner = CustomerAccount
 *     کیف‌پول نماینده → owner = Reseller  (بدون تغییر)
 * یعنی فقط سمت مشتری مهاجرت می‌کند. unique قدیمی روی
 * (owner_type, owner_id) دست‌نخورده می‌ماند و یک unique جدید روی
 * customer_account_id اضافه می‌شود تا در دوره‌ی گذار — که هر دو مسیر
 * هم‌زمان زنده‌اند — امکان ساخته‌شدن دو کیف‌پول برای یک CustomerAccount
 * از هیچ مسیری وجود نداشته باشد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->foreignId('customer_account_id')->nullable()->after('owner_id')
                ->constrained()->cascadeOnDelete();
            $table->unique('customer_account_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_account_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            $table->index('customer_account_id');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('customer_account_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            $table->index('customer_account_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('customer_account_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            $table->index('customer_account_id');
        });

        Schema::table('commissions', function (Blueprint $table) {
            // کمیسیون دو طرف دارد و هر دو باید Store-aware شوند (بند ۳۱):
            // معرف و معرفی‌شده ممکن است در فروشگاه‌های متفاوتی باشند، و
            // کمیسیون همیشه باید به کیف‌پولِ همان فروشگاهی واریز شود که
            // خرید در آن انجام شده — نه به کیف‌پول دیگرِ همان شخص.
            $table->foreignId('referrer_customer_account_id')->nullable()->after('referrer_id')
                ->constrained('customer_accounts')->nullOnDelete();
            $table->foreignId('referred_customer_account_id')->nullable()->after('referred_user_id')
                ->constrained('customer_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referrer_customer_account_id');
            $table->dropConstrainedForeignId('referred_customer_account_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_account_id');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_account_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_account_id');
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['customer_account_id']);
            $table->dropConstrainedForeignId('customer_account_id');
        });
    }
};
