<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرحله ۵ از ترتیب پانزده‌مرحله‌ای سند معماری (بند ۵۹): Pricing Migration.
 *
 * طبق تصمیم صریح («حتی اگر داده‌ها پاک شوند مهم نیست»)، این یک rename
 * ساده و بدون‌نگرانیِ حفظ داده‌ی قدیمی است — نه یک migration محتاطانه‌ی
 * معناشناسانه که بند ۵۴ برای پروژه‌های production توصیه می‌کند.
 *
 * سه تغییر:
 *   ۱) products.price          → products.main_price
 *      (products.reseller_price از قبل درست بود، دست نمی‌خورد)
 *   ۲) reseller_product_prices.custom_price → customers_price
 *   ۳) orders: سه ستون قدیمی (base_price, core_price, sold_price) که
 *      همیشه هرسه یک عدد را با سه نام مختلف نگه می‌داشتند، با سه ستون
 *      *مستقل* و nullable جایگزین می‌شوند — دقیقاً مدل بند ۳۱ سند:
 *      سفارش Main فقط main_price دارد، سفارش نماینده فقط reseller_price
 *      و customers_price. این جایگزینی از نوع rename نیست چون تعداد و
 *      معنای ستون‌ها عوض شده؛ به همین دلیل مقادیر قدیمی منتقل نمی‌شوند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('price', 'main_price');
        });

        Schema::table('reseller_product_prices', function (Blueprint $table) {
            $table->renameColumn('custom_price', 'customers_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['base_price', 'core_price', 'sold_price']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('main_price', 15, 2)->nullable()->after('sales_channel');
            $table->decimal('reseller_price', 15, 2)->nullable()->after('main_price');
            $table->decimal('customers_price', 15, 2)->nullable()->after('reseller_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('main_price', 'price');
        });

        Schema::table('reseller_product_prices', function (Blueprint $table) {
            $table->renameColumn('customers_price', 'custom_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['main_price', 'reseller_price', 'customers_price']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('base_price', 15, 2)->nullable();
            $table->decimal('core_price', 15, 2)->nullable();
            $table->decimal('sold_price', 15, 2)->nullable();
        });
    }
};
