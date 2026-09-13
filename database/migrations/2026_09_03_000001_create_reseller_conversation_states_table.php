<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * چرا این جدول از telegram_conversation_states جدا است: در چت خصوصی
 * تلگرام، chat.id همیشه برابر با شناسه‌ی عددی خودِ کاربر است — یعنی اگر
 * یک کاربر هم به ربات اصلی پیام بدهد هم به یک ربات نماینده، مقدار
 * chat_id در هر دو وب‌هوک کاملاً یکسان خواهد بود. اگر وضعیت مکالمه‌ی
 * ربات نماینده هم در همان جدول (که فقط telegram_chat_id را unique
 * می‌داند) ذخیره می‌شد، وسط یک جریان چندمرحله‌ای در ربات اصلی (مثلاً
 * خرید) پیام‌دادن به یک ربات نماینده می‌توانست آن state را رونویسی یا
 * با آن تداخل کند — و برعکس. کلید یکتای این جدول (reseller_id,
 * telegram_chat_id) این تداخل را از ریشه غیرممکن می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_conversation_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('telegram_chat_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('step')->default('idle');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['reseller_id', 'telegram_chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_conversation_states');
    }
};
