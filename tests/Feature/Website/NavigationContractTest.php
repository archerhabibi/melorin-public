<?php

namespace Tests\Feature\Website;

use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B1.3 — Navigation Contract (منوی پنل کاربری + Breadcrumb).
 */
class NavigationContractTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());

        return $user;
    }

    #[Test]
    public function account_pages_show_a_breadcrumb_and_mark_the_current_menu_item(): void
    {
        $this->actingAs($this->customer())
            ->get(route('website.wallet.show'))
            ->assertOk()
            ->assertSee('aria-label="مسیر صفحه"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('حساب من');
    }

    #[Test]
    public function the_profile_page_is_reachable_from_the_account_menu(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->get(route('website.wallet.show'))
            ->assertOk()
            ->assertSee(route('website.identity.profile.show'), false);

        $this->actingAs($user)
            ->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertSee('پروفایل');
    }

    #[Test]
    public function the_header_links_a_logged_in_user_to_their_account_panel(): void
    {
        $this->actingAs($this->customer())
            ->get(route('website.home'))
            ->assertOk()
            ->assertSee(route('website.wallet.show'), false);
    }
}
