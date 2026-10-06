<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B6.1 — Custom Domain: هر نماینده حداکثر یک دامنه‌ی اختصاصی دارد.
 *
 *  - custom_domain: hostِ نرمال‌شده (حروف کوچک، بدون scheme/path)؛ یکتا در کل پلتفرم.
 *  - custom_domain_token: توکن تأیید مالکیت (رکورد TXT)؛ فقط تا زمانی که دامنه‌ای ثبت است.
 *  - custom_domain_status: pending | verified (NULL = بدون دامنه). فقط verified مسیریابی می‌شود.
 *  - custom_domain_verified_at: زمان تأیید.
 *  - custom_domain_checked_at: pending ⇒ آخرین تلاش؛ verified ⇒ آخرین بار که TXT دوباره دیده شد (بازبررسی دوره‌ای).
 *  - custom_domain_claimed_at: زمان ثبت/ادعای دامنه؛ مبنای انقضای ادعای تأییدنشده (pending_ttl_hours).
 *
 * افزایشی و Idempotent؛ ردیف‌های موجود بدون backfill درست‌اند (NULL = رفتار قبلی).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reseller_website_settings')) {
            return;
        }

        Schema::table('reseller_website_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('reseller_website_settings', 'custom_domain')) {
                $table->string('custom_domain', 253)->nullable()->unique();
            }
            if (! Schema::hasColumn('reseller_website_settings', 'custom_domain_token')) {
                $table->string('custom_domain_token', 64)->nullable();
            }
            if (! Schema::hasColumn('reseller_website_settings', 'custom_domain_status')) {
                $table->string('custom_domain_status', 16)->nullable();
            }
            if (! Schema::hasColumn('reseller_website_settings', 'custom_domain_verified_at')) {
                $table->timestamp('custom_domain_verified_at')->nullable();
            }
            if (! Schema::hasColumn('reseller_website_settings', 'custom_domain_checked_at')) {
                $table->timestamp('custom_domain_checked_at')->nullable();
            }
            if (! Schema::hasColumn('reseller_website_settings', 'custom_domain_claimed_at')) {
                $table->timestamp('custom_domain_claimed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        $existing = array_values(array_filter(
            ['custom_domain_claimed_at', 'custom_domain_checked_at', 'custom_domain_verified_at', 'custom_domain_status', 'custom_domain_token'],
            fn (string $column) => Schema::hasColumn('reseller_website_settings', $column),
        ));

        if (Schema::hasColumn('reseller_website_settings', 'custom_domain')) {
            Schema::table('reseller_website_settings', fn (Blueprint $table) => $table->dropUnique(['custom_domain']));
            $existing[] = 'custom_domain';
        }

        if ($existing !== []) {
            Schema::table('reseller_website_settings', fn (Blueprint $table) => $table->dropColumn($existing));
        }
    }
};
