<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند ۱۳ سند نیازمندی‌های Reseller Platform: «Owner می‌تواند Manager
 * اضافه/حذف کند». به‌جای این‌که «مالک بودن» فقط با یک مقایسه‌ی جداگانه‌ی
 * resellers.user_id تشخیص داده شود و «مدیر بودن» با یک جدول کاملاً
 * متفاوت، هر دو نقش در همین یک جدول ذخیره می‌شوند (نقش owner هنگام
 * ساخت Reseller خودکار درج می‌شود — ر.ک. ResellerService::create) تا
 * تمام چک‌های Authorization («آیا این کاربر روی این Reseller دسترسی
 * دارد؟») از یک مسیر واحد و قابل تست عبور کنند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'manager']);
            $table->timestamps();

            $table->unique(['reseller_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_admins');
    }
};
