<?php

namespace Tests\Feature\Provisioning;

use App\Filament\Pages\ProvisioningSettings;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\Admin;
use App\Models\Order;
use App\Models\ProvisioningSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FailurePolicyPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
    }

    #[Test]
    public function the_default_policy_is_retry(): void
    {
        $this->assertEquals(ProvisioningSetting::POLICY_RETRY, ProvisioningSetting::activePolicy());
    }

    #[Test]
    public function admin_can_choose_each_of_the_three_policies(): void
    {
        $this->actingAsAdmin();

        foreach (ProvisioningSetting::policies() as $policy) {
            Livewire::test(ProvisioningSettings::class)
                ->fillForm(['failure_policy' => $policy])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertEquals($policy, ProvisioningSetting::activePolicy());
        }
    }

    #[Test]
    public function an_unknown_policy_value_is_rejected_by_the_form(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ProvisioningSettings::class)
            ->fillForm(['failure_policy' => 'nonsense'])
            ->call('save')
            ->assertHasFormErrors(['failure_policy']);
    }

    #[Test]
    public function an_unknown_policy_stored_in_the_database_falls_back_to_retry(): void
    {
        ProvisioningSetting::current()->update(['failure_policy' => 'garbage']);

        $this->assertEquals(ProvisioningSetting::POLICY_RETRY, ProvisioningSetting::activePolicy());
    }

    #[Test]
    public function the_order_page_offers_refund_only_for_provision_failed_orders(): void
    {
        $this->actingAsAdmin();

        $failed = Order::factory()->create(['status' => Order::STATUS_PROVISION_FAILED]);
        $done = Order::factory()->create(['status' => Order::STATUS_ACCOUNT_CREATED]);

        Livewire::test(ViewOrder::class, ['record' => $failed->getRouteKey()])
            ->assertActionVisible('refundOrder')
            ->assertActionVisible('retryProvisioning');

        Livewire::test(ViewOrder::class, ['record' => $done->getRouteKey()])
            ->assertActionHidden('refundOrder')
            ->assertActionHidden('retryProvisioning');
    }
}
