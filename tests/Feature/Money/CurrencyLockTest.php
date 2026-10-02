<?php

namespace Tests\Feature\Money;

use App\Exceptions\CurrencyLockException;
use App\Support\CurrencyLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * قفل ارز: بعد از Migration مبالغ، تغییر code/decimals در config رد می‌شود.
 */
class CurrencyLockTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_migration_stores_the_signature(): void
    {
        $this->assertSame('IRT:0', CurrencyLock::signature());
        $this->assertSame('IRT:0', CurrencyLock::stored());
    }

    #[Test]
    public function verify_passes_when_config_matches(): void
    {
        CurrencyLock::verify();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function changing_decimals_after_migration_is_refused(): void
    {
        config(['melorin.currency.decimals' => 2]);

        $this->expectException(CurrencyLockException::class);
        $this->expectExceptionMessage('IRT:0');

        CurrencyLock::verify();
    }

    #[Test]
    public function changing_the_currency_code_after_migration_is_refused(): void
    {
        config(['melorin.currency.code' => 'USD']);

        $this->expectException(CurrencyLockException::class);

        CurrencyLock::verify();
    }

    #[Test]
    public function label_and_position_are_cosmetic_and_stay_changeable(): void
    {
        config(['melorin.currency.label' => 'Toman', 'melorin.currency.symbol_position' => 'before']);

        CurrencyLock::verify();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function before_the_money_migration_nothing_is_enforced(): void
    {
        DB::table(CurrencyLock::TABLE)->delete();
        config(['melorin.currency.code' => 'USD', 'melorin.currency.decimals' => 2]);

        CurrencyLock::verify();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function record_refuses_to_overwrite_a_different_signature(): void
    {
        config(['melorin.currency.code' => 'EUR', 'melorin.currency.decimals' => 2]);

        $this->expectException(CurrencyLockException::class);

        CurrencyLock::record();
    }
}
