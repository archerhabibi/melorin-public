<?php

namespace Tests\Feature\Website;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\ResellerProductPrice;
use App\Models\ResellerWebsiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesTelegram;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * B5.7 — White Label Foundation (لایه‌ی Website/پنل/پرداخت). منطق در ResellerBrandingServiceTest.
 */
class ResellerWhiteLabelTest extends TestCase
{
    use FakesTelegram, InteractsWithWebsiteFixtures, RefreshDatabase, StoreMembers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->fakeTelegram();
    }

    private function owner(Reseller $reseller): User
    {
        $owner = User::factory()->create();
        ResellerAdmin::create(['reseller_id' => $reseller->id, 'user_id' => $owner->id, 'role' => 'owner']);

        return $owner;
    }

    /** پنل نماینده با Tenant واقعی (همان الگوی تست‌های ResellerPanel). */
    private function panelFor(Reseller $reseller): Panel
    {
        $panel = Filament::getPanel('reseller');
        Filament::setCurrentPanel($panel);

        ResellerAdmin::query()->firstOrCreate(['reseller_id' => $reseller->id, 'user_id' => $reseller->user->id], ['role' => 'owner']);
        $this->actingAs($reseller->user, 'reseller');
        Filament::setTenant($reseller->fresh());

        return $panel;
    }

    private function brandingUrl(Reseller $reseller): string
    {
        return route('website.store.manage.branding.update', $reseller->slug);
    }

    // ─── SEO: noindex پیش‌فرض و opt-in ──────────────────────────

    #[Test]
    public function a_store_is_noindex_by_default_and_prints_no_description_or_duplicate_robots(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'meta_description' => 'نباید چاپ شود']);

        $html = $this->get(route('website.store.home', $reseller->slug))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="robots"'));
        $this->assertStringContainsString('noindex, nofollow', $html);
        $this->assertStringNotContainsString('نباید چاپ شود', $html);
        $this->assertStringNotContainsString('name="description"', $html);
    }

    #[Test]
    public function an_opted_in_store_is_indexable_with_its_description_and_a_theme_color(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create([
            'reseller_id' => $reseller->id, 'allow_indexing' => true,
            'meta_description' => 'بهترین سرویس‌ها', 'brand_color' => '#aa3300',
        ]);

        $response = $this->get(route('website.store.home', $reseller->slug))->assertOk();

        $response->assertDontSee('noindex');
        $response->assertSee('<meta name="description" content="بهترین سرویس‌ها">', false);
        $response->assertSee('<meta name="theme-color" content="#aa3300">', false);
    }

    #[Test]
    public function a_description_with_markup_is_escaped_in_the_meta_tag(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create([
            'reseller_id' => $reseller->id, 'allow_indexing' => true,
            'meta_description' => '"><script>alert(1)</script>',
        ]);

        $this->get(route('website.store.home', $reseller->slug))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    #[Test]
    public function a_filtered_catalog_page_stays_noindex_even_for_an_indexable_store_and_has_one_robots_tag(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'allow_indexing' => true]);

        $filtered = $this->get(route('website.store.home', $reseller->slug).'?q=abc')->assertOk();
        $this->assertSame(1, substr_count($filtered->getContent(), 'name="robots"'));
        $filtered->assertSee('noindex,follow', false);

        $this->get(route('website.store.home', $reseller->slug))->assertOk()->assertDontSee('noindex');
    }

    #[Test]
    public function a_non_indexable_store_keeps_the_stricter_robots_rule_on_a_filtered_page(): void
    {
        $reseller = Reseller::factory()->create();

        $html = $this->get(route('website.store.home', $reseller->slug).'?q=abc')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="robots"'));
        $this->assertStringContainsString('content="noindex, nofollow"', $html, 'nofollow سخت‌گیرانه‌تر از noindex,follow صفحه است');
    }

    #[Test]
    public function the_main_store_is_unchanged_indexable_without_robots_or_description(): void
    {
        $html = $this->get(route('website.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="robots"', $html);
        $this->assertStringNotContainsString('name="description"', $html);
    }

    // ─── نشت نام پلتفرم ─────────────────────────────────────────

    #[Test]
    public function the_profile_page_in_a_reseller_store_does_not_show_the_platform_name_but_main_does(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'wl-shop']);
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'display_name' => 'برند من']);
        $member = $this->memberOf($reseller, ['email_verified_at' => now()]);
        $this->accountIn($member, null);

        $this->actingAs($member)->get(route('website.store.identity.profile.show', $reseller->slug))
            ->assertOk()
            ->assertSee('برند من')
            ->assertDontSee('Melorin')
            ->assertSee('عضویت در این فروشگاه از');

        $this->actingAs($member)->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertSee('عضو Melorin از');
    }

    // ─── صفحه‌ی تنظیمات ─────────────────────────────────────────

    #[Test]
    public function the_branding_page_shows_preview_readiness_and_seo_controls_to_the_owner_only(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'display_name' => 'پیش‌نمایش من']);
        $owner = $this->owner($reseller);

        $this->actingAs($owner)->get(route('website.store.manage.branding', $reseller->slug))
            ->assertOk()
            ->assertSee('پیش‌نمایش')
            ->assertSee('تکمیل برندینگ')
            ->assertSee('1 از 5', false)
            ->assertSee('name="allow_indexing"', false)
            ->assertSee('name="meta_description"', false)
            ->assertSee('پیش از روشن‌کردن ایندکس');

        $this->actingAs(User::factory()->create())
            ->get(route('website.store.manage.branding', $reseller->slug))->assertForbidden();
    }

    #[Test]
    public function the_indexing_warning_disappears_once_name_and_about_are_filled(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'display_name' => 'نام', 'about_text' => 'معرفی']);

        $this->actingAs($this->owner($reseller))->get(route('website.store.manage.branding', $reseller->slug))
            ->assertOk()->assertDontSee('پیش از روشن‌کردن ایندکس');
    }

    #[Test]
    public function saving_writes_audit_and_a_second_identical_save_says_nothing_changed(): void
    {
        $reseller = Reseller::factory()->create();
        $owner = $this->owner($reseller);
        $payload = ['display_name' => 'ثبت اول', 'allow_indexing' => '1', 'meta_description' => 'توضیح'];

        $this->actingAs($owner)->post($this->brandingUrl($reseller), $payload)
            ->assertRedirect()->assertSessionHas('status', 'اطلاعات فروشگاه به‌روزرسانی شد.');

        $this->actingAs($owner)->post($this->brandingUrl($reseller), $payload)
            ->assertRedirect()->assertSessionHas('status', 'تغییری برای ذخیره وجود نداشت.');

        $this->assertSame(1, AuditLog::query()->where('action', 'reseller.branding.updated')->count());
        $this->assertTrue(ResellerWebsiteSetting::forReseller($reseller)->allow_indexing);
    }

    #[Test]
    public function the_checkbox_hidden_field_turns_indexing_off(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'allow_indexing' => true]);

        $this->actingAs($this->owner($reseller))
            ->post($this->brandingUrl($reseller), ['allow_indexing' => '0']);

        $this->assertFalse(ResellerWebsiteSetting::forReseller($reseller)->allow_indexing);
        $this->get(route('website.store.home', $reseller->slug))->assertSee('noindex');
    }

    #[Test]
    public function the_remove_logo_checkbox_removes_the_logo_from_the_storefront(): void
    {
        $reseller = Reseller::factory()->create();
        $owner = $this->owner($reseller);
        $this->actingAs($owner)->post($this->brandingUrl($reseller), ['logo' => UploadedFile::fake()->image('l.png', 80, 80)]);
        $path = ResellerWebsiteSetting::forReseller($reseller)->logo_path;
        $this->get(route('website.store.home', $reseller->slug))->assertSee($path, false);

        $this->actingAs($owner)->post($this->brandingUrl($reseller), ['remove_logo' => '1'])->assertRedirect();

        Storage::disk('public')->assertMissing($path);
        $this->get(route('website.store.home', $reseller->slug))->assertDontSee($path, false);
    }

    #[Test]
    public function oversized_or_non_image_logos_and_overlong_fields_are_rejected_with_nothing_saved(): void
    {
        $reseller = Reseller::factory()->create();
        $owner = $this->owner($reseller);

        $this->actingAs($owner)->post($this->brandingUrl($reseller), ['logo' => UploadedFile::fake()->image('huge.png', 2500, 100)])
            ->assertSessionHasErrors('logo');
        $this->actingAs($owner)->post($this->brandingUrl($reseller), ['logo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('logo');
        $this->actingAs($owner)->post($this->brandingUrl($reseller), ['meta_description' => str_repeat('آ', 301)])
            ->assertSessionHasErrors('meta_description');
        $this->actingAs($owner)->post($this->brandingUrl($reseller), ['brand_color' => 'red'])
            ->assertSessionHasErrors('brand_color');

        $this->assertSame(0, ResellerWebsiteSetting::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_non_admin_cannot_change_branding_or_indexing(): void
    {
        $reseller = Reseller::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post($this->brandingUrl($reseller), ['allow_indexing' => '1'])->assertForbidden();

        $this->assertSame(0, ResellerWebsiteSetting::query()->count());
    }

    // ─── صفحه‌ی نتیجه‌ی پرداخت ──────────────────────────────────

    #[Test]
    public function the_gateway_result_page_uses_the_originating_stores_color_and_name(): void
    {
        $product = $this->makeSellableProduct();
        $reseller = Reseller::factory()->create(['status' => 'active']);
        ResellerProductPrice::create(['reseller_id' => $reseller->id, 'product_id' => $product->id, 'customers_price' => 130000, 'is_enabled' => true]);
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'display_name' => 'فروشگاه رنگی', 'brand_color' => '#aa3300']);
        Http::fake([
            '*/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'AUTH123']], 200),
            '*/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 998877]], 200),
        ]);
        $user = User::factory()->create(['telegram_id' => null, 'email_verified_at' => now()]);

        $this->actingAs($user)->post(route('website.store.wallet.charge.store', $reseller->slug), [
            'amount' => 150000, 'payment_method_id' => $this->makeZarinpalMethod()->id,
        ]);
        $payment = Payment::query()->where('user_id', $user->id)->firstOrFail();

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Authority=AUTH123&Status=OK')
            ->assertOk()
            ->assertSee('--brand: #aa3300', false)
            ->assertSee('فروشگاه رنگی')
            ->assertDontSee('Melorin');
    }

    #[Test]
    public function error_results_without_a_payment_carry_no_store_brand(): void
    {
        $this->get('/payment/zarinpal/callback?payment_id=999999&Authority=X&Status=OK')
            ->assertNotFound()
            ->assertDontSee('--brand:', false);
    }

    // ─── پنل نماینده ────────────────────────────────────────────

    #[Test]
    public function the_reseller_panel_uses_the_same_brand_name_logo_and_color_as_the_website(): void
    {
        $reseller = Reseller::factory()->create();

        // بدون برندینگ: همان نام قبلی پنل، بدون لوگو
        $panel = $this->panelFor($reseller);
        $this->assertSame($reseller->getFilamentName(), $panel->getBrandName());
        $this->assertNull($panel->getBrandLogo());

        $this->actingAs($this->owner($reseller))->post($this->brandingUrl($reseller), [
            'display_name' => 'نام پنل', 'brand_color' => '#aa3300',
            'logo' => UploadedFile::fake()->image('l.png', 60, 60),
        ])->assertSessionHasNoErrors();

        $panel = $this->panelFor($reseller);
        $this->assertSame('نام پنل', $panel->getBrandName());
        $this->assertStringContainsString(ResellerWebsiteSetting::forReseller($reseller)->logo_path, (string) $panel->getBrandLogo());
    }

    #[Test]
    public function the_panel_brand_does_not_leak_between_stores(): void
    {
        $a = Reseller::factory()->create();
        $b = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $a->id, 'display_name' => 'پنل آ']);

        $this->assertNotSame('پنل آ', $this->panelFor($b)->getBrandName());
        $this->assertSame('پنل آ', $this->panelFor($a)->getBrandName());
    }
}
