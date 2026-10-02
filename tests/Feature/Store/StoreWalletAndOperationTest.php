<?php

namespace Tests\Feature\Store;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Operation;
use App\Models\Reseller;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Core\OperationService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بند ۵۸ (Wallet Tests) و بند ۲۶ (Idempotency) بلوپرینت.
 */
class StoreWalletAndOperationTest extends TestCase
{
    use RefreshDatabase;

    protected WalletService $wallet;

    protected IdentityService $identity;

    protected OperationService $operations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = app(WalletService::class);
        $this->identity = app(IdentityService::class);
        $this->operations = app(OperationService::class);
    }

    /**
     * مهم‌ترین تست این فاز. پولی که مشتری به نماینده‌ی A داده، به هیچ
     * عنوان نباید در فروشگاه اصلی یا نزد نماینده‌ی B قابل خرج‌کردن باشد.
     * در معماری قبلی این تفکیک اصلاً وجود نداشت — یک کیف‌پول برای هر
     * User و تمام.
     */
    #[Test]
    public function wallets_of_the_same_person_in_different_stores_are_completely_separate(): void
    {
        $user = User::factory()->create();
        $resellerA = Reseller::factory()->create();

        $main = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $inA = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($resellerA));

        $this->wallet->credit($main, 100000);
        $this->wallet->credit($inA, 30000);

        $this->assertEquals(100000, $this->wallet->getBalance($main));
        $this->assertEquals(30000, $this->wallet->getBalance($inA));

        // خرج‌کردن در فروشگاه اصلی نباید کیف‌پول نمایندگی را لمس کند
        $this->wallet->debit($main, 60000);

        $this->assertEquals(40000, $this->wallet->getBalance($main));
        $this->assertEquals(30000, $this->wallet->getBalance($inA));
    }

    #[Test]
    public function debit_beyond_balance_is_rejected_and_leaves_the_ledger_untouched(): void
    {
        $user = User::factory()->create();
        $account = $this->identity->resolveCustomerAccount($user, StoreContext::main());

        $this->wallet->credit($account, 5000);

        $this->assertFalse($this->wallet->canDebit($account, 9000));

        try {
            $this->wallet->debit($account, 9000);
            $this->fail('کسر بیش از موجودی باید رد می‌شد.');
        } catch (InsufficientBalanceException) {
            // انتظار همین است
        }

        $this->assertEquals(5000, $this->wallet->getBalance($account));
        // فقط تراکنش شارژ اولیه باید ثبت شده باشد، نه کسر ناموفق
        $this->assertEquals(1, $this->wallet->walletFor($account)->transactions()->count());
    }

    /**
     * بند ۱۰ بلوپرینت: هر تراکنش باید balance_after داشته باشد تا گردش
     * حساب همیشه قابل بازسازی و راستی‌آزمایی باشد.
     */
    #[Test]
    public function the_ledger_stays_consistent_with_the_balance(): void
    {
        $user = User::factory()->create();
        $account = $this->identity->resolveCustomerAccount($user, StoreContext::main());

        $this->wallet->credit($account, 100000);
        $this->wallet->debit($account, 25000);
        $this->wallet->credit($account, 5000, 'refund');
        $this->wallet->adminAdjust($account, -10000);

        $wallet = $this->wallet->walletFor($account);
        $transactions = $wallet->transactions()->orderBy('id')->get();

        $this->assertEquals(70000, (int) $wallet->balance);
        $this->assertEquals(70000, (int) $transactions->last()->balance_after);

        // جمع تک‌تک تراکنش‌ها باید دقیقاً همان موجودی نهایی باشد
        $this->assertEquals(
            (int) $wallet->balance,
            (int) $transactions->sum('amount')
        );
    }

    #[Test]
    public function admin_adjustment_can_be_negative_but_never_below_zero(): void
    {
        $user = User::factory()->create();
        $account = $this->identity->resolveCustomerAccount($user, StoreContext::main());

        $this->wallet->credit($account, 20000);
        $this->wallet->adminAdjust($account, -15000);

        $this->assertEquals(5000, $this->wallet->getBalance($account));

        $this->expectException(InsufficientBalanceException::class);
        $this->wallet->adminAdjust($account, -99999);
    }

    #[Test]
    public function crediting_with_an_invalid_transaction_type_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = $this->identity->resolveCustomerAccount($user, StoreContext::main());

        $this->expectException(\InvalidArgumentException::class);
        $this->wallet->credit($account, 1000, 'purchase');
    }

    /* ------------------------------------------------------------------
     | Idempotency — بند ۲۶
     ------------------------------------------------------------------ */

    /**
     * سناریوی واقعی: کاربر دوبار روی دکمه‌ی خرید می‌زند، یا تلگرام همان
     * Update را دوباره می‌فرستد. نتیجه باید یک کسر باشد، نه دو تا.
     */
    #[Test]
    public function running_the_same_operation_twice_only_debits_once(): void
    {
        $user = User::factory()->create();
        $account = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->wallet->credit($account, 100000);

        $key = 'purchase:test:duplicate-click';
        $run = fn () => $this->operations->runOnce(
            $key,
            Operation::TYPE_PURCHASE,
            function (Operation $operation) use ($account) {
                $this->wallet->debit($account, 30000, 'purchase', null, null, $operation);

                return ['debited' => 30000];
            }
        );

        $first = $run();
        $second = $run();

        $this->assertEquals(['debited' => 30000], $first);
        $this->assertEquals(['debited' => 30000], $second);

        // فقط یک کسر واقعی رخ داده است
        $this->assertEquals(70000, $this->wallet->getBalance($account));
        $this->assertEquals(1, Operation::where('idempotency_key', $key)->count());
    }

    #[Test]
    public function a_wallet_transaction_records_which_operation_caused_it(): void
    {
        $user = User::factory()->create();
        $account = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->wallet->credit($account, 50000);

        $this->operations->runOnce(
            'purchase:test:traceable',
            Operation::TYPE_PURCHASE,
            function (Operation $operation) use ($account) {
                $this->wallet->debit($account, 20000, 'purchase', null, 'خرید آزمایشی', $operation);

                return true;
            }
        );

        $operation = Operation::where('idempotency_key', 'purchase:test:traceable')->firstOrFail();
        $transaction = WalletTransaction::where('operation_id', $operation->id)->firstOrFail();

        $this->assertEquals(-20000, (int) $transaction->amount);
        $this->assertTrue($operation->isCompleted());
    }

    /**
     * وقتی callback شکست می‌خورد، عملیات باید failed ثبت شود و خطا به
     * بالا برود — نه این‌که بی‌صدا completed علامت بخورد و تلاش مجدد را
     * برای همیشه مسدود کند.
     */
    #[Test]
    public function a_failing_operation_is_recorded_as_failed_and_rethrows(): void
    {
        $key = 'purchase:test:failing';

        try {
            $this->operations->runOnce($key, Operation::TYPE_PURCHASE, function () {
                throw new \RuntimeException('پنل در دسترس نیست');
            });
            $this->fail('خطای داخل callback باید دوباره پرتاب می‌شد.');
        } catch (\RuntimeException $e) {
            $this->assertEquals('پنل در دسترس نیست', $e->getMessage());
        }

        $operation = Operation::where('idempotency_key', $key)->firstOrFail();

        $this->assertTrue($operation->isFailed());
        $this->assertStringContainsString('پنل در دسترس نیست', $operation->last_error);
        $this->assertEquals(1, $operation->attempts);
    }

    /** بند ۸ بلوپرینت: انتقال بین کیف‌پول‌ها اصلاً وجود ندارد */
    #[Test]
    public function there_is_no_transfer_api_between_wallets(): void
    {
        $this->assertFalse(method_exists(WalletService::class, 'transfer'));
    }
}
