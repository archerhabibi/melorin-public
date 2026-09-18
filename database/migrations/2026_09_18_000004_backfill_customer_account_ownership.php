<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrationهای ۴ تا ۷ بلوپرینت (بند ۵۵) — پر کردن مالکیت CustomerAccount
 * روی کیف‌پول، سفارش، اکانت، پرداخت و کمیسیون.
 *
 * چرا در یک فایل و نه چهار فایل جدا (همان‌طور که بلوپرینت شماره‌گذاری
 * کرده): این پنج backfill باید نسبت به هم سازگار بمانند. اگر کیف‌پول‌ها
 * منتقل شوند ولی سفارش‌ها نه، سیستم در یک حالت نیمه‌مهاجرت‌یافته گیر
 * می‌کند که هیچ‌کدام از دو مسیر کامل نیست. اجرای همه در یک migration
 * یعنی یا همه با هم موفق می‌شوند یا هیچ‌کدام. شماره‌گذاری بلوپرینت برای
 * توضیح ترتیب منطقی است، نه الزام به چهار فایل.
 *
 * قاعده‌ی مشترک همه: «مالک = CustomerAccountی که (user_id, store) اش با
 * ردیف جور دربیاید»، و store از همان ستونی می‌آید که تا امروز مرجع بوده
 * (orders.reseller_id، payments.reseller_id، و برای accounts از طریق
 * سفارشش).
 *
 * idempotent: همه‌جا شرط «WHERE customer_account_id IS NULL» هست، پس
 * اجرای دوباره چیزی را بازنویسی نمی‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfillWallets();
        $this->backfillOrders();
        $this->backfillAccounts();
        $this->backfillPayments();
        $this->backfillCommissions();
    }

    /**
     * کیف‌پول‌های مشتری (owner_type = User) به CustomerAccount وصل
     * می‌شوند. کیف‌پول نماینده (owner_type = Reseller) عمداً دست‌نخورده
     * می‌ماند — طبق بند ۷ بلوپرینت، آن کیف‌پول مال خودِ Reseller است و
     * قرار نیست به CustomerAccount مهاجرت کند.
     *
     * نکته: در معماری قدیم هر User فقط یک کیف‌پول داشت، و چون هر User
     * الان دقیقاً یک CustomerAccount دارد (Migration ۳)، این نگاشت
     * یک‌به‌یک و بدون ابهام است.
     */
    protected function backfillWallets(): void
    {
        DB::table('wallets')
            ->where('owner_type', \App\Models\User::class)
            ->whereNull('customer_account_id')
            ->orderBy('id')
            ->chunkById(500, function ($wallets) {
                foreach ($wallets as $wallet) {
                    $accountId = DB::table('customer_accounts')
                        ->where('user_id', $wallet->owner_id)
                        ->value('id');

                    if ($accountId) {
                        DB::table('wallets')->where('id', $wallet->id)
                            ->update(['customer_account_id' => $accountId]);
                    }
                }
            });
    }

    protected function backfillOrders(): void
    {
        DB::table('orders')->whereNull('customer_account_id')->orderBy('id')
            ->chunkById(500, function ($orders) {
                foreach ($orders as $order) {
                    $accountId = $this->resolveAccountId($order->user_id, $order->reseller_id);

                    if ($accountId) {
                        DB::table('orders')->where('id', $order->id)
                            ->update(['customer_account_id' => $accountId]);
                    }
                }
            });
    }

    /**
     * اکانت‌ها ستون reseller_id ندارند، پس فروشگاه از روی سفارششان
     * خوانده می‌شود — که همان منبع حقیقتی است که سفارش خودش استفاده کرده.
     */
    protected function backfillAccounts(): void
    {
        DB::table('accounts')->whereNull('customer_account_id')->orderBy('id')
            ->chunkById(500, function ($accounts) {
                foreach ($accounts as $account) {
                    $orderAccountId = DB::table('orders')
                        ->where('id', $account->order_id)
                        ->value('customer_account_id');

                    // اگر سفارش به هر دلیل مالک نگرفت، از خودِ user_id
                    // اکانت به فروشگاه اصلی سقوط می‌کنیم تا ردیف یتیم نماند.
                    $accountId = $orderAccountId ?: $this->resolveAccountId($account->user_id, null);

                    if ($accountId) {
                        DB::table('accounts')->where('id', $account->id)
                            ->update(['customer_account_id' => $accountId]);
                    }
                }
            });
    }

    /**
     * فقط پرداخت‌های کیف‌پول مشتری مالک می‌گیرند. پرداخت‌هایی که
     * wallet_owner_type = reseller دارند یعنی شارژ اعتبار خودِ نماینده
     * نزد پلتفرم‌اند و اصلاً به CustomerAccount مربوط نیستند.
     */
    protected function backfillPayments(): void
    {
        DB::table('payments')
            ->where('wallet_owner_type', 'user')
            ->whereNull('customer_account_id')
            ->orderBy('id')
            ->chunkById(500, function ($payments) {
                foreach ($payments as $payment) {
                    $accountId = $this->resolveAccountId($payment->user_id, $payment->reseller_id);

                    if ($accountId) {
                        DB::table('payments')->where('id', $payment->id)
                            ->update(['customer_account_id' => $accountId]);
                    }
                }
            });
    }

    /**
     * کمیسیون هر دو طرفش باید Store-aware شود. فروشگاه از سفارشِ مرتبط
     * گرفته می‌شود، چون کمیسیون همیشه در همان فروشگاهی معنا دارد که خرید
     * در آن اتفاق افتاده (بند ۳۱ بلوپرینت).
     */
    protected function backfillCommissions(): void
    {
        DB::table('commissions')
            ->whereNull('referrer_customer_account_id')
            ->orderBy('id')
            ->chunkById(500, function ($commissions) {
                foreach ($commissions as $commission) {
                    $resellerId = DB::table('orders')
                        ->where('id', $commission->order_id)
                        ->value('reseller_id');

                    DB::table('commissions')->where('id', $commission->id)->update([
                        'referrer_customer_account_id' => $this->resolveAccountId($commission->referrer_id, $resellerId),
                        'referred_customer_account_id' => $this->resolveAccountId($commission->referred_user_id, $resellerId),
                    ]);
                }
            });
    }

    /**
     * (user, store) → customer_account_id
     *
     * اگر کاربر در آن فروشگاه CustomerAccount نداشته باشد (مثلاً سفارشی
     * از نماینده‌ای که کاربر دیگر عضوش نیست)، همین‌جا ساخته می‌شود —
     * چون داده‌ی مالی گذشته باید مالک داشته باشد، حتی اگر عضویتش امروز
     * دیگر در users.reseller_id منعکس نباشد. این دقیقاً همان حالتی است
     * که معماری قدیم نمی‌توانست نمایش دهد و انگیزه‌ی اصلی این مهاجرت است.
     */
    protected function resolveAccountId(?int $userId, ?int $resellerId): ?int
    {
        if (! $userId) {
            return null;
        }

        $storeType = $resellerId ? 'reseller' : 'main';

        $query = DB::table('customer_accounts')
            ->where('user_id', $userId)
            ->where('store_type', $storeType);

        $resellerId
            ? $query->where('reseller_id', $resellerId)
            : $query->whereNull('reseller_id');

        $existing = $query->value('id');

        if ($existing) {
            return $existing;
        }

        DB::table('customer_accounts')->insertOrIgnore([[
            'user_id' => $userId,
            'store_type' => $storeType,
            'reseller_id' => $resellerId,
            // insertOrIgnore خام است، رویداد saving مدل اجرا نمی‌شود؛
            // scope_key باید دستی هم‌گام با store_type/reseller_id بالا
            // ساخته شود.
            'scope_key' => $resellerId ? 'reseller:'.$resellerId : 'main',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        return $query->value('id');
    }

    public function down(): void
    {
        DB::table('wallets')->update(['customer_account_id' => null]);
        DB::table('orders')->update(['customer_account_id' => null]);
        DB::table('accounts')->update(['customer_account_id' => null]);
        DB::table('payments')->update(['customer_account_id' => null]);
        DB::table('commissions')->update([
            'referrer_customer_account_id' => null,
            'referred_customer_account_id' => null,
        ]);
    }
};
