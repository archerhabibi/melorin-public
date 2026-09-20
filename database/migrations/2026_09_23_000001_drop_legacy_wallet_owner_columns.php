<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فاز ۱۵ — مرحله‌ی «contract» ساختار Wallet (ادامه‌ی فاز ۱۳).
 *
 * ساختار نهایی سند (بند ۲۱): wallets(user_id, store_type, reseller_id, balance).
 * ستون‌های polymorphic قدیمی — owner_type، owner_id و customer_account_id — از
 * فاز ۱۳ دیگر نه خوانده می‌شدند و نه نوشته؛ اینجا همراه با ایندکس‌ها و FKشان
 * حذف می‌شوند.
 *
 * ⚠️ حذف برگشت‌ناپذیر است، پس اگر هنوز Walletی مانده که به هیچ User نگاشت
 * نشده (user_id خالی؛ مثلاً owner قدیمیِ حذف‌شده)، تنها ردّ مالکش همین
 * ستون‌هاست و Migration عمداً متوقف می‌شود تا چیزی از دست نرود. لاگ
 * `wallet_context_backfill_unresolved` (فاز ۱۳) همان ردیف‌ها را نشان می‌دهد؛
 * پس از رسیدگی دستی (یا انتقال موجودی) دوباره migrate کنید.
 *
 * user_id و scope_key عمداً nullable می‌مانند: NOT NULL کردنشان تست
 * Migration ادغام (فاز ۱۳) را که ردیف قدیمی‌شکل می‌سازد ناممکن می‌کند، و
 * چون ردیفِ بدون User فقط از راه SQL خام می‌آید، نگهبانِ اصلی مدل Wallet و
 * unique(user_id, scope_key) است.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wallets', 'owner_type')) {
            return;
        }

        $orphans = DB::table('wallets')->whereNull('user_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "{$orphans} Wallet بدون User باقی مانده؛ حذف ستون‌های legacy ردّ مالکشان را نابود می‌کرد. "
                .'ابتدا آن‌ها را (با لاگ wallet_context_backfill_unresolved) دستی رسیدگی کنید.'
            );
        }

        // سه Schema::table جدا و ترتیبدار (MySQL: FK قبل از unique قبل از ستون؛
        // SQLite: dropForeign بازسازی جدول است و dropColumn بومی).
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropForeign(['customer_account_id']);
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['customer_account_id']);
            $table->dropUnique(['owner_type', 'owner_id']);
            $table->dropIndex(['owner_type', 'owner_id']);
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['owner_type', 'owner_id', 'customer_account_id']);
        });
    }

    public function down(): void
    {
        // برگشت‌پذیر نیست؛ ستون‌ها فقط به‌صورت خالی برمی‌گردند.
        Schema::table('wallets', function (Blueprint $table) {
            $table->string('owner_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('customer_account_id')->nullable();
        });
    }
};
