<?php

namespace Tests\Feature\Resellers\Branding;

use App\Models\AuditLog;
use App\Models\Reseller;
use App\Models\ResellerWebsiteSetting;
use App\Models\User;
use App\Services\Core\Store\StoreContext;
use App\Services\Resellers\Branding\BrandingReadiness;
use App\Services\Resellers\Branding\ResellerBrandingService;
use App\Services\Resellers\Branding\StoreBrand;
use App\Services\Resellers\Branding\StoreBrandResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * B5.7 — White Label Foundation (Core): منبع واحد برندینگ، نوشتن امن، Audit، چک‌لیست تکمیل.
 */
class ResellerBrandingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ResellerBrandingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->service = app(ResellerBrandingService::class);
    }

    private function actor(): User
    {
        return User::factory()->create();
    }

    // ─── Resolver / StoreBrand ───────────────────────────────────

    #[Test]
    public function the_main_brand_is_the_platform_default_and_always_indexable(): void
    {
        $brand = app(StoreBrandResolver::class)->forContext(StoreContext::main());

        $this->assertTrue($brand->isMain);
        $this->assertSame('Melorin', $brand->name);
        $this->assertNull($brand->robots());
        $this->assertNull($brand->description());
    }

    #[Test]
    public function the_main_flag_alone_decides_indexing_regardless_of_the_opt_in_value(): void
    {
        $main = new StoreBrand('Melorin', null, '#2563eb', null, null, null, allowIndexing: false, metaDescription: 'x', isMain: true);
        $reseller = new StoreBrand('R', null, '#2563eb', null, null, null, allowIndexing: false, metaDescription: 'x', isMain: false);

        $this->assertNull($main->robots());
        $this->assertSame('noindex, nofollow', $reseller->robots());
    }

    #[Test]
    public function a_reseller_without_settings_keeps_the_default_label_and_is_not_indexable_and_nothing_is_written(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'plain']);

        $brand = app(StoreBrandResolver::class)->forReseller($reseller);

        $this->assertSame('نمایندگی plain', $brand->name);
        $this->assertFalse($brand->nameIsCustom);
        $this->assertSame('noindex, nofollow', $brand->robots());
        $this->assertSame(0, ResellerWebsiteSetting::query()->count(), 'خواندن برندینگ نباید رکورد بسازد');
    }

    #[Test]
    public function indexing_opt_in_removes_noindex_and_exposes_the_description_only_then(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'meta_description' => 'توضیح فروشگاه', 'allow_indexing' => false]);

        $off = app(StoreBrandResolver::class)->forReseller($reseller);
        $this->assertSame('noindex, nofollow', $off->robots());
        $this->assertNull($off->description(), 'بدون ایندکس، توضیح متا چاپ نمی‌شود');

        app(StoreBrandResolver::class)->forget($reseller);
        ResellerWebsiteSetting::query()->where('reseller_id', $reseller->id)->update(['allow_indexing' => true]);

        $on = app(StoreBrandResolver::class)->forReseller($reseller);
        $this->assertNull($on->robots());
        $this->assertSame('توضیح فروشگاه', $on->description());
    }

    #[Test]
    public function an_invalid_stored_color_falls_back_and_a_light_color_gets_dark_text(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'brand_color' => 'red;}a{']);
        $this->assertSame('#2563eb', app(StoreBrandResolver::class)->forReseller($reseller)->color);

        $light = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $light->id, 'brand_color' => '#ffff00']);
        $brand = app(StoreBrandResolver::class)->forReseller($light);
        $this->assertSame('#111827', $brand->onColor());
        $this->assertNotSame('#ffff00', $brand->panelColor(), 'رنگ پنل باید با متن سفید خوانا شود');
    }

    #[Test]
    public function the_resolver_reads_the_database_once_per_reseller_and_forget_refreshes_it(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'display_name' => 'اول']);
        $resolver = app(StoreBrandResolver::class);

        DB::enableQueryLog();
        $resolver->forReseller($reseller);
        $resolver->forReseller($reseller);
        $resolver->forContext(StoreContext::reseller($reseller));
        $this->assertCount(1, DB::getQueryLog(), 'سه بار پرسیدن = یک کوئری');
        DB::disableQueryLog();

        ResellerWebsiteSetting::query()->where('reseller_id', $reseller->id)->update(['display_name' => 'دوم']);
        $this->assertSame('اول', $resolver->forReseller($reseller)->name, 'تا forget، نسخه‌ی Request');

        $resolver->forget($reseller);
        $this->assertSame('دوم', $resolver->forReseller($reseller)->name);
    }

    #[Test]
    public function the_legacy_array_helper_still_returns_the_same_shape(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'display_name' => 'نام', 'contact_phone' => '021']);

        $legacy = ResellerWebsiteSetting::brandingFor($reseller);

        $this->assertSame(['name', 'logo_url', 'color', 'phone', 'email', 'about'], array_keys($legacy));
        $this->assertSame('نام', $legacy['name']);
        $this->assertSame('Melorin', ResellerWebsiteSetting::brandingFor(null)['name']);
    }

    // ─── Update ──────────────────────────────────────────────────

    #[Test]
    public function update_writes_whitelisted_fields_reports_changes_and_audits_names_only(): void
    {
        $reseller = Reseller::factory()->create();
        $actor = $this->actor();

        $changed = $this->service->update($reseller, $actor, [
            'display_name' => 'فروشگاه نمونه',
            'brand_color' => '#00aa00',
            'contact_phone' => '02112345678',
            'allow_indexing' => '1',
            'meta_description' => 'یک توضیح',
            'reseller_id' => 999999,   // نباید خوانده شود
            'logo_path' => 'evil.png', // نباید خوانده شود
        ]);

        $this->assertEqualsCanonicalizing(['display_name', 'brand_color', 'contact_phone', 'allow_indexing', 'meta_description'], $changed);

        $setting = ResellerWebsiteSetting::forReseller($reseller);
        $this->assertSame($reseller->id, $setting->reseller_id);
        $this->assertNull($setting->logo_path);
        $this->assertTrue($setting->allow_indexing);

        $log = AuditLog::query()->where('action', 'reseller.branding.updated')->firstOrFail();
        $this->assertSame($reseller->id, (int) $log->target_id);
        $this->assertSame('customer', $log->actor_type);
        $this->assertSame($actor->id, (int) $log->actor_id);
        $this->assertEqualsCanonicalizing($changed, $log->after['fields']);
        $this->assertStringNotContainsString('02112345678', json_encode($log->after), 'مقدار تماس در Audit ثبت نمی‌شود');
        $this->assertStringNotContainsString('فروشگاه نمونه', json_encode($log->after));
    }

    #[Test]
    public function an_unchanged_submission_writes_nothing_and_leaves_no_audit(): void
    {
        $reseller = Reseller::factory()->create();
        $actor = $this->actor();
        $this->service->update($reseller, $actor, ['display_name' => 'ثابت', 'allow_indexing' => '0']);
        $before = AuditLog::query()->where('action', 'reseller.branding.updated')->count();

        $this->assertSame([], $this->service->update($reseller, $actor, ['display_name' => '  ثابت  ', 'allow_indexing' => '0']));
        $this->assertSame($before, AuditLog::query()->where('action', 'reseller.branding.updated')->count());
    }

    #[Test]
    public function submitting_only_defaults_to_a_reseller_without_a_row_creates_no_row(): void
    {
        $reseller = Reseller::factory()->create();

        $changed = $this->service->update($reseller, $this->actor(), ['display_name' => '', 'allow_indexing' => '0', 'about_text' => '   ']);

        $this->assertSame([], $changed);
        $this->assertSame(0, ResellerWebsiteSetting::query()->count());
    }

    #[Test]
    public function empty_text_clears_the_field_and_hostile_characters_are_stripped_but_zwnj_stays(): void
    {
        $reseller = Reseller::factory()->create();
        $this->service->update($reseller, $this->actor(), [
            'display_name' => 'فروشگاه',
            'contact_phone' => '021',
        ]);

        $this->service->update($reseller, $this->actor(), [
            'display_name' => "  می‌فروشم\u{202E}\u{200B}  خوب\x07 ",
            'contact_phone' => '',
        ]);

        $setting = ResellerWebsiteSetting::forReseller($reseller);
        $this->assertSame('می‌فروشم خوب', $setting->display_name, 'جهت‌دهی/صفر-عرض/کنترل حذف، نیم‌فاصله می‌ماند');
        $this->assertNull($setting->contact_phone);
    }

    #[Test]
    public function the_about_text_keeps_line_breaks_but_collapses_blank_runs(): void
    {
        $reseller = Reseller::factory()->create();

        $this->service->update($reseller, $this->actor(), [
            'about_text' => "\r\n\r\nخط اول  \r\n\r\n\r\n\r\nخط   دوم\x07\r\n\r\n",
        ]);

        $this->assertSame("خط اول\n\nخط دوم", ResellerWebsiteSetting::forReseller($reseller)->about_text);
    }

    #[Test]
    public function an_invalid_color_is_never_stored(): void
    {
        $reseller = Reseller::factory()->create();

        $this->service->update($reseller, $this->actor(), ['brand_color' => 'red;}a{']);

        $this->assertSame(0, ResellerWebsiteSetting::query()->count());
    }

    #[Test]
    public function updating_one_store_never_touches_another(): void
    {
        $a = Reseller::factory()->create();
        $b = Reseller::factory()->create();
        $this->service->update($b, $this->actor(), ['display_name' => 'ب', 'allow_indexing' => '1']);

        $this->service->update($a, $this->actor(), ['display_name' => 'آ']);

        $this->assertSame('ب', ResellerWebsiteSetting::forReseller($b)->display_name);
        $this->assertTrue(ResellerWebsiteSetting::forReseller($b)->allow_indexing);
        $this->assertFalse((bool) ResellerWebsiteSetting::forReseller($a)->allow_indexing);
    }

    #[Test]
    public function after_an_update_the_resolver_serves_the_new_brand_immediately(): void
    {
        $reseller = Reseller::factory()->create();
        $resolver = app(StoreBrandResolver::class);
        $this->assertFalse($resolver->forReseller($reseller)->nameIsCustom);

        $this->service->update($reseller, $this->actor(), ['display_name' => 'تازه']);

        $this->assertSame('تازه', $resolver->forReseller($reseller)->name);
    }

    // ─── Logo ────────────────────────────────────────────────────

    #[Test]
    public function a_new_logo_replaces_and_deletes_the_old_file_only_after_success(): void
    {
        $reseller = Reseller::factory()->create();
        $this->service->update($reseller, $this->actor(), [], UploadedFile::fake()->image('a.png', 50, 50));
        $first = ResellerWebsiteSetting::forReseller($reseller)->logo_path;

        $changed = $this->service->update($reseller, $this->actor(), [], UploadedFile::fake()->image('b.png', 50, 50));

        $this->assertSame(['logo_path'], $changed);
        $second = ResellerWebsiteSetting::forReseller($reseller)->logo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function a_failed_save_keeps_the_old_logo_and_cleans_the_new_upload(): void
    {
        $reseller = Reseller::factory()->create();
        $this->service->update($reseller, $this->actor(), [], UploadedFile::fake()->image('a.png', 50, 50));
        $old = ResellerWebsiteSetting::forReseller($reseller)->logo_path;

        ResellerWebsiteSetting::saving(fn () => throw new RuntimeException('db down'));

        try {
            $this->service->update($reseller, $this->actor(), [], UploadedFile::fake()->image('b.png', 50, 50));
            $this->fail('باید خطا می‌داد');
        } catch (RuntimeException) {
            // مورد انتظار
        } finally {
            ResellerWebsiteSetting::flushEventListeners();
        }

        Storage::disk('public')->assertExists($old);
        $this->assertCount(1, Storage::disk('public')->allFiles(), 'فایل تازه‌ی یتیم نباید بماند');
        $this->assertSame($old, ResellerWebsiteSetting::forReseller($reseller)->logo_path);
    }

    #[Test]
    public function remove_logo_deletes_the_file_and_clears_the_path_and_is_a_noop_without_a_logo(): void
    {
        $reseller = Reseller::factory()->create();
        $this->assertSame([], $this->service->update($reseller, $this->actor(), [], null, true));

        $this->service->update($reseller, $this->actor(), [], UploadedFile::fake()->image('a.png', 50, 50));
        $path = ResellerWebsiteSetting::forReseller($reseller)->logo_path;

        $this->assertSame(['logo_path'], $this->service->update($reseller, $this->actor(), [], null, true));

        $this->assertNull(ResellerWebsiteSetting::forReseller($reseller)->logo_path);
        Storage::disk('public')->assertMissing($path);
        $this->assertNull(app(StoreBrandResolver::class)->forReseller($reseller)->logoUrl);
    }

    #[Test]
    public function a_new_upload_wins_over_the_remove_flag(): void
    {
        $reseller = Reseller::factory()->create();
        $this->service->update($reseller, $this->actor(), [], UploadedFile::fake()->image('a.png', 50, 50));

        $this->service->update($reseller, $this->actor(), [], UploadedFile::fake()->image('b.png', 50, 50), true);

        $this->assertNotNull(ResellerWebsiteSetting::forReseller($reseller)->logo_path);
    }

    // ─── Readiness ───────────────────────────────────────────────

    #[Test]
    public function readiness_counts_only_values_the_reseller_provided(): void
    {
        $empty = BrandingReadiness::of(null);
        $this->assertSame(0, $empty->percent());
        $this->assertFalse($empty->indexingReady());
        $this->assertCount(5, $empty->missing());

        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create([
            'reseller_id' => $reseller->id,
            'display_name' => 'نام',
            'brand_color' => '#2563eb', // همان پیش‌فرض = انتخاب شخصی نیست
            'contact_email' => 'a@b.test',
            'about_text' => 'معرفی',
        ]);

        $r = $this->service->readiness($reseller);
        $this->assertSame(3, $r->doneCount());
        $this->assertSame(60, $r->percent());
        $this->assertTrue($r->indexingReady());
        $this->assertEqualsCanonicalizing(['لوگو', 'رنگ اصلی برند'], $r->missing());
    }

    #[Test]
    public function indexing_readiness_needs_a_custom_name_and_an_about_text(): void
    {
        $r = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $r->id, 'display_name' => 'نام']);

        $this->assertFalse($this->service->readiness($r)->indexingReady());
    }

    #[Test]
    public function the_brand_value_object_is_immutable(): void
    {
        $this->expectException(\Error::class);

        $brand = app(StoreBrandResolver::class)->forContext(StoreContext::main());
        /** @phpstan-ignore-next-line */
        $brand->name = 'x';
    }
}
