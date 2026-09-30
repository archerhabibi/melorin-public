<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاز W5 (نفر ۳) — بند ۴۶ زیرسند: «Reseller Website Branding — Name,
 * Logo, Contact Information». تا امروز این اطلاعات اصلاً جایی ذخیره
 * نمی‌شد؛ Layout سایت (فاز W0) برای Context نماینده فقط از
 * `Reseller::getFilamentName()` (که خودش یک fallback به slug است)
 * استفاده می‌کرد.
 *
 * جدولِ جدا (نه ستونِ اضافه روی `resellers`) — دقیقاً هم‌الگو با
 * `reseller_bot_settings`: تفکیکِ دغدغه‌ها (این یکی مخصوصِ کانالِ
 * Website است)، و ساده‌تر برای Migrationهای بعدی که فقط همین کانال
 * را لمس می‌کنند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_website_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('display_name', 100)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->text('about_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_website_settings');
    }
};
