<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2.1 — هویت‌های بیرونی (فعلاً Google). GOOGLE-SIGNIN-CONTRACT.md §G13.
 *
 * کلید هویت `(provider, provider_user_id)` است (برای Google: claim `sub`)، نه Email.
 * هر User حداکثر یک هویت از هر Provider دارد. Additive و برگشت‌پذیر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('provider_email')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
