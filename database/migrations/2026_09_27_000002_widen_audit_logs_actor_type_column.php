<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاز W6 بند ۴ (نفر ۴) — پیش‌نیاز Audit روی «Identity Linking» و سایر
 * عملیات حساسی که یک مشتری Website (نه Admin/Reseller) انجام می‌دهد.
 *
 * `actor_type` یک ENUM محدود به admin/reseller/system بود —
 * `AuditService::resolveActor()` (همین پچ) حالا یک User را هم
 * می‌شناسد و 'customer' برمی‌گرداند، پس خودِ ستون هم باید این مقدار را
 * قبول کند وگرنه هر Insert با actor مشتری با خطای Constraint شکست
 * می‌خورد.
 *
 * از ENUM به یک `string` ساده تغییر داده شد (نه افزودن یک مقدار جدید
 * به همان ENUM) چون:
 * (۱) تغییر ENUM در MySQL نیاز به بازنویسی کامل تعریف ستون دارد (نه
 *     یک افزودن ساده)، پس هزینه‌اش با رفتن به string یکسان است؛
 * (۲) SQLite (محیط تست این پروژه) اصلاً ENUM واقعی در سطح دیتابیس
 *     ندارد؛ یک ستون string با اعتبارسنجی سطح اپلیکیشن (که همین حالا
 *     هم `resolveActor()` تضمینش می‌کند: فقط چهار مقدار مشخص تولید
 *     می‌شوند) به همان اندازه امن است و migrate بین درایورهای مختلف
 *     ساده‌تر می‌ماند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_type', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->enum('actor_type', ['admin', 'reseller', 'system'])->change();
        });
    }
};
