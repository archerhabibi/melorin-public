<?php

namespace App\Services\Ops;

use App\Models\Order;
use App\Models\Payment;
use App\Support\CurrencyLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * بررسی‌های آمادگی Staging/Production (فاز ۹).
 *
 *  - config  : تنظیمات محیط (فقط خواندن؛ هیچ‌چیز را تغییر نمی‌دهد)
 *  - data    : سلامت داده‌ی مالی/عملیاتی روی دیتابیس واقعی
 *  - runtime : زنده‌بودن زیرساخت (DB، Cache، Storage، Scheduler، Queue)
 *
 * این کلاس هیچ قاعده‌ی کسب‌وکاری را دوباره پیاده نمی‌کند؛ فقط ناسازگاری‌ها را
 * گزارش می‌دهد. اصلاح داده همیشه دستی و طبق docs/operations/INCIDENT-RESPONSE.md است.
 */
class Preflight
{
    public const HEARTBEAT_KEY = 'melorin:scheduler:heartbeat';

    /** سفارش/Operation که بیش از این دقیقه در حالت میانی بماند «گیرکرده» است */
    public const STUCK_MINUTES = 15;

    /** @return list<CheckResult> */
    public function run(string $group = 'all'): array
    {
        $results = [];

        if (in_array($group, ['all', 'config'], true)) {
            $results = array_merge($results, $this->config());
        }
        if (in_array($group, ['all', 'runtime'], true)) {
            $results = array_merge($results, $this->runtime());
        }
        if (in_array($group, ['all', 'data'], true)) {
            $results = array_merge($results, $this->data());
        }

        return $results;
    }

