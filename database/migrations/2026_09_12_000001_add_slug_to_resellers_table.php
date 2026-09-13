<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طبق درخواست صریح: آدرس پنل وب هر نماینده باید بر اساس نام لاتین خودش
 * باشد (مثلاً https://.../parismobile)، نه یک مسیر ثابت مشترک. این
 * ستون همان «Tenant Slug» ای است که Filament Multi-Tenancy برای ساخت
 * URL از روی آن استفاده می‌کند (ر.ک. ResellerPanelProvider::tenant()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('webhook_slug');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
