<?php

namespace Tests\Feature\Store;

use App\Models\CustomerAccount;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بند ۵۷ بلوپرینت — «same User + Main / Reseller A / Reseller B و
 * هیچ‌کدام با دیگری اشتباه نشوند.»
 *
 * این دقیقاً همان چیزی است که معماری قبلی (users.reseller_id) اصلاً
 * نمی‌توانست نمایش دهد: یک نفر فقط می‌توانست عضو یک فروشگاه باشد.
 */
class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    protected IdentityService $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = app(IdentityService::class);
    }

    #[Test]
    public function one_identity_can_be_a_customer_of_three_stores_at_once(): void
    {
        $user = User::factory()->create();
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        $main = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $inA = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($resellerA));
        $inB = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($resellerB));

        // سه عضویت کاملاً مجزا
        $this->assertCount(3, collect([$main->id, $inA->id, $inB->id])->unique());

        $this->assertTrue($main->isMainStore());
        $this->assertEquals($resellerA->id, $inA->reseller_id);
        $this->assertEquals($resellerB->id, $inB->reseller_id);
    }

    #[Test]
    public function resolving_the_same_store_twice_returns_the_same_account(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create();

        $first = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));
        $second = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, CustomerAccount::where('user_id', $user->id)->count());
    }

    /**
     * این تست مستقیماً از قفل دیتابیس محافظت می‌کند. اگر کسی روزی
     * unique را از migration بردارد، دو کیف‌پول موازی برای یک نفر ممکن
     * می‌شود — نوع باگی که ماه‌ها بی‌سروصدا می‌ماند و بعد به‌صورت پول
     * گم‌شده ظاهر می‌شود.
     */
    #[Test]
    public function the_database_refuses_a_duplicate_membership_in_the_same_store(): void
    {
        $user = User::factory()->create();

        CustomerAccount::create([
            'user_id' => $user->id,
            'store_type' => 'main',
            'reseller_id' => null,
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        CustomerAccount::create([
            'user_id' => $user->id,
            'store_type' => 'main',
            'reseller_id' => null,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function store_context_knows_when_a_reseller_store_is_not_operational(): void
    {
        $active = Reseller::factory()->create(['status' => 'active']);
        $inactive = Reseller::factory()->create(['status' => 'inactive']);

        $this->assertTrue(StoreContext::main()->isOperational());
        $this->assertTrue(StoreContext::reseller($active)->isOperational());
        $this->assertFalse(StoreContext::reseller($inactive)->isOperational());
    }

    #[Test]
    public function store_contexts_compare_by_store_not_by_object_identity(): void
    {
        $reseller = Reseller::factory()->create();

        $this->assertTrue(StoreContext::main()->equals(StoreContext::main()));
        $this->assertTrue(
            StoreContext::reseller($reseller)->equals(StoreContext::fromReseller($reseller))
        );
        $this->assertFalse(StoreContext::main()->equals(StoreContext::reseller($reseller)));
    }

    #[Test]
    public function a_guest_account_has_no_identity_until_it_is_attached(): void
    {
        $guest = $this->identity->createGuestAccount(StoreContext::main(), ['source' => 'website']);

        $this->assertTrue($guest->isGuest());

        $user = User::factory()->create();
        $attached = $this->identity->attachGuestToIdentity($guest, $user);

        $this->assertFalse($attached->isGuest());
        $this->assertEquals($user->id, $attached->user_id);
    }

    /**
     * حالت لبه‌ای که عمداً خطا می‌دهد به‌جای ادغام بی‌صدا: کاربر از قبل
     * در همین فروشگاه عضویت دارد. ادغام سفارش‌ها و کیف‌پول یک عملیات
     * مالی است و به فاز B موکول شده؛ تا آن موقع سکوت‌کردن و نصفه‌کاره
     * جابه‌جا کردن داده بدترین گزینه است.
     */
    #[Test]
    public function attaching_a_guest_to_an_identity_that_already_shops_here_is_refused(): void
    {
        $user = User::factory()->create();
        $this->identity->resolveCustomerAccount($user, StoreContext::main());

        $guest = $this->identity->createGuestAccount(StoreContext::main());

        $this->expectException(\RuntimeException::class);
        $this->identity->attachGuestToIdentity($guest, $user);
    }

    #[Test]
    public function identity_resolution_never_merges_accounts_across_stores(): void
    {
        $user = User::factory()->create(['telegram_id' => 55501, 'email' => 'a@example.test']);
        $reseller = Reseller::factory()->create();

        $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));

        // شناسایی Identity از روی تلگرام/ایمیل کار می‌کند...
        $this->assertEquals($user->id, $this->identity->findIdentity(telegramId: 55501)?->id);
        $this->assertEquals($user->id, $this->identity->findIdentity(email: 'a@example.test')?->id);

        // ...ولی عضویت‌ها همچنان دوتای مستقل می‌مانند (بند ۳۶)
        $this->assertCount(2, $this->identity->accountsOf($user));
    }
}
