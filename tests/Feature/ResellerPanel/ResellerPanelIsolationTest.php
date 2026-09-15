<?php

namespace Tests\Feature\ResellerPanel;

use App\Exceptions\ResellerScopeViolationException;
use App\Filament\Reseller\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Reseller\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Reseller\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Reseller\Resources\ProductResource\Pages\ListProducts;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\User;
use App\Services\Core\PaymentService;
use App\Services\Core\WalletService;
use App\Services\Resellers\ResellerService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * پوشش دقیقاً همان «تست‌های اجباری» بند ۱۸ سند نیازمندی Reseller
 * Platform، این‌بار برای مسیر پنل وب (R5) نه ربات: نماینده‌ی A نباید
 * بتواند مشتری/سفارش/پرداخت نماینده‌ی B را ببیند یا تغییر دهد.
 */
class ResellerPanelIsolationTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // بدون این، Filament هنگام رندر Resourceهای پنل reseller در
        // تست (که مستقیم از طریق Livewire::test صدا زده می‌شوند، نه
        // یک درخواست HTTP واقعی) پنل فعلی را به پیش‌فرض ('admin')
        // برمی‌گرداند و لینک‌های داخلی را با نام Routeِ پنل اشتباه می‌سازد.
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
    }

    protected function ownerOf(Reseller $reseller): User
    {
        $owner = $reseller->user;
        ResellerAdmin::query()->firstOrCreate(
            ['reseller_id' => $reseller->id, 'user_id' => $owner->id],
            ['role' => 'owner']
        );

        return $owner;
    }

    /**
     * علاوه‌بر actingAs، از وقتی پنل به Filament Multi-Tenancy مجهز
     * شد، خودِ Tenant فعلی هم باید صریحاً در تست تنظیم شود — در یک
     * درخواست HTTP واقعی این کار را Filament از روی slug داخل URL
     * انجام می‌دهد، ولی Livewire::test() هیچ URLای را پیمایش نمی‌کند.
     */
    protected function actingAsReseller(Reseller $reseller): User
    {
        $owner = $this->ownerOf($reseller);
        $this->actingAs($owner, 'reseller');
        Filament::setTenant($reseller);

        return $owner;
    }

    #[Test]
    public function signed_login_link_logs_the_owner_into_only_their_own_reseller_panel(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'parismobile']);
        $owner = $this->ownerOf($reseller);

        $this->assertTrue(app(ResellerService::class)->isOwner($reseller, $owner));

        $url = URL::temporarySignedRoute('reseller.login', now()->addMinutes(10), ['reseller' => $reseller->id]);

        $response = $this->get($url);

        $response->assertRedirect('/parismobile');
        $this->assertAuthenticatedAs($owner, 'reseller');
    }

    #[Test]
    public function tampering_the_reseller_id_in_an_otherwise_valid_signed_link_is_rejected(): void
    {
        $reseller = Reseller::factory()->create();
        $this->ownerOf($reseller);
        $otherReseller = Reseller::factory()->create();

        $url = URL::temporarySignedRoute('reseller.login', now()->addMinutes(10), ['reseller' => $reseller->id]);
        // شبیه‌سازیِ جایگزینیِ id در URL پس از تولید امضا
        $tampered = str_replace((string) $reseller->id, (string) $otherReseller->id, $url);

        $this->get($tampered)->assertForbidden();
    }

    #[Test]
    public function an_expired_signed_login_link_is_rejected(): void
    {
        $reseller = Reseller::factory()->create();
        $this->ownerOf($reseller);

        $url = URL::temporarySignedRoute('reseller.login', now()->subMinute(), ['reseller' => $reseller->id]);

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function reseller_cannot_see_another_resellers_customers(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        $customerA = User::factory()->create(['reseller_id' => $resellerA->id]);
        $customerB = User::factory()->create(['reseller_id' => $resellerB->id]);

        $this->actingAsReseller($resellerA);

        Livewire::test(ListCustomers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$customerA])
            ->assertCanNotSeeTableRecords([$customerB]);
    }

    #[Test]
    public function reseller_cannot_see_another_resellers_orders(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        $orderA = Order::factory()->create(['reseller_id' => $resellerA->id]);
        $orderB = Order::factory()->create(['reseller_id' => $resellerB->id]);

        $this->actingAsReseller($resellerA);

        Livewire::test(ListOrders::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$orderA])
            ->assertCanNotSeeTableRecords([$orderB]);
    }

    #[Test]
    public function reseller_cannot_see_or_approve_another_resellers_pending_payments(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        $customerB = User::factory()->create(['reseller_id' => $resellerB->id]);
        $method = PaymentMethod::factory()->create();

        ['payment' => $paymentB] = app(PaymentService::class)->initiate(
            $customerB, $method, 300000, 'wallet_charge', reseller: $resellerB, walletOwnerType: 'user'
        );

        $this->actingAsReseller($resellerA);

        Livewire::test(ListPayments::class)
            ->assertSuccessful()
            ->assertCanNotSeeTableRecords([$paymentB]);

        // حتی اگر بخواهد مستقیم روی id آن اقدام کند، سرویس زیرین رد می‌کند
        $this->expectException(ResellerScopeViolationException::class);
        app(PaymentService::class)->confirmManualByReseller($paymentB, $resellerA);
    }

    #[Test]
    public function reseller_can_approve_their_own_customers_pending_payment_from_the_panel(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = app(PaymentService::class)->initiate(
            $customer, $method, 300000, 'wallet_charge', reseller: $reseller, walletOwnerType: 'user'
        );

        $this->fakeTelegram();
        $this->actingAsReseller($reseller);

        Livewire::test(ListPayments::class)
            ->callTableAction('approve', $payment)
            ->assertHasNoTableActionErrors();

        $this->assertEquals(300000, app(WalletService::class)->balance($customer));
    }

    #[Test]
    public function reseller_can_set_a_valid_selling_price_but_not_one_below_base_price(): void
    {
        $reseller = Reseller::factory()->create([
            'min_sale_price_rule' => ['min_price' => 110000],
        ]);
        $product = Product::factory()->create(['price' => 100000, 'status' => 'active']);

        $this->actingAsReseller($reseller);

        Livewire::test(ListProducts::class)
            ->callTableAction('set_price', $product, data: ['selling_price' => 130000])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(130000, $product->fresh()->sellingPriceForReseller($reseller));

        Livewire::test(ListProducts::class)
            ->callTableAction('set_price', $product, data: ['selling_price' => 105000]);

        // قیمت قبلی (معتبر) دست‌نخورده باقی مانده، چون تلاش دوم رد شده
        $this->assertEquals(130000, $product->fresh()->sellingPriceForReseller($reseller));
    }
}
