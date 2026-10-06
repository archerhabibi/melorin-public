<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B6.2 — Branding: دو دارایی تصویریِ اختیاری کنار `logo_path`.
 *
 *  - logo_dark_path: لوگوی مخصوص حالت تیره (NULL = همان لوگوی اصلی).
 *  - favicon_path:   Favicon اختصاصی فروشگاه (NULL = بدون Favicon اختصاصی، رفتار قبلی).
 *
 * ردیف‌های موجود بدون backfill درست‌اند (NULL = رفتار قبلی). Idempotent مثل Migrationهای قبلی.
 */
return new class extends Migration
{
    private const COLUMNS = ['logo_dark_path', 'favicon_path'];

    public function up(): void
    {
        if (! Schema::hasTable('reseller_website_settings')) {
            return;
        }

        Schema::table('reseller_website_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('reseller_website_settings', 'logo_dark_path')) {
                $table->string('logo_dark_path')->nullable();
            }
            if (! Schema::hasColumn('reseller_website_settings', 'favicon_path')) {
                $table->string('favicon_path')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reseller_website_settings')) {
            return;
        }

        $existing = array_values(array_filter(
            self::COLUMNS,
            fn (string $column) => Schema::hasColumn('reseller_website_settings', $column),
        ));

        if ($existing !== []) {
            Schema::table('reseller_website_settings', fn (Blueprint $table) => $table->dropColumn($existing));
        }
    }
};
