<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('bot_token')->nullable(); // encrypted via model cast؛ payload رمزنگاری‌شده >255 کاراکتر است، پس text (نه varchar)
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->json('min_sale_price_rule')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resellers');
    }
};
