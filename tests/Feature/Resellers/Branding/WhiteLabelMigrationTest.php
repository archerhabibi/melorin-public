<?php

namespace Tests\Feature\Resellers\Branding;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** B5.7: Migration دو ستونِ SEO باید ایدمپوتنت باشد (اجرای دوباره روی دیتابیسِ دارای ستون نشکند). */
class WhiteLabelMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_07_000001_add_white_label_foundation_to_reseller_website_settings.php');
    }

    #[Test]
    public function columns_exist_after_migrate_with_safe_defaults(): void
    {
        $this->assertTrue(Schema::hasColumns('reseller_website_settings', ['allow_indexing', 'meta_description']));
    }

    #[Test]
    public function up_twice_does_not_fail(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('reseller_website_settings', 'allow_indexing'));
    }

    #[Test]
    public function down_is_safe_when_columns_are_already_gone(): void
    {
        $migration = $this->migration();
        $migration->down();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('reseller_website_settings', 'allow_indexing'));
        $this->assertFalse(Schema::hasColumn('reseller_website_settings', 'meta_description'));
    }
}
