<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فاز B و D — اسنپ‌شات قیمت (بند ۱۲) و وضعیت Provisioning (بند ۲۵ و ۲۹).
 *
 * ۱) اسنپ‌شات قیمت: امروز سفارش فقط base_price و sold_price دارد. برای
 *    فروش نمایندگی این دو یعنی «قیمت عمده» و «قیمت فروش نماینده»، ولی
 *    برای فروش مستقیم هر دو یک عددند. نتیجه این است که از روی خودِ
 *    سفارش نمی‌شود فهمید هزینه‌ی واقعی هسته چقدر بوده. `core_price`
 *    این ابهام را برمی‌دارد و در هر دو حالت معنای یکسان دارد:
 *      core_price = چیزی که هسته دریافت کرده
 *      sold_price = چیزی که مشتری پرداخت کرده
 *    تفاوتشان = سود نماینده (در فروش مستقیم صفر).
 *
 * ۲) وضعیت Provisioning: بند ۲۹ می‌گوید حالت «پرداخت موفق ولی ساخت
 *    اکانت شکست‌خورده» باید صریحاً قابل تشخیص باشد. با enum فعلی،
 *    وضعیت `failed` هم برای شکست مالی به کار می‌رفت و هم برای شکست
 *    ساخت اکانت — یعنی از دیتابیس معلوم نبود پول کسر شده یا نه. دو
 *    وضعیت جدید این ابهام را برمی‌دارند:
 *      provisioning     → مالی تمام شده، ساخت اکانت در جریان است
 *      provision_failed → مالی تمام شده، ساخت اکانت نهایتاً شکست خورد
 *
 * enumها با تبدیل به string عوض می‌شوند نه با change() — چون تغییر enum
 * در MySQL نیازمند doctrine/dbal و در SQLite (محیط تست) اساساً بازسازی
 * جدول است. string ساده‌تر، قابل‌حمل‌تر و برای این کاربرد کاملاً کافی
 * است؛ اعتبارسنجی مقادیر کار لایه‌ی Service است، نه اسکیما.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('core_price', 15, 2)->nullable()->after('base_price');
            $table->unsignedInteger('provision_attempts')->default(0)->after('status');
            $table->text('failure_reason')->nullable()->after('provision_attempts');
        });

        // enum → string تا وضعیت‌های جدید بدون doctrine/dbal اضافه شوند
        $this->relaxStatusColumn('orders');
        $this->relaxStatusColumn('payments');

        // پرکردن core_price برای سفارش‌های موجود: تا امروز base_price
        // دقیقاً همین معنا را داشته، پس نگاشت یک‌به‌یک و بی‌خطر است.
        DB::table('orders')->whereNull('core_price')->update([
            'core_price' => DB::raw('base_price'),
        ]);
    }

    /**
     * ستون status را از enum به string تبدیل می‌کند تا افزودن وضعیت
     * جدید نیاز به تغییر اسکیما نداشته باشد.
     */
    protected function relaxStatusColumn(string $table): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite اصلاً enum واقعی ندارد (پشت صحنه TEXT است) پس
            // مقادیر جدید از همین الان پذیرفته می‌شوند.
            return;
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY `status` VARCHAR(32) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['core_price', 'provision_attempts', 'failure_reason']);
        });
    }
};
