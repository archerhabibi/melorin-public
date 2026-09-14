<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طبق درخواست صریح: «قیمت نمایندگان» — قیمتی که ما (پلتفرم) محصول را
 * به نماینده می‌فروشیم، نه قیمت فروش نماینده (که همیشه در اختیار خودِ
 * نماینده می‌ماند و اینجا دست‌نخورده باقی می‌ماند). عمداً یک فیلد
 * سراسری روی خودِ محصول است، نه دوباره per-reseller — طبق توضیح صریح:
 * «اگه برای هر نماینده قیمت نمایندگی جدا تعیین کنیم کاری بسیار زمان‌بر
 * و در آینده گیج‌کننده می‌شود.» تا این migration، AccountService برای
 * Double-Debit همان products.price (قیمت خرده‌فروشی/مشتری) را از
 * کیف‌پول نماینده هم کسر می‌کرد — یعنی نماینده هیچ حاشیه‌ی سودِ
 * عمده‌فروشی واقعی نداشت.
 *
 * nullable عمداً است: تا وقتی ادمین برای یک محصول مقدار نگذاشته،
 * AccountService به products.price سقوط می‌کند (Product::resellerBasePrice)
 * — یعنی رفتار محصولات موجود بدون اقدام صریح تغییر نمی‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('reseller_price', 15, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reseller_price');
        });
    }
};
