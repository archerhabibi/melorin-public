<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B3.4 — Ticket Center: تیکت باید به «فروشگاه» (StoreContext) وصل باشد.
 *
 * تا B3.3 تیکت فقط `user_id` داشت (ثبت فقط از ربات اصلی). با ثبت تیکت از Website، مشتری یک نماینده
 * نباید تیکت‌های خودش در فروشگاه اصلی را در فروشگاه نماینده ببیند (و برعکس): همان کلید مالکیتِ
 * Payment/Order (`user_id + reseller_id`). `NULL` = فروشگاه اصلی، که تمام تیکت‌های موجود (ربات) را
 * بدون backfill درست پوشش می‌دهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('reseller_id')->nullable()->after('user_id');
            $table->foreign('reseller_id')->references('id')->on('resellers')->nullOnDelete();
            $table->index(['user_id', 'reseller_id', 'status'], 'tickets_owner_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropIndex('tickets_owner_status_index');
            $table->dropColumn('reseller_id');
        });
    }
};
