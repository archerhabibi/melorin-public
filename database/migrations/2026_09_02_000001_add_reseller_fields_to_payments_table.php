<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طبق تصمیم صریح شما: «شارژ حساب مشتری» در ربات نماینده باید توسط خودِ
 * نماینده تایید شود (چون پول را مستقیم می‌گیرد)، نه ادمین اصلی؛ در
 * مقابل «شارژ حساب نماینده» (اعتبار خودِ نماینده نزد پلتفرم) هم‌چنان
 * باید توسط ادمین اصلی تایید شود. یک ستون reviewed_by (با FK به admins)
 * نمی‌تواند هر دو حالت را پوشش دهد، پس یک مسیر تایید کاملاً جدا برای
 * نماینده اضافه می‌شود:
 *
 * - wallet_owner_type: کدام کیف‌پول شارژ می‌شود («user» یا «reseller»)
 * - reseller_id: پرداخت به کدام نماینده مربوط است (برای Scope و برای
 *   تشخیص این‌که کدام نماینده مجاز به تایید است)
 * - reviewed_by_reseller_id: اگر تاییدکننده ادمین اصلی نبود بلکه خودِ
 *   نماینده بود، این ستون پر می‌شود (reviewed_by آن‌وقت null می‌ماند)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('wallet_owner_type', ['user', 'reseller'])->default('user')->after('user_id');
            $table->foreignId('reseller_id')->nullable()->after('wallet_owner_type')->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by_reseller_id')->nullable()->after('reviewed_by')->constrained('resellers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_reseller_id');
            $table->dropConstrainedForeignId('reseller_id');
            $table->dropColumn('wallet_owner_type');
        });
    }
};
