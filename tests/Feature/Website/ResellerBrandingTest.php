<?php

namespace Tests\Feature\Website;

use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\ResellerWebsiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز W5 (نفر ۳) — بند ۴۶ زیرسند: «Reseller Website Branding — Name,
 * Logo, Contact Information».
 */
class ResellerBrandingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_reseller_without_any_branding_keeps_the_previous_default_name(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'no-branding']);

        $this->get(route('website.store.home', $reseller->slug))
            ->assertOk()
            ->assertSee('نمایندگی no-branding');
    }

    #[Test]
    public function a_configured_display_name_and_color_are_shown_instead_of_the_default(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'branded-shop']);

        ResellerWebsiteSetting::create([
            'reseller_id' => $reseller->id,
            'display_name' => 'فروشگاه ویژه‌ی نمونه',
            'brand_color' => '#ff0055',
            'contact_phone' => '02112345678',
            'contact_email' => 'support@example.test',
        ]);

        $response = $this->get(route('website.store.home', $reseller->slug))->assertOk();

        $response->assertSee('فروشگاه ویژه‌ی نمونه');
        $response->assertSee('--brand: #ff0055', false);
        $response->assertSee('02112345678');
        $response->assertSee('support@example.test');
    }

    #[Test]
    public function an_invalid_stored_color_falls_back_to_the_default_instead_of_reaching_the_stylesheet(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'brand_color' => 'red;} body{display:none']);

        $response = $this->get(route('website.store.home', $reseller->slug))->assertOk();

        $response->assertSee('--brand: #2563eb', false);
        $response->assertDontSee('display:none', false);
    }

    #[Test]
    public function the_main_store_never_shows_reseller_branding_or_the_noindex_tag(): void
    {
        $response = $this->get(route('website.home'))->assertOk();

        $response->assertSee('Melorin');
        $response->assertDontSee('noindex');
    }

    #[Test]
    public function a_reseller_store_page_is_marked_noindex(): void
    {
        $reseller = Reseller::factory()->create();

        $this->get(route('website.store.home', $reseller->slug))
            ->assertOk()
            ->assertSee('noindex');
    }

    #[Test]
    public function branding_of_one_reseller_never_leaks_into_another_resellers_pages(): void
    {
        $a = Reseller::factory()->create(['slug' => 'store-a']);
        $b = Reseller::factory()->create(['slug' => 'store-b']);

        ResellerWebsiteSetting::create(['reseller_id' => $a->id, 'display_name' => 'فروشگاه آ', 'brand_color' => '#111111']);
        ResellerWebsiteSetting::create(['reseller_id' => $b->id, 'display_name' => 'فروشگاه ب', 'brand_color' => '#222222']);

        $this->get(route('website.store.home', $a->slug))->assertOk()
            ->assertSee('فروشگاه آ')->assertDontSee('فروشگاه ب');

        $this->get(route('website.store.home', $b->slug))->assertOk()
            ->assertSee('فروشگاه ب')->assertDontSee('فروشگاه آ');
    }

    #[Test]
    public function the_admin_management_link_is_shown_only_to_that_resellers_own_admin(): void
    {
        $reseller = Reseller::factory()->create();
        $owner = User::factory()->create();
        ResellerAdmin::create(['reseller_id' => $reseller->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $stranger = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('website.store.home', $reseller->slug))
            ->assertSee('تنظیمات فروشگاه');

        $this->actingAs($stranger)
            ->get(route('website.store.home', $reseller->slug))
            ->assertDontSee('تنظیمات فروشگاه');

        $this->get(route('website.store.home', $reseller->slug))
            ->assertDontSee('تنظیمات فروشگاه');
    }

    #[Test]
    public function an_admin_can_update_branding_including_a_logo_upload(): void
    {
        Storage::fake('public');

        $reseller = Reseller::factory()->create();
        $owner = User::factory()->create();
        ResellerAdmin::create(['reseller_id' => $reseller->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $this->actingAs($owner)->post(route('website.store.manage.branding.update', $reseller->slug), [
            'display_name' => 'فروشگاه جدید',
            'brand_color' => '#00aa00',
            'contact_phone' => '09120000000',
            'contact_email' => 'shop@example.test',
            'about_text' => 'توضیحات کوتاه',
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ])->assertRedirect()->assertSessionHas('status');

        $setting = ResellerWebsiteSetting::forReseller($reseller->fresh());

        $this->assertEquals('فروشگاه جدید', $setting->display_name);
        $this->assertEquals('#00aa00', $setting->brand_color);
        $this->assertEquals('09120000000', $setting->contact_phone);
        Storage::disk('public')->assertExists($setting->logo_path);
    }

    #[Test]
    public function uploading_a_new_logo_deletes_the_previous_one(): void
    {
        Storage::fake('public');

        $reseller = Reseller::factory()->create();
        $owner = User::factory()->create();
        ResellerAdmin::create(['reseller_id' => $reseller->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $this->actingAs($owner)->post(route('website.store.manage.branding.update', $reseller->slug), [
            'logo' => UploadedFile::fake()->image('first.png'),
        ]);
        $firstPath = ResellerWebsiteSetting::forReseller($reseller)->logo_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($owner)->post(route('website.store.manage.branding.update', $reseller->slug), [
            'logo' => UploadedFile::fake()->image('second.png'),
        ]);

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists(ResellerWebsiteSetting::forReseller($reseller->fresh())->logo_path);
    }

    #[Test]
    public function a_non_admin_cannot_view_or_update_the_branding_page(): void
    {
        $reseller = Reseller::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('website.store.manage.branding', $reseller->slug))->assertForbidden();
        $this->actingAs($stranger)->post(route('website.store.manage.branding.update', $reseller->slug), [])->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_login_from_the_branding_page(): void
    {
        $reseller = Reseller::factory()->create();

        $this->get(route('website.store.manage.branding', $reseller->slug))->assertRedirect();
    }

    #[Test]
    public function the_management_area_does_not_exist_for_the_main_store(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get('/manage/branding')->assertNotFound();
    }
}
