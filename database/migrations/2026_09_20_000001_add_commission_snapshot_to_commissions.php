<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاز F — اسنپ‌شات کمیسیون (بند ۳۱ بلوپرینت: «هنگام خرید Snapshot شود:
 * commission_rate و commission_amount، تا تغییر Rate در آینده History
 * را تغییر ندهد»).
 *
 * amount از قبل روی جدول هست؛ چیزی که کم بود نرخِ لحظه‌ی محاسبه است.
 * بدون آن، اگر ادمین فردا درصد کمیسیون را از ۱۰٪ به ۱۵٪ ببرد، هیچ راهی
 * نیست بفهمیم کمیسیون‌های قدیمی با چه نرخی حساب شده‌اند — و هر گزارشی
 * که نرخ را از تنظیمات فعلی بخواند، تاریخچه را اشتباه نشان می‌دهد.
 *
 * `base_amount` هم اضافه می‌شود: مبلغی که کمیسیون روی آن حساب شده.
 * چون سفارش ممکن است بعداً بازگشت بخورد (و طبق بند ۳۲ کمیسیون برنمی‌گردد)،
 * نگه‌داشتن مبنا باعث می‌شود محاسبه همیشه قابل بازسازی و راستی‌آزمایی باشد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('type');
            $table->decimal('base_amount', 15, 2)->nullable()->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'base_amount']);
        });
    }
};