    /** @return list<CheckResult> */
    public function config(): array
    {
        $g = 'config';
        $env = (string) config('app.env');
        $isLive = in_array($env, ['production', 'staging'], true);
        $r = [];

        $r[] = filled(config('app.key'))
            ? CheckResult::ok($g, 'app_key')
            : CheckResult::fail($g, 'app_key', 'APP_KEY خالی است (php artisan key:generate).');

        $r[] = ! config('app.debug')
            ? CheckResult::ok($g, 'app_debug')
            : ($isLive
                ? CheckResult::fail($g, 'app_debug', "APP_DEBUG=true در محیط {$env}؛ Stack Trace و مقادیر env برای کاربر نمایش داده می‌شود.")
                : CheckResult::warn($g, 'app_debug', 'APP_DEBUG=true (فقط در Local قابل‌قبول است).'));

        $https = str_starts_with((string) config('app.url'), 'https://');
        $r[] = $https
            ? CheckResult::ok($g, 'app_url_https')
            : ($env === 'production'
                ? CheckResult::fail($g, 'app_url_https', 'APP_URL در Production باید https باشد (Callback زرین‌پال، لینک‌های امضاشده، HSTS).')
                : CheckResult::warn($g, 'app_url_https', 'APP_URL https نیست؛ روی Staging پشت Tunnel/TLS باید https باشد.'));

        $secure = config('session.secure');
        $r[] = ($secure === true || ! $https)
            ? CheckResult::ok($g, 'session_secure_cookie')
            : CheckResult::fail($g, 'session_secure_cookie', 'APP_URL https است ولی SESSION_SECURE_COOKIE=true نیست.');

        // B2.5 — Session Security (docs/canonical/SESSION-SECURITY-CONTRACT.md §S7)
        $sessionDriver = (string) config('session.driver');
        $r[] = match (true) {
            in_array($sessionDriver, ['array', 'cookie'], true) && $isLive => CheckResult::fail($g, 'session_driver', "SESSION_DRIVER={$sessionDriver}؛ نشست‌ها ماندگار/قابل‌ابطال نیستند (database یا redis لازم است)."),
            $sessionDriver === 'file' && $isLive => CheckResult::warn($g, 'session_driver', 'SESSION_DRIVER=file؛ فهرست و ابطال نشست‌های کاربر (دستگاه‌ها) کار نمی‌کند. database پیشنهاد می‌شود.'),
            default => CheckResult::ok($g, 'session_driver', $sessionDriver),
        };

        $r[] = config('session.http_only') === true
            ? CheckResult::ok($g, 'session_http_only')
            : ($isLive
                ? CheckResult::fail($g, 'session_http_only', 'SESSION_HTTP_ONLY=true نیست؛ Cookie نشست برای JavaScript (XSS) خواندنی می‌شود.')
                : CheckResult::warn($g, 'session_http_only', 'SESSION_HTTP_ONLY=true نیست.'));

        $sameSite = strtolower((string) config('session.same_site'));
        $r[] = match (true) {
            $sameSite === 'none' => $isLive
                ? CheckResult::fail($g, 'session_same_site', 'SESSION_SAME_SITE=none؛ Cookie نشست در درخواست‌های بین‌سایتی هم ارسال می‌شود (CSRF).')
                : CheckResult::warn($g, 'session_same_site', 'SESSION_SAME_SITE=none.'),
            $sameSite === 'strict' => $isLive
                ? CheckResult::fail($g, 'session_same_site', 'SESSION_SAME_SITE=strict؛ برگشت از Google/Telegram نشست (state) را گم می‌کند و ورود/اتصال می‌شکند. lax لازم است.')
                : CheckResult::warn($g, 'session_same_site', 'SESSION_SAME_SITE=strict ورود Google و اتصال Telegram را می‌شکند.'),
            $sameSite === 'lax' => CheckResult::ok($g, 'session_same_site', 'lax'),
            default => CheckResult::warn($g, 'session_same_site', 'SESSION_SAME_SITE روی lax تنظیم نیست.'),
        };

        $idle = (int) config('session.lifetime');
        $absolute = (int) config('session.absolute_lifetime');
        $r[] = match (true) {
            $idle > 720 => CheckResult::warn($g, 'session_lifetime', "SESSION_LIFETIME={$idle} دقیقه؛ Idle Timeout بیش از ۱۲ ساعت برای فروشگاه مالی زیاد است."),
            $absolute <= 0 && $isLive => CheckResult::warn($g, 'session_lifetime', 'SESSION_ABSOLUTE_LIFETIME=0؛ نشستی که مدام استفاده شود هرگز منقضی نمی‌شود.'),
            $absolute > 43200 => CheckResult::warn($g, 'session_lifetime', "SESSION_ABSOLUTE_LIFETIME={$absolute} دقیقه؛ بیش از ۳۰ روز."),
            default => CheckResult::ok($g, 'session_lifetime', "idle={$idle}m absolute={$absolute}m"),
        };

        // Cookie سراسری دامنه (مثلاً .example.com) بین زیردامنه‌ها (و دامنه‌های نماینده‌ها در B6) نشست را به اشتراک می‌گذارد.
        $r[] = filled(config('session.domain'))
            ? CheckResult::warn($g, 'session_domain', 'SESSION_DOMAIN تنظیم شده؛ نشست بین همه‌ی زیردامنه‌ها مشترک می‌شود. Cookie فقط-میزبان (خالی) امن‌تر است.')
            : CheckResult::ok($g, 'session_domain');

        $r[] = filled(config('telegram.bots.main.token'))
            ? CheckResult::ok($g, 'telegram_main_bot_token')
            : CheckResult::fail($g, 'telegram_main_bot_token', 'TELEGRAM_MAIN_BOT_TOKEN خالی است.');

        // S-02: بدون secret وب‌هوک اصلی عمداً ۴۰۳ می‌دهد (Fail-closed).
        $r[] = filled(config('telegram.webhook_secret'))
            ? CheckResult::ok($g, 'telegram_webhook_secret')
            : CheckResult::fail($g, 'telegram_webhook_secret', 'TELEGRAM_WEBHOOK_SECRET خالی است ← ربات اصلی همه‌ی Updateها را ۴۰۳ می‌دهد.');

        $proxies = (string) env('TRUSTED_PROXIES', '127.0.0.1,::1');
        $r[] = str_contains($proxies, '*')
            ? CheckResult::warn($g, 'trusted_proxies', 'TRUSTED_PROXIES شامل * است؛ هر کلاینت می‌تواند X-Forwarded-For جعل کند (Rate Limit/Audit بی‌اعتبار).')
            : CheckResult::ok($g, 'trusted_proxies', $proxies);

        $queue = (string) config('queue.default');
        $r[] = ($queue === 'sync' && $isLive)
            ? CheckResult::fail($g, 'queue_driver', 'QUEUE_CONNECTION=sync؛ Broadcast/اعلان‌ها داخل درخواست کاربر اجرا می‌شوند.')
            : CheckResult::ok($g, 'queue_driver', $queue);

        $cache = (string) config('cache.default');
        $r[] = ($cache === 'array' && $isLive)
            ? CheckResult::fail($g, 'cache_store', 'CACHE_STORE=array؛ Rate Limit و Lock بین درخواست‌ها ماندگار نیست.')
            : CheckResult::ok($g, 'cache_store', $cache);

        // Staging باید Sandbox باشد، Production نباید (پول واقعی).
        $sandbox = (bool) config('services.zarinpal.sandbox');
        if ($env === 'staging') {
            $r[] = $sandbox
                ? CheckResult::ok($g, 'zarinpal_sandbox', 'staging + sandbox')
                : CheckResult::fail($g, 'zarinpal_sandbox', 'Staging با ZARINPAL_SANDBOX=false؛ ممکن است پول واقعی جابه‌جا شود (مگر روش پرداخت sandbox:true داشته باشد).');
        } elseif ($env === 'production') {
            $r[] = ! $sandbox
                ? CheckResult::ok($g, 'zarinpal_sandbox', 'production + live')
                : CheckResult::fail($g, 'zarinpal_sandbox', 'Production با ZARINPAL_SANDBOX=true؛ پرداخت‌ها شارژ واقعی نمی‌شوند.');
        }

        // تأیید ایمیل Gate خرید/شارژ است؛ بدون Mailer واقعی کاربر جدید هرگز نمی‌تواند خرید کند.
        $mailer = (string) config('mail.default');
        $r[] = (in_array($mailer, ['log', 'array'], true) && $env === 'production')
            ? CheckResult::fail($g, 'mail_driver', "MAIL_MAILER={$mailer}؛ ایمیل تأیید/بازیابی رمز هرگز ارسال نمی‌شود و ثبت‌نام‌های جدید قفل می‌ماند.")
            : (in_array($mailer, ['log', 'array'], true)
                ? CheckResult::warn($g, 'mail_driver', "MAIL_MAILER={$mailer}؛ ایمیل واقعی ارسال نمی‌شود (برای تست دستی Verification لینک را از لاگ بردارید).")
                : CheckResult::ok($g, 'mail_driver', $mailer));

        foreach (['app', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
            $path = storage_path($dir);
            $r[] = (is_dir($path) && is_writable($path))
                ? CheckResult::ok($g, "storage_writable:{$dir}")
                : CheckResult::fail($g, "storage_writable:{$dir}", "{$path} قابل‌نوشتن نیست.");
        }

        return $r;
    }

    /** @return list<CheckResult> */
    public function runtime(): array
    {
        $g = 'runtime';
        $r = [];

        try {
            DB::select('select 1');
            $r[] = CheckResult::ok($g, 'database');
        } catch (Throwable $e) {
            return [CheckResult::fail($g, 'database', 'اتصال دیتابیس ناموفق: '.$e::class)];
        }

        try {
            $key = 'melorin:health:'.bin2hex(random_bytes(4));
            Cache::put($key, 'x', 10);
            $ok = Cache::get($key) === 'x';
            Cache::forget($key);
            $r[] = $ok ? CheckResult::ok($g, 'cache') : CheckResult::fail($g, 'cache', 'Cache مقدار نوشته‌شده را برنگرداند.');
        } catch (Throwable $e) {
            $r[] = CheckResult::fail($g, 'cache', 'Cache در دسترس نیست: '.$e::class);
        }

        $r[] = is_writable(storage_path('app'))
            ? CheckResult::ok($g, 'storage')
            : CheckResult::fail($g, 'storage', 'storage/app قابل‌نوشتن نیست.');

        $pending = $this->pendingMigrations();
        $r[] = $pending === []
            ? CheckResult::ok($g, 'migrations')
            : CheckResult::fail($g, 'migrations', count($pending).' Migration اجرا‌نشده: '.implode(', ', array_slice($pending, 0, 5)));

        try {
            $stored = CurrencyLock::stored();
            $r[] = ($stored === null || $stored === CurrencyLock::signature())
                ? CheckResult::ok($g, 'currency_lock', (string) $stored)
                : CheckResult::fail($g, 'currency_lock', "ثبت‌شده={$stored} ولی config=".CurrencyLock::signature());
        } catch (Throwable $e) {
            $r[] = CheckResult::fail($g, 'currency_lock', $e::class);
        }

        // Scheduler: Heartbeat هر دقیقه از routes/console.php نوشته می‌شود.
        $beat = Cache::get(self::HEARTBEAT_KEY);
        if ($beat === null) {
            $r[] = CheckResult::warn($g, 'scheduler', 'Heartbeat ثبت نشده؛ کران `schedule:run` اجرا نمی‌شود یا هنوز یک دقیقه نگذشته (Retry خودکار Provisioning و guest:prune متوقف است).');
        } else {
            $age = now()->timestamp - (int) $beat;
            $r[] = $age <= 180
                ? CheckResult::ok($g, 'scheduler', "{$age}s")
                : CheckResult::warn($g, 'scheduler', "آخرین Heartbeat {$age} ثانیه پیش؛ Scheduler متوقف است.");
        }

        if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
            $oldest = DB::table('jobs')->min('created_at');
            $waiting = $oldest === null ? 0 : now()->timestamp - (int) $oldest;
            $r[] = $waiting <= self::STUCK_MINUTES * 60
                ? CheckResult::ok($g, 'queue_backlog', DB::table('jobs')->count().' job')
                : CheckResult::warn($g, 'queue_backlog', "قدیمی‌ترین Job {$waiting} ثانیه منتظر است؛ Worker (queue:work) اجرا نمی‌شود؟");
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->count();
            $r[] = $failed === 0
                ? CheckResult::ok($g, 'failed_jobs')
                : CheckResult::warn($g, 'failed_jobs', "{$failed} Job شکست‌خورده (php artisan queue:failed).");
        }

        return $r;
    }

