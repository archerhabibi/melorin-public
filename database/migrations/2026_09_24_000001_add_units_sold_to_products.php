<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فاز A2 سند v2.1 (بند ۶۱ — Sale Limit در برابر خرید هم‌زمان).
 *
 * تا امروز sale_limit با یک COUNT ساده و بدون قفل چک می‌شد
 * (PurchaseGuard::assertSaleLimitNotReached)، درست قبل از شروع تراکنش
 * مالی — یعنی دو خرید هم‌زمان می‌توانستند هر دو COUNT را کمتر از سقف
 * ببینند و هر دو رد شوند، درحالی‌که مجموعشان از sale_limit عبور می‌کرد.
 *
 * علاوه بر این، آن COUNT حتی از نظر منطقی هم ناقص بود: لیست وضعیت‌هایش
 * (paid/provisioning/account_created) بر خلاف Order::isFinanciallySettled()
 * وضعیت provision_failed را نمی‌شمرد — یعنی سفارشی که پول آن گرفته شده
 * ولی Provisioning‌اش گیر کرده (منتظر retry/رسیدگی ادمین)، سهمیه را
 * آزاد نشان می‌داد و مشتریِ دوم می‌توانست «آخرین واحد» را هم بخرد.
 *
 * راه‌حل: یک شمارنده‌ی اتمیک روی خودِ محصول. رزرو با یک UPDATE شرطی
 * انجام می‌شود (`WHERE units_sold < sale_limit`)، نه با یک SELECT جدا و
 * بعد یک UPDATE — پس دو خرید هم‌زمان روی همین ردیف سریالایز می‌شوند و
 * فقط تا سقفِ واقعی موفق می‌شوند (PurchaseService::execute).
 *
 * Backfill: units_sold از روی سفارش‌های «مالی‌شده»ی *غیرِ-تمدید* موجود
 * پر می‌شود — renewal (renews_account_id ست) یک واحدِ جدید نمی‌فروشد،
 * پس نباید در شمارنده حساب شود (RenewalService از مسیر جدا و بدون رزرو
 * عمل می‌کند و هیچ‌وقت این شمارنده را دست نمی‌زند).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('units_sold')->default(0)->after('sale_limit');
        });

        $counts = DB::table('orders')
            ->select('product_id', DB::raw('COUNT(*) as c'))
            ->whereIn('status', [
                Order::STATUS_PAID,
                Order::STATUS_PROVISIONING,
                Order::STATUS_ACCOUNT_CREATED,
                Order::STATUS_PROVISION_FAILED,
            ])
            ->whereNull('renews_account_id')
            ->groupBy('product_id')
            ->get();

        foreach ($counts as $row) {
            DB::table('products')->where('id', $row->product_id)->update(['units_sold' => $row->c]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('units_sold');
        });
    }
};
