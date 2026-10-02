<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * قفل ایمنیِ آخر: پیش از اینکه هیچ trait تستی (مثل RefreshDatabase)
     * فرصت کند به دیتابیس وصل شود یا migrate کند، بررسی می‌کنیم که
     * واقعاً روی sqlite :memory: هستیم — و بس. عمداً هیچ استثنایی (مثل
     * نام دیتابیسی که شامل «testing» باشد) پذیرفته نمی‌شود: تنها راه
     * صددرصد مطمئن برای اینکه یک migrate/RefreshDatabase هرگز به یک
     * دیتابیس MySQL واقعی (حتی یک دیتابیس تستیِ دستی مثل
     * melorin_testing) نرسد، محدود کردن تست‌ها به یک دیتابیس کاملاً
     * موقت در RAM است که با پایان فرآیند PHP از بین می‌رود.
     *
     * چرا اینجا و نه در setUp()؟ چون Illuminate\Foundation\Testing\TestCase::setUp()
     * ترتیب زیر را دارد:
     *   1) refreshApplication() → این متد createApplication() را صدا می‌زند
     *   2) setUpTraits() → دقیقاً همین‌جا RefreshDatabase migrate می‌کند
     * یعنی اگر بخواهیم بعد از parent::setUp() چک کنیم، migrate از قبل
     * انجام شده. با override کردن createApplication() و throw کردن خطا
     * *پیش از* بازگرداندن $app، تضمین می‌شود که setUpTraits() و در نتیجه
     * RefreshDatabase هرگز اجرا نمی‌شود اگر اتصال ناامن باشد — یعنی حتی
     * اگر phpunit.xml یا .env دوباره اشتباه تنظیم شوند، تست‌ها فوراً با
     * خطا متوقف می‌شوند و دست به هیچ دیتابیسی نمی‌زنند.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $this->guardAgainstNonTestDatabase($app);

        return $app;
    }

    /**
     * مسیر اختیاریِ تست روی MySQL/MariaDB واقعی (برای تست‌های concurrency و
     * lockForUpdate که sqlite توان بررسی‌شان را ندارد). پیش‌فرض بسته است و
     * فقط وقتی باز می‌شود که *هر چهار* شرط هم‌زمان برقرار باشد:
     *   1) متغیر محیطی MELORIN_TEST_ALLOW_MYSQL دقیقاً برابر 1 باشد
     *      (در phpunit.xml تنظیم نشده؛ فقط با export در shell/CI)
     *   2) اتصال mysql یا mariadb باشد
     *   3) نام دیتابیس دقیقاً melorin_ci_test باشد (نه prefix، نه شبیه آن)
     *   4) میزبان فقط محلی باشد: 127.0.0.1 ، localhost یا ::1
     * هیچ‌کدام از این‌ها با .env یا phpunit.xml ست نمی‌شود، پس یک اشتباه در
     * پیکربندی نمی‌تواند تست‌ها را به دیتابیس تولید برساند.
     */
    private function isExplicitCiDatabase($app, string $connection, string $database): bool
    {
        if (getenv('MELORIN_TEST_ALLOW_MYSQL') !== '1') {
            return false;
        }

        $host = (string) $app['config']->get("database.connections.{$connection}.host");

        return in_array($connection, ['mysql', 'mariadb'], true)
            && $database === 'melorin_ci_test'
            && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    private function guardAgainstNonTestDatabase($app): void
    {
        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        $isSafe = ($connection === 'sqlite' && $database === ':memory:')
            || $this->isExplicitCiDatabase($app, $connection, $database);

        if (! $isSafe) {
            throw new RuntimeException(
                "🔴 SAFETY ABORT: تست‌ها می‌خواستند با اتصال '{$connection}' به دیتابیس ".
                "'{$database}' وصل شوند که دیتابیس واقعی/تولید به‌نظر می‌رسد. ".
                'برای جلوگیری از پاک‌شدن اطلاعات واقعی، اجرای تست متوقف شد. '.
                'تنها اتصال مجاز برای تست، sqlite :memory: است؛ استثنا فقط با '.
                'MELORIN_TEST_ALLOW_MYSQL=1 روی دیتابیس محلیِ melorin_ci_test. '.
                "phpunit.xml را بررسی کنید — باید داشته باشد:\n".
                "  <env name=\"DB_CONNECTION\" value=\"sqlite\"/>\n".
                '  <env name="DB_DATABASE" value=":memory:"/>'
            );
        }
    }
}