    /** @return list<CheckResult> */
    public function data(): array
    {
        $g = 'data';
        $r = [];

        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            return [CheckResult::fail($g, 'database', 'اتصال دیتابیس ناموفق: '.$e::class)];
        }

        // ۱) Ledger: مجموع گردش هر کیف‌پول باید دقیقاً برابر موجودی باشد.
        $drift = DB::table('wallets as w')
            ->leftJoinSub(
                DB::table('wallet_transactions')->selectRaw('wallet_id, SUM(amount) as total')->groupBy('wallet_id'),
                't',
                't.wallet_id', '=', 'w.id'
            )
            ->whereRaw('COALESCE(t.total, 0) <> w.balance')
            ->limit(20)
            ->pluck('w.id')
            ->all();
        $r[] = $drift === []
            ? CheckResult::ok($g, 'ledger_matches_balance')
            : CheckResult::fail($g, 'ledger_matches_balance', 'موجودی ≠ مجموع گردش در Walletهای: '.implode(',', $drift));

        // ۲) balance_after آخرین تراکنش باید برابر موجودی باشد.
        $stale = DB::table('wallets as w')
            ->join('wallet_transactions as t', 't.wallet_id', '=', 'w.id')
            ->whereRaw('t.id = (SELECT MAX(id) FROM wallet_transactions WHERE wallet_id = w.id)')
            ->whereColumn('t.balance_after', '<>', 'w.balance')
            ->limit(20)
            ->pluck('w.id')
            ->all();
        $r[] = $stale === []
            ? CheckResult::ok($g, 'last_balance_after_matches')
            : CheckResult::fail($g, 'last_balance_after_matches', 'balance_after آخرین تراکنش ≠ موجودی در Walletهای: '.implode(',', $stale));

