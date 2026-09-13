<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طبق سند نیازمندی‌های Reseller Platform (بند ۵): «نماینده فقط می‌تواند
 * روی Product مجاز: فروش را فعال/غیرفعال کند» — یعنی مدل باید opt-in
 * باشد، نه opt-out. قبل از این migration، وجود یک ردیف در
 * reseller_product_prices فقط قیمت سفارشی را مشخص می‌کرد و
 * Product::priceForReseller() در نبود آن به‌صورت پیش‌فرض قیمت پایه را
 * برمی‌گرداند — یعنی هر محصولی که نماینده هیچ‌وقت لمسش نکرده بود هم
 * قابل‌فروش به‌حساب می‌آمد. این ستون آن رفتار را برعکس می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_product_prices', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(false)->after('custom_price');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_product_prices', function (Blueprint $table) {
            $table->dropColumn('is_enabled');
        });
    }
};
