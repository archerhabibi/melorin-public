<?php

namespace Tests\Feature\Resellers\Branding;

use App\Models\AuditLog;
use App\Models\Reseller;
use App\Models\ResellerWebsiteSetting;
use App\Models\User;
use App\Services\Resellers\Branding\ResellerBrandingService;
use App\Services\Resellers\Branding\StoreBrandResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B6.2 — دارایی‌های تصویری برند (لوگو، لوگوی حالت تیره، Favicon) در Core.
 */
class BrandAssetsTest extends TestCase
{
    use RefreshDatabase;

    private ResellerBrandingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->service = app(ResellerBrandingService::class);
    }

    private function png(string $name, int $w = 64, int $h = 64): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    private function setting(Reseller $reseller): ?ResellerWebsiteSetting
    {
        return ResellerWebsiteSetting::forReseller($reseller->fresh());
    }

    private function brand(Reseller $reseller)
    {
        app(StoreBrandResolver::class)->forget($reseller);

        return app(StoreBrandResolver::class)->forReseller($reseller->fresh());
    }

    // ─── لوگوی تیره ───────────────────────────────────────────

    #[Test]
    public function a_dark_logo_is_stored_next_to_the_main_logo_and_exposed_by_the_brand(): void
    {
        $reseller = Reseller::factory()->create();

        $changed = $this->service->update($reseller, User::factory()->create(), [], $this->png('l.png'), false, $this->png('d.png'));

        $this->assertEqualsCanonicalizing(['logo_path', 'logo_dark_path'], $changed);
        $setting = $this->setting($reseller);
        Storage::disk('public')->assertExists($setting->logo_path);
        Storage::disk('public')->assertExists($setting->logo_dark_path);
        $this->assertNotSame($setting->logo_path, $setting->logo_dark_path);

        $brand = $this->brand($reseller);
        $this->assertTrue($brand->hasDarkLogo());
        $this->assertNotNull($brand->logoDarkUrl);
    }

    #[Test]
    public function a_dark_logo_without_any_main_logo_is_rejected_and_nothing_is_stored(): void
    {
        $reseller = Reseller::factory()->create();

        try {
            $this->service->update($reseller, User::factory()->create(), [], null, false, $this->png('d.png'));
            $this->fail('باید ValidationException می‌داد');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('logo_dark', $e->errors());
        }

        $this->assertNull($this->setting($reseller));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_dark_logo_may_be_added_later_next_to_an_existing_main_logo(): void
    {
        $reseller = Reseller::factory()->create();
        $actor = User::factory()->create();
        $this->service->update($reseller, $actor, [], $this->png('l.png'));

        $changed = $this->service->update($reseller, $actor, [], null, false, $this->png('d.png'));

        $this->assertSame(['logo_dark_path'], $changed);
    }

    #[Test]
    public function removing_the_main_logo_also_removes_the_dark_one_and_deletes_both_files(): void
    {
        $reseller = Reseller::factory()->create();
        $actor = User::factory()->create();
        $this->service->update($reseller, $actor, [], $this->png('l.png'), false, $this->png('d.png'));
        $before = $this->setting($reseller);

        $changed = $this->service->update($reseller, $actor, [], null, true);

        $this->assertEqualsCanonicalizing(['logo_path', 'logo_dark_path'], $changed);
        $after = $this->setting($reseller);
        $this->assertNull($after->logo_path);
        $this->assertNull($after->logo_dark_path);
        Storage::disk('public')->assertMissing($before->logo_path);
        Storage::disk('public')->assertMissing($before->logo_dark_path);
    }

    #[Test]
    public function removing_only_the_dark_logo_keeps_the_main_one(): void
    {
        $reseller = Reseller::factory()->create();
        $actor = User::factory()->create();
        $this->service->update($reseller, $actor, [], $this->png('l.png'), false, $this->png('d.png'));
        $before = $this->setting($reseller);

        $changed = $this->service->update($reseller, $actor, [], null, false, null, true);

        $this->assertSame(['logo_dark_path'], $changed);
        $after = $this->setting($reseller);
        $this->assertSame($before->logo_path, $after->logo_path);
        Storage::disk('public')->assertExists($before->logo_path);
        Storage::disk('public')->assertMissing($before->logo_dark_path);
        $this->assertFalse($this->brand($reseller)->hasDarkLogo());
    }

    #[Test]
    public function replacing_the_main_logo_in_the_same_request_keeps_the_dark_one(): void
    {
        $reseller = Reseller::factory()->create();
        $actor = User::factory()->create();
        $this->service->update($reseller, $actor, [], $this->png('l.png'), false, $this->png('d.png'));
        $before = $this->setting($reseller);

        $changed = $this->service->update($reseller, $actor, [], $this->png('l2.png'), true);

        // آپلود جدید بر حذف مقدم است (قرارداد W3) ⇒ لوگوی تیره دست‌نخورده.
        $this->assertSame(['logo_path'], $changed);
        $this->assertSame($before->logo_dark_path, $this->setting($reseller)->logo_dark_path);
        Storage::disk('public')->assertMissing($before->logo_path);
    }

    #[Test]
    public function an_orphan_dark_logo_row_is_not_exposed_by_the_brand(): void
    {
        $reseller = Reseller::factory()->create();
        ResellerWebsiteSetting::create(['reseller_id' => $reseller->id, 'logo_dark_path' => 'reseller-logos/x/d.png']);

        $this->assertNull($this->brand($reseller)->logoDarkUrl);
        $this->assertFalse($this->brand($reseller)->hasDarkLogo());
    }

    // ─── Favicon ──────────────────────────────────────────────

    #[Test]
    public function a_favicon_is_stored_in_its_own_folder_and_exposed_by_the_brand(): void
    {
        $reseller = Reseller::factory()->create();

        $changed = $this->service->update($reseller, User::factory()->create(), [], null, false, null, false, $this->png('f.png', 64, 64));

        $this->assertSame(['favicon_path'], $changed);
        $path = $this->setting($reseller)->favicon_path;
        $this->assertStringStartsWith('reseller-favicons/'.$reseller->id.'/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($this->brand($reseller)->faviconUrl);
    }

    #[Test]
    public function replacing_and_removing_a_favicon_cleans_up_the_old_file(): void
    {
        $reseller = Reseller::factory()->create();
        $actor = User::factory()->create();
        $this->service->update($reseller, $actor, [], null, false, null, false, $this->png('f1.png'));
        $first = $this->setting($reseller)->favicon_path;

        $this->service->update($reseller, $actor, [], null, false, null, false, $this->png('f2.png'));
        $second = $this->setting($reseller)->favicon_path;
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->service->update($reseller, $actor, [], null, false, null, false, null, true);
        $this->assertNull($this->setting($reseller)->favicon_path);
        Storage::disk('public')->assertMissing($second);
        $this->assertNull($this->brand($reseller)->faviconUrl);
    }

    #[Test]
    public function removing_something_that_does_not_exist_is_a_no_op_without_a_row_or_audit(): void
    {
        $reseller = Reseller::factory()->create();

        $changed = $this->service->update($reseller, User::factory()->create(), [], null, true, null, true, null, true);

        $this->assertSame([], $changed);
        $this->assertNull($this->setting($reseller));
        $this->assertSame(0, AuditLog::query()->where('action', 'reseller.branding.updated')->count());
    }

    // ─── اتمیک‌بودن و Audit ──────────────────────────────────

    #[Test]
    public function a_database_failure_deletes_every_new_file_and_keeps_the_old_ones(): void
    {
        $reseller = Reseller::factory()->create();
        $actor = User::factory()->create();
        $this->service->update($reseller, $actor, [], $this->png('l.png'), false, null, false, $this->png('f.png'));
        $before = $this->setting($reseller);
        $filesBefore = Storage::disk('public')->allFiles();

        // شکست DB پس از ذخیره‌ی فایل‌ها: Audit بعد از تراکنش است؛ پس خطا را داخل تراکنش با Listener شبیه‌سازی می‌کنیم.
        ResellerWebsiteSetting::updating(fn () => throw new \RuntimeException('db down'));
        ResellerWebsiteSetting::creating(fn () => throw new \RuntimeException('db down'));

        try {
            $this->service->update($reseller, $actor, [], $this->png('l2.png'), false, $this->png('d2.png'), false, $this->png('f2.png'));
            $this->fail('باید خطا می‌داد');
        } catch (\RuntimeException $e) {
            $this->assertSame('db down', $e->getMessage());
        } finally {
            // فقط همین دو Listener را برمی‌داریم (نه flushEventListeners که Observerهای دیگر را هم می‌برد).
            app('events')->forget('eloquent.updating: '.ResellerWebsiteSetting::class);
            app('events')->forget('eloquent.creating: '.ResellerWebsiteSetting::class);
        }

        $this->assertEqualsCanonicalizing($filesBefore, Storage::disk('public')->allFiles());
        $after = $this->setting($reseller);
        $this->assertSame($before->logo_path, $after->logo_path);
        $this->assertSame($before->favicon_path, $after->favicon_path);
        $this->assertNull($after->logo_dark_path);
    }

    #[Test]
    public function the_audit_lists_only_field_names_for_the_new_assets(): void
    {
        $reseller = Reseller::factory()->create();

        $this->service->update($reseller, User::factory()->create(), [], $this->png('l.png'), false, $this->png('d.png'), false, $this->png('f.png'));

        $log = AuditLog::query()->where('action', 'reseller.branding.updated')->latest('id')->firstOrFail();
        $fields = $log->after['fields'] ?? [];
        $this->assertEqualsCanonicalizing(['logo_path', 'logo_dark_path', 'favicon_path'], $fields);
        $this->assertStringNotContainsString('reseller-', json_encode($log->after));
    }

    // ─── قوانین فرم ───────────────────────────────────────────

    #[Test]
    public function the_favicon_rules_require_a_small_square_png_or_webp(): void
    {
        $rules = fn (UploadedFile $f) => validator(['favicon' => $f], ['favicon' => $this->service->rules()['favicon']])->passes();

        $this->assertTrue($rules($this->png('f.png', 64, 64)));
        $this->assertTrue($rules($this->png('f.png', 512, 512)));
        $this->assertFalse($rules($this->png('f.png', 64, 32)), 'غیرمربع');
        $this->assertFalse($rules($this->png('f.png', 16, 16)), 'کوچک‌تر از ۳۲');
        $this->assertFalse($rules($this->png('f.png', 600, 600)), 'بزرگ‌تر از ۵۱۲');
        $this->assertFalse($rules(UploadedFile::fake()->create('f.svg', 1, 'image/svg+xml')), 'SVG');
        $this->assertFalse($rules(UploadedFile::fake()->create('f.png', 300, 'image/png')), 'بزرگ‌تر از ۲۰۰ کیلوبایت');
    }

    #[Test]
    public function the_dark_logo_follows_the_same_rules_as_the_main_logo(): void
    {
        $this->assertSame($this->service->rules()['logo'], $this->service->rules()['logo_dark']);
    }

    // ─── Migration ────────────────────────────────────────────

    #[Test]
    public function the_migration_adds_nullable_columns_and_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_10_09_000001_add_brand_assets_to_reseller_website_settings.php');

        $this->assertTrue(Schema::hasColumns('reseller_website_settings', ['logo_dark_path', 'favicon_path']));

        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('reseller_website_settings', 'favicon_path'));

        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('reseller_website_settings', 'logo_dark_path'));
        $this->assertFalse(Schema::hasColumn('reseller_website_settings', 'favicon_path'));
    }
}