        // ۳) Walletی بدون user_id (هشدار Runbook فاز ۱۵).
        $orphans = DB::table('wallets')->whereNull('user_id')->count();
        $r[] = $orphans === 0
            ? CheckResult::ok($g, 'wallets_have_owner')
            : CheckResult::fail($g, 'wallets_have_owner', "{$orphans} Wallet بدون user_id.");

        // ۴) پرداخت تأییدشده‌ی شارژ کیف‌پول بدون تراکنش charge.
        $morph = (new Payment)->getMorphClass();
        $uncredited = DB::table('payments as p')
            ->where('p.purpose', 'wallet_charge')
            ->whereIn('p.status', ['confirmed', 'refunded'])
            ->whereNotExists(function ($q) use ($morph) {
                $q->select(DB::raw(1))->from('wallet_transactions as t')
                    ->whereColumn('t.reference_id', 'p.id')
                    ->where('t.reference_type', $morph)
                    ->where('t.type', 'charge');
            })
            ->limit(20)
            ->pluck('p.id')
            ->all();
        $r[] = $uncredited === []
            ? CheckResult::ok($g, 'confirmed_payments_credited')
            : CheckResult::fail($g, 'confirmed_payments_credited', 'پرداخت تأییدشده بدون شارژ کیف‌پول: '.implode(',', $uncredited).' (پول گرفته شده ولی اعتبار داده نشده).');

