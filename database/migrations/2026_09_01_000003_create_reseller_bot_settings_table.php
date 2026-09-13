<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند ۱۱ و ۱۴ سند نیازمندی‌های Reseller Platform: قوانین خرید، راهنمای
 * اتصال و Support ID مخصوص هر نماینده — این محتوا فقط در ربات همان
 * نماینده نمایش داده می‌شود (نه ربات اصلی). bot_enabled هم سوییچ کلی
 * «توقف فروش و عملیات حساس Bot» است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_bot_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete()->unique();
            $table->boolean('bot_enabled')->default(true);
            $table->string('support_id')->nullable();
            $table->text('rules')->nullable();
            $table->text('connection_guide')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_bot_settings');
    }
};
