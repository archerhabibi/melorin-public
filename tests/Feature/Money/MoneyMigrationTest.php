<?php

namespace Tests\Feature\Money;

use App\Exceptions\CurrencyLockException;
use App\Support\CurrencyLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Migration مبالغ: اعشارِ غیرصفر نباید بی‌صدا گرد شود، و ارز باید قفل شود.
 * (RefreshDatabase خودش یک‌بار Migration را روی دیتابیس خالی اجرا کرده؛ این تست‌ها دوباره up() را صدا می‌زنند.)
 */
class MoneyMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * این تست‌ها عمداً up() را روی دیتابیسی که از قبل migrate شده دوباره اجرا
     * می‌کنند و مقدار اعشاری را در ستونی می‌ریزند که قبلاً integer شده؛ این فقط
     * روی sqlite (typing شل) معنا دارد. روی MySQL/MariaDB ستون BIGINT مقدار
     * را بی‌صدا گرد می‌کند و DDL هم commit ضمنی می‌زند، یعنی قفل ارز (USD:2)
     * از rollback تست فرار می‌کند و تمام تست‌های بعدی را مسموم می‌کند.
     * رفتار واقعی migration روی MySQL با migrate:fresh و migrate روی دیتای
     * قدیمی (مرحله Staging) بررسی می‌شود، نه با این تست.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('MoneyMigrationTest فقط روی sqlite معتبر است؛ روی MySQL با Staging تأیید می‌شود.');
        }
    }

    protected function migration(): object
    {
        return require base_path('database/migrations/2026_10_01_000001_convert_money_columns_to_integer_minor_unit.php');
    }

    protected function seedAffiliate(string $referrerBonus): void
    {
        DB::table('affiliate_settings')->delete();
        DB::table('affiliate_settings')->insert([
            'customer_bonus_amount' => 0,
            'referrer_bonus_amount' => $referrerBonus,
            'commission_percent' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_refuses_to_run_when_a_money_column_has_a_fraction(): void
    {
        $this->seedAffiliate('10.50');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('affiliate_settings.referrer_bonus_amount');

        $this->migration()->up();
    }

    #[Test]
    public function a_refused_run_changes_nothing(): void
    {
        $this->seedAffiliate('10.50');

        try {
            $this->migration()->up();
            $this->fail('باید متوقف می‌شد');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertEquals(10.5, (float) DB::table('affiliate_settings')->value('referrer_bonus_amount'));
    }

    #[Test]
    public function it_passes_when_every_value_is_a_whole_number(): void
    {
        $this->seedAffiliate('10000.00');

        $this->migration()->up();

        $this->assertEquals(10000, DB::table('affiliate_settings')->value('referrer_bonus_amount'));
    }

    #[Test]
    public function rounding_happens_only_when_explicitly_allowed_and_is_half_up(): void
    {
        config(['melorin.currency.allow_migration_rounding' => true]);
        $this->seedAffiliate('10.50');

        $this->migration()->up();

        $this->assertEquals(11, DB::table('affiliate_settings')->value('referrer_bonus_amount'));
    }

    #[Test]
    public function a_currency_with_decimals_is_refused_when_the_database_already_has_money_data(): void
    {
        DB::table(CurrencyLock::TABLE)->delete(); // شبیه دیتابیسی که هنوز قفل نشده
        $this->seedAffiliate('10000');
        config(['melorin.currency.code' => 'USD', 'melorin.currency.decimals' => 2]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MELORIN_CURRENCY_DECIMALS');

        $this->migration()->up();
    }

    #[Test]
    public function a_currency_with_decimals_is_fine_on_an_empty_database(): void
    {
        DB::table(CurrencyLock::TABLE)->delete();
        DB::table('affiliate_settings')->delete();
        config(['melorin.currency.code' => 'USD', 'melorin.currency.decimals' => 2]);

        $this->migration()->up();

        $this->assertSame('USD:2', CurrencyLock::stored());
    }

    #[Test]
    public function a_successful_run_locks_the_currency(): void
    {
        $this->assertSame('IRT:0', CurrencyLock::stored());
    }

    #[Test]
    public function running_with_a_different_currency_than_the_lock_is_refused_before_any_change(): void
    {
        $this->seedAffiliate('10.50'); // اگر قفل زودتر چک نشود، پیام «اعشار» می‌آمد
        config(['melorin.currency.code' => 'USD', 'melorin.currency.decimals' => 2]);

        $this->expectException(CurrencyLockException::class);

        $this->migration()->up();
    }

    #[Test]
    public function it_is_marked_irreversible(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IRREVERSIBLE');

        $this->migration()->down();
    }
}
