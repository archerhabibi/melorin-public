<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند ۱۰ بلوپرینت: «برای عملیات حساس، یک شناسه Idempotency/Operation نیز
 * باید قابل ثبت باشد.»
 *
 * بدون این ستون، وقتی در گردش حساب یک کیف‌پول دو کسر مشابه می‌بینیم،
 * هیچ راهی نیست بفهمیم دو خرید واقعی بوده یا یک خرید که دوبار پردازش
 * شده. با این ستون، این سؤال با یک کوئری جواب می‌گیرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreignId('operation_id')->nullable()->after('wallet_id')
                ->constrained()->nullOnDelete();
            $table->index('operation_id');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operation_id');
        });
    }
};