        // ۵) سفارش‌های گیرکرده در وضعیت میانی.
        $cutoff = now()->subMinutes(self::STUCK_MINUTES);
        $stuck = DB::table('orders')
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PROVISIONING])
            ->where('updated_at', '<', $cutoff)
            ->limit(20)
            ->pluck('id')
            ->all();
        $r[] = $stuck === []
            ? CheckResult::ok($g, 'orders_not_stuck')
            : CheckResult::warn($g, 'orders_not_stuck', 'سفارش در paid/provisioning بیش از '.self::STUCK_MINUTES.' دقیقه: '.implode(',', $stuck).' (Crash وسط Provisioning یا پاسخ گمشده‌ی پنل).');

        // ۶) Retry خودکار باید سفارش‌های سررسیدشده را برداشته باشد.
        $overdue = DB::table('orders')
            ->where('status', Order::STATUS_PROVISION_FAILED)
            ->whereNotNull('next_provision_retry_at')
            ->where('next_provision_retry_at', '<', $cutoff)
            ->count();
        $r[] = $overdue === 0
            ? CheckResult::ok($g, 'retries_not_overdue')
            : CheckResult::warn($g, 'retries_not_overdue', "{$overdue} سفارش Retry سررسیدشده‌ی اجرانشده (Scheduler خاموش است؟).");

        $needsAction = DB::table('orders')->where('status', Order::STATUS_PROVISION_FAILED)->count();
        $r[] = $needsAction === 0
            ? CheckResult::ok($g, 'provision_failed_orders')
            : CheckResult::warn($g, 'provision_failed_orders', "{$needsAction} سفارش provision_failed نیازمند رسیدگی.");

        // ۷) Operation گیرکرده در processing.
        $ops = DB::table('operations')->where('status', 'processing')->where('updated_at', '<', $cutoff)->count();
        $r[] = $ops === 0
            ? CheckResult::ok($g, 'operations_not_stuck')
            : CheckResult::warn($g, 'operations_not_stuck', "{$ops} Operation بیش از ".self::STUCK_MINUTES.' دقیقه processing است.');

        // ۸) نماینده‌ی فعال بدون Webhook Secret (S-01 ← ربات او ۴۰۳ می‌دهد).
        $noSecret = DB::table('resellers')->where('status', 'active')->whereNotNull('bot_token')
            ->where(fn ($q) => $q->whereNull('webhook_secret')->orWhere('webhook_secret', ''))->count();
        $r[] = $noSecret === 0
            ? CheckResult::ok($g, 'resellers_have_webhook_secret')
            : CheckResult::warn($g, 'resellers_have_webhook_secret', "{$noSecret} نماینده‌ی فعال بدون webhook_secret: از پنل ادمین «Reconnect webhook» را بزنید.");

        $badHook = DB::table('resellers')->where('status', 'active')->where('webhook_status', 'failed')->count();
        $r[] = $badHook === 0
            ? CheckResult::ok($g, 'reseller_webhooks_registered')
            : CheckResult::warn($g, 'reseller_webhooks_registered', "{$badHook} نماینده با webhook_status=failed.");

        return $r;
    }

    /** @return list<string> */
    public function pendingMigrations(): array
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return ['(جدول migrations وجود ندارد)'];
            }
            $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
            $ran = $migrator->getRepository()->getRan();

            return array_values(array_diff(array_keys($files), $ran));
        } catch (Throwable $e) {
            return ['(خطا در خواندن وضعیت Migration: '.$e::class.')'];
        }
    }
}
