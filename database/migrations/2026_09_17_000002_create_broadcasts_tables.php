<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 گزارش امنیتی (موارد #20، #21، #22): پیام همگانی تا این نسخه هیچ
 * ردی در دیتابیس نمی‌گذاشت — نتیجه فقط در فایل لاگ می‌رفت. یعنی نه
 * ادمین/نماینده می‌توانست ببیند پیامش به چند نفر رسید، نه معلوم بود
 * کدام گیرنده‌ها شکست خورده‌اند تا دوباره تلاش شود.
 *
 * broadcasts: خودِ کمپین (متن، فرستنده، شمارش‌ها، بازه‌ی زمانی)
 * broadcast_recipients: وضعیت تک‌تک گیرنده‌ها — همین جدول است که
 * امکان retryِ هدفمندِ فقط موارد شکست‌خورده را می‌دهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            // null = پیام همگانی ادمین اصلی با ربات اصلی
            $table->foreignId('reseller_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->enum('status', ['queued', 'sending', 'completed', 'failed'])->default('queued');
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['broadcast_id', 'user_id']);
            $table->index(['broadcast_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');
    }
};
