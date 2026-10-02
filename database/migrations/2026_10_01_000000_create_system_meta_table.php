<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاز ۵ — جدول کلید/مقدارِ سیستمی. فعلاً فقط «قفل ارز» (currency_lock) را نگه می‌دارد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_meta', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_meta');
    }
};
