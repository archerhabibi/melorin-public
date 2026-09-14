<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * طبق درخواست صریح: گزینه‌ی «Manager» کلاً حذف شود چون برداشت اشتباهی
 * از آن شده بود — در عمل هیچ‌جای ربات یا پنل (نه ربات نماینده، نه پنل
 * وب نماینده، نه پنل ادمین اصلی) امکان افزودن Manager را نشان
 * نمی‌داد؛ ResellerService::addAdmin/removeAdmin/isManager هم هرگز از
 * جایی صدا زده نمی‌شدند. این migration ابتدا هر ردیفِ احتمالیِ manager
 * را حذف می‌کند (نباید داده‌ای وجود داشته باشد، ولی برای اطمینان) و
 * بعد enum ستون role را به فقط 'owner' محدود می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('reseller_admins')->where('role', 'manager')->delete();

        if (DB::getDriverName() === 'sqlite') {
            // SQLite واقعاً enum ندارد (فقط CHECK constraint معادلش را
            // شبیه‌سازی می‌کند)؛ چون تغییر آن نیازمند بازسازی کامل جدول
            // است و هیچ سود امنیتی اضافه‌ای در محیط تست نمی‌دهد، در
            // SQLite این migration فقط پاک‌سازیِ داده را انجام می‌دهد.
            return;
        }

        DB::statement("ALTER TABLE reseller_admins MODIFY role ENUM('owner') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE reseller_admins MODIFY role ENUM('owner', 'manager') NOT NULL");
    }
};
