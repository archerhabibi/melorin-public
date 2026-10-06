<?php

namespace Tests\Feature\Website;

use App\Models\Payment;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\ResellerWebsiteSetting;
use App\Models\User;
use App\Services\Resellers\Branding\StoreBrandResolver;
use App\Support\Branding\BrandColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B6.2 — لایه‌ی وب برندینگ: رنگ متن/لینک، لوگوی افقی و حالت تیره، Favicon، فرم تنظیمات.
 */
class ResellerBrandAssetsWebTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Reseller $reseller): User
    {
        $owner = User::factory()->create();
        ResellerAdmin::create(['reseller_id' => $reseller->id, 'user_id' => $owner->id, 'role' => 'owner']);

        return $owner;
    }

    private function url(Reseller $reseller): string
    {
        return route('website.store.manage.branding.update', $reseller->slug);
    }

    // ─── رنگ ──────────────────────────────────────────────────

    #[Test]
    public function the_layout_prints_calculated_readable_text_colors_next_to_the_chosen_brand_color(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'brand_color' => '#ffff00']);

        $html = $this->get(route('website.store.home', $reseller->slug))->assertOk()->getContent();

        // رنگ دکمه دقیقاً انتخاب نماینده است؛ رنگ متن روی سطح روشن تیره‌تر می‌شود، روی سطح تیره همان می‌ماند.
        $this->assertStringContainsString('--brand: #ffff00;', $html);
        $this->assertStringContainsString('--brand-text: '.BrandColor::textOnLight('#ffff00').';', $html);
        $this->assertStringContainsString('--brand-text-dark: #ffff00;', $html);
        $this->assertNotSame('#ffff00', BrandColor::textOnLight('#ffff00'));
    }

    #[Test]
    public function the_main_store_gets_readable_text_colors_for_the_default_brand(): void
    {
        $html = $this->get(route('website.home'))->assertOk()->getContent();

        $this->assertStringContainsString('--brand-text: '.BrandColor::textOnLight(BrandColor::DEFAULT).';', $html);
        $this->assertStringContainsString('--brand-text-dark: '.BrandColor::textOnDark(BrandColor::DEFAULT).';', $html);
    }

    #[Test]
    public function an_invalid_stored_color_never_reaches_the_derived_text_colors(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'brand_color' => 'red;}a{']);

        $html = $this->get(route('website.store.home', $reseller->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('red;}a{', $html);
        $this->assertStringContainsString('--brand-text: '.BrandColor::textOnLight(BrandColor::DEFAULT).';', $html);
    }

    #[Test]
    public function the_stylesheet_source_uses_the_derived_colors_for_brand_text_and_links(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.link-brand { color: var(--brand-text, var(--brand));', $css);
        $this->assertStringContainsString(':root .text-brand { color: var(--brand-text, var(--brand)); }', $css);
        $this->assertStringContainsString('var(--brand-text-dark,', $css);
    }

    // ─── لوگو ─────────────────────────────────────────────────

    #[Test]
    public function the_header_logo_keeps_its_aspect_ratio_instead_of_a_forced_square(): void
    {
        Storage::fake('public');
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'logo_path' => 'reseller-logos/1/wide.png']);

        $html = $this->get(route('website.store.home', $reseller->slug))->assertOk()->getContent();

        $this->assertStringContainsString('h-8 w-auto', $html);
        $this->assertStringNotContainsString('h-8 w-8 rounded object-contain', $html);
    }

    #[Test]
    public function without_a_dark_logo_only_one_logo_image_is_printed(): void
    {
        Storage::fake('public');
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'logo_path' => 'reseller-logos/1/l.png']);

        $html = $this->get(route('website.store.home', $reseller->slug))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="brand-logo '));
        $this->assertStringNotContainsString('brand-logo-dark', $html);
        $this->assertStringNotContainsString('brand-logo-light', $html);
    }

    #[Test]
    public function with_a_dark_logo_both_images_are_printed_with_theme_classes(): void
    {
        Storage::fake('public');
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create([
            'reseller_id' => $reseller->id, 'logo_path' => 'reseller-logos/1/l.png', 'logo_dark_path' => 'reseller-logos/1/d.png',
        ]);

        $html = $this->get(route('website.store.home', $reseller->slug))->assertOk()->getContent();

        $this->assertStringContainsString('reseller-logos/1/l.png', $html);
        $this->assertStringContainsString('reseller-logos/1/d.png', $html);
        $this->assertSame(1, substr_count($html, 'brand-logo-light'));
        $this->assertSame(1, substr_count($html, 'brand-logo brand-logo-dark'));
    }

    #[Test]
    public function a_dark_logo_without_a_main_logo_is_never_printed(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'logo_dark_path' => 'reseller-logos/1/d.png']);

        $this->get(route('website.store.home', $reseller->slug))->assertOk()
            ->assertDontSee('reseller-logos/1/d.png');
    }

    // ─── Favicon ──────────────────────────────────────────────

    #[Test]
    public function a_favicon_is_linked_only_when_the_reseller_uploaded_one(): void
    {
        Storage::fake('public');
        $reseller = Reseller::factory()->create();

        $this->get(route('website.store.home', $reseller->slug))->assertOk()->assertDontSee('rel="icon"', false);
        $this->get(route('website.home'))->assertOk()->assertDontSee('rel="icon"', false);

        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'favicon_path' => 'reseller-favicons/1/f.png']);
        app(StoreBrandResolver::class)->forget(); // Resolver در تست بین دو درخواستِ یک تست مشترک می‌ماند؛ در تولید هر Request تازه است.

        $html = $this->get(route('website.store.home', $reseller->slug))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'rel="icon"'));
        $this->assertStringContainsString('reseller-favicons/1/f.png', $html);
    }

    #[Test]
    public function the_payment_result_page_uses_the_origin_stores_text_colors_and_favicon(): void
    {
        // Favicon/رنگ‌ها از همان Partial می‌آیند؛ صفحه‌ی نتیجه‌ی بدون Payment برند ندارد (S-03).
        $this->get('/payment/zarinpal/callback?payment_id=999999&Authority=X&Status=OK')
            ->assertNotFound()
            ->assertDontSee('--brand-text', false)
            ->assertDontSee('rel="icon"', false);
    }

    // ─── فرم و ذخیره ──────────────────────────────────────────

    #[Test]
    public function an_admin_can_upload_all_three_assets_in_one_save(): void
    {
        Storage::fake('public');
        $reseller = Reseller::factory()->create();
        $owner = $this->owner($reseller);

        $this->actingAs($owner)->post($this->url($reseller), [
            'display_name' => 'فروشگاه تصویری',
            'logo' => UploadedFile::fake()->image('l.png', 200, 80),
            'logo_dark' => UploadedFile::fake()->image('d.png', 200, 80),
            'favicon' => UploadedFile::fake()->image('f.png', 64, 64),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $setting = ResellerWebsiteSetting::forReseller($reseller);
        Storage::disk('public')->assertExists([$setting->logo_path, $setting->logo_dark_path, $setting->favicon_path]);
    }

    #[Test]
    public function a_dark_logo_alone_is_rejected_with_a_field_error_and_nothing_is_written(): void
    {
        Storage::fake('public');
        $reseller = Reseller::factory()->create();

        $this->actingAs($this->owner($reseller))->post($this->url($reseller), [
            'display_name' => 'x',
            'logo_dark' => UploadedFile::fake()->image('d.png', 64, 64),
        ])->assertSessionHasErrors('logo_dark');

        $this->assertNull(ResellerWebsiteSetting::forReseller($reseller));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function an_invalid_favicon_is_rejected_by_the_form(): void
    {
        Storage::fake('public');
        $reseller = Reseller::factory()->create();

        $this->actingAs($this->owner($reseller))->post($this->url($reseller), [
            'favicon' => UploadedFile::fake()->image('f.png', 64, 32),
        ])->assertSessionHasErrors('favicon');

        $this->assertNull(ResellerWebsiteSetting::forReseller($reseller));
    }

    #[Test]
    public function a_stranger_cannot_upload_assets_and_nothing_is_stored(): void
    {
        Storage::fake('public');
        $reseller = Reseller::factory()->create();

        $this->actingAs(User::factory()->create())->post($this->url($reseller), [
            'logo' => UploadedFile::fake()->image('l.png', 64, 64),
            'favicon' => UploadedFile::fake()->image('f.png', 64, 64),
        ])->assertForbidden();

        $this->assertNull(ResellerWebsiteSetting::forReseller($reseller));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function the_settings_page_shows_the_new_fields_and_the_color_readability_report(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'brand_color' => '#ffff00']);

        $response = $this->actingAs($this->owner($reseller))
            ->get(route('website.store.manage.branding', $reseller->slug))->assertOk();

        $response->assertSee('name="logo_dark"', false)
            ->assertSee('name="favicon"', false)
            ->assertSee('خوانایی رنگ برند')
            ->assertSee(BrandColor::textOnLight('#ffff00'));
    }

    #[Test]
    public function the_report_says_readable_when_no_adjustment_is_needed(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'brand_color' => '#2563eb']);

        $html = $this->actingAs($this->owner($reseller))
            ->get(route('website.store.manage.branding', $reseller->slug))->assertOk()->getContent();

        // روی سطح روشن بی‌نیاز از اصلاح (نشان «خوانا»)، روی سطح تیره اصلاح می‌شود.
        $this->assertStringContainsString('خوانا', $html);
        $this->assertStringContainsString(BrandColor::textOnDark('#2563eb'), $html);
    }

    #[Test]
    public function the_settings_page_offers_remove_checkboxes_only_for_existing_assets(): void
    {
        Storage::fake('public');
        $reseller = Reseller::factory()->create();
        $owner = $this->owner($reseller);

        $empty = $this->actingAs($owner)->get(route('website.store.manage.branding', $reseller->slug))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="remove_logo_dark"', $empty);
        $this->assertStringNotContainsString('name="remove_favicon"', $empty);

        ResellerWebsiteSetting::create([
            'reseller_id' => $reseller->id, 'logo_path' => 'reseller-logos/1/l.png',
            'logo_dark_path' => 'reseller-logos/1/d.png', 'favicon_path' => 'reseller-favicons/1/f.png',
        ]);
        app(StoreBrandResolver::class)->forget();

        $full = $this->actingAs($owner)->get(route('website.store.manage.branding', $reseller->slug))->assertOk()->getContent();
        $this->assertStringContainsString('name="remove_logo_dark"', $full);
        $this->assertStringContainsString('name="remove_favicon"', $full);
    }
}
