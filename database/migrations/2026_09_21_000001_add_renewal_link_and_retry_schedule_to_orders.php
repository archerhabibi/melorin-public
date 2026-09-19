<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * فاز ۱۱ — سیاست شکست Provisioning (بند ۳۵ تا ۴۰ سند معماری).
 *
 * دو ستون به orders اضافه می‌شود، هر دو برای این‌که «retry» و «refund»
 * واقعاً هم برای خرید و هم برای تمدید کار کنند:
 *
 * ۱) renews_account_id — اگر سفارش یک «تمدید» باشد، همین ستون می‌گوید
 *    کدام اکانت را تمدید می‌کند. تا امروز سفارش تمدید هیچ نشانه‌ای از
 *    اکانتِ هدفش نداشت، پس:
 *      الف) نمی‌شد تمدیدِ شکست‌خورده را بدون کسر دوباره retry کرد؛
 *      ب) بدتر: retryProvisioning() روی سفارش تمدید، به‌جای تمدید،
 *         یک اکانت «جدید» می‌ساخت (چون هیچ account با این order_id
 *         وجود ندارد).
 *    ستون عمداً یک unsignedBigInteger ساده است، نه foreign key: اکانت‌ها
 *    SoftDelete دارند و قید FK در محیط تست (SQLite) روی جدول موجود
 *    پشتیبانی‌نشده است. یکپارچگی را RenewalService::retry() چک می‌کند.
 *
 * ۲) next_provision_retry_at — زمانِ تلاش مجدد خودکارِ بعدی. تا امروز
 *    این زمان‌بندی فقط روی Operation ثبت می‌شد و بلافاصله توسط
 *    OperationService::runOnce (با markFailed بدون available_at)
 *    پاک می‌شد؛ یعنی هیچ Job‌ای هیچ‌وقت چیزی برای retry پیدا نمی‌کرد.
 *    حالا خودِ سفارش زمان تلاش بعدی‌اش را نگه می‌دارد.
 *
 * Backfill: فقط سفارش‌های provision_failed «موجود» بررسی می‌شوند و فقط
 * برای پیداکردن ستون renews_account_id. متن تراکنش کیف‌پولِ تمدید همیشه
 * «تمدید اکانت {username} — سفارش #{id}» بوده و username روی accounts
 * unique است، پس نگاشت بی‌ابهام است. اگر پیدا نشد، سفارش دست‌نخورده
 * می‌ماند. next_provision_retry_at عمداً برای سفارش‌های قدیمی null
 * می‌ماند تا با دیپلوی این پچ، سفارش‌های قدیمی ناگهان خودکار retry
 * نشوند — آن‌ها با دکمه‌های صفحه‌ی سفارش‌ها رسیدگی می‌شوند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('renews_account_id')->nullable()->index();
            $table->timestamp('next_provision_retry_at')->nullable()->index();
        });

        $this->backfillRenewalLinkForFailedOrders();
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['renews_account_id']);
            $table->dropIndex(['next_provision_retry_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['renews_account_id', 'next_provision_retry_at']);
        });
    }

    /**
     * بهترین‌تلاش (best-effort): هیچ خطایی در این مرحله نباید Migration را
     * شکست بدهد، چون ستون‌ها تا اینجا ساخته شده‌اند و backfill فقط یک
     * راحتی برای سفارش‌های قدیمی است.
     */
    protected function backfillRenewalLinkForFailedOrders(): void
    {
        try {
            if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('accounts')) {
                return;
            }

            $orderMorphClass = \App\Models\Order::class;

            DB::table('orders')
                ->where('status', 'provision_failed')
                ->whereNull('renews_account_id')
                ->chunkById(100, function ($orders) use ($orderMorphClass) {
                    foreach ($orders as $order) {
                        $description = DB::table('wallet_transactions')
                            ->where('reference_type', $orderMorphClass)
                            ->where('reference_id', $order->id)
                            ->where('description', 'like', 'تمدید اکانت %')
                            ->value('description');

                        if (! $description) {
                            continue;
                        }

                        if (! preg_match('/^تمدید اکانت (\S+) — سفارش #\d+$/u', $description, $matches)) {
                            continue;
                        }

                        $accountId = DB::table('accounts')
                            ->where('panel_username', $matches[1])
                            ->value('id');

                        if ($accountId) {
                            DB::table('orders')
                                ->where('id', $order->id)
                                ->update(['renews_account_id' => $accountId]);
                        }
                    }
                });
        } catch (\Throwable $e) {
            Log::warning('orders_renewal_link_backfill_skipped', ['error' => $e->getMessage()]);
        }
    }
};
