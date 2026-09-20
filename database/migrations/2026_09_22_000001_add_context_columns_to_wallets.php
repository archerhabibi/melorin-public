<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاز ۱۳ — ساختار Wallet (بند ۲۱ تا ۲۸ سند معماری).
 *
 *     Wallet = User + StoreContext
 *     wallets(user_id, store_type, reseller_id, balance)
 *
 * تا امروز wallets یک جدول polymorphic بود (owner = CustomerAccount /
 * Reseller / User قدیمی). این باعث سه مشکل می‌شد:
 *   ۱) «Wallet صاحب نماینده در Main» (Rule 6) وجود نداشت؛ اعتبار نماینده
 *      یک Wallet جدا با owner=Reseller بود، نه Wallet همان User در Main.
 *   ۲) Wallet قدیمیِ کاربران (owner=User) که Migration ۴ فقط به آن
 *      customer_account_id وصل کرد، توسط WalletService پیدا نمی‌شد
 *      (سرویس فقط owner=CustomerAccount را می‌دید) و موجودی‌شان صفر
 *      دیده می‌شد.
 *   ۳) یکتایی در سطح DB روی (owner_type, owner_id) بود، نه روی Context.
 *
 * این Migration فقط ستون‌های جدید را اضافه می‌کند (مرحله‌ی «expand»).
 * ادغام داده در Migration بعدی و unique نهایی در Migration سوم است تا
 * هر مرحله جدا، کوچک و قابل‌بازاجرا باشد. ستون‌های قدیمی (owner_type,
 * owner_id, customer_account_id) عمداً حذف نمی‌شوند؛ nullable می‌شوند و
 * دیگر نوشته نمی‌شوند. حذف‌شان مرحله‌ی «contract» بعدی است، بعد از
 * اطمینان از production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->enum('store_type', ['main', 'reseller'])->default('main');

            // nullOnDelete نه cascade: حذف نماینده نباید سابقه‌ی مالی را پاک کند
            $table->foreignId('reseller_id')->nullable()->constrained()->nullOnDelete();

            // main → "main"، reseller → "reseller:{id}". دلیل وجود این ستون
            // (به‌جای unique مستقیم روی reseller_id): در MySQL، NULL در
            // unique index با NULL دیگر برابر حساب نمی‌شود؛ پس
            // UNIQUE(user_id, store_type, reseller_id) برای Main (که
            // reseller_id=NULL دارد) هیچ‌چیز را محافظت نمی‌کند (بند ۲۷).
            // scope_key هرگز NULL نیست، پس unique روی آن واقعاً کار می‌کند.
            $table->string('scope_key', 40)->nullable();
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->string('owner_type')->nullable()->change();
            $table->unsignedBigInteger('owner_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // بازگرداندن به NOT NULL ممکن نیست (ردیف‌های جدید owner ندارند) و
        // ادغام Migration بعدی هم برگشت‌پذیر نیست؛ فقط ستون‌های جدید حذف می‌شوند.
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('reseller_id');
            $table->dropColumn(['store_type', 'scope_key']);
        });
    }
};
