<?php

use App\Support\CurrencyLock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baseline اسکیمای Melorin (۸۲ Migration پیشین در یک فایل ادغام شد؛ تاریخچه‌شان در Git است).
 *
 * چرا ادغام شد: پروژه هنوز منتشر نشده و هیچ دیتای واقعی‌ای وجود ندارد، پس Backfill/Merge
 * (CustomerAccount، ادغام Walletها، تبدیل اعشار→Integer) و ستون‌های قدیمی (users.reseller_id،
 * ستون‌های مالکیت قدیمی Wallet، نام‌های قدیمی قیمت) هیچ کاربردی ندارند.
 *
 * قواعد این Baseline:
 *   - همه‌ی مبالغ bigInteger (Minor Unit ارزِ config('melorin.currency')) هستند؛ درصدها (commission_*)
 *     و traffic_gb پول نیستند و decimal می‌مانند.
 *   - پایانِ موفق، «code:decimals» را در system_meta قفل می‌کند (App\Support\CurrencyLock).
 *   - FKها داخل خودِ create هستند (SQLite افزودن FK با ALTER را نمی‌پذیرد) و جدول‌ها به ترتیب وابستگی ساخته می‌شوند.
 *
 * ⚠️ IRREVERSIBLE: down() عمداً خطا می‌دهد؛ برگشت = Restore از Backup (یا migrate:fresh در Dev).
 * هر تغییر بعدی اسکیما باید Migration جدید و جداگانه باشد، نه ویرایش این فایل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_id')->nullable();
            $table->string('phone')->nullable();
            $table->string('username_site')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('full_name')->nullable();
            $table->enum('status', ['active', 'disabled', 'blocked'])->default('active');
            $table->unsignedBigInteger('referrer_id')->nullable();
            $table->enum('joined_from', ['bot', 'website', 'reseller_bot', 'panel'])->default('bot');
            $table->softDeletes();
            $table->timestamps();
            $table->rememberToken();
            $table->unique('email');
            $table->unique('phone');
            $table->unique('telegram_id');
            $table->unique('username_site');
            $table->foreign('referrer_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('resellers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('bot_token')->nullable();
            $table->string('webhook_slug')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_status')->nullable();
            $table->text('webhook_error')->nullable();
            $table->timestamp('webhook_registered_at')->nullable();
            $table->string('slug')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->json('min_sale_price_rule')->nullable();
            $table->bigInteger('debt_limit')->default(0);
            $table->timestamps();
            $table->unique('slug');
            $table->unique('webhook_slug');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('store_type', ['main', 'reseller'])->default('main');
            $table->unsignedBigInteger('reseller_id')->nullable();
            $table->enum('status', ['active', 'disabled', 'blocked'])->default('active');
            $table->string('display_name')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->string('scope_key');
            $table->index(['store_type', 'reseller_id']);
            $table->unique(['user_id', 'scope_key'], 'customer_accounts_user_scope_unique');
            $table->foreign('reseller_id')->references('id')->on('resellers')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['card_to_card', 'rial_gateway', 'crypto', 'other'])->default('card_to_card');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->boolean('is_super_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
            $table->unique('email');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('customer_account_id')->nullable();
            $table->enum('wallet_owner_type', ['user', 'reseller'])->default('user');
            $table->unsignedBigInteger('reseller_id')->nullable();
            $table->unsignedBigInteger('payment_method_id');
            $table->bigInteger('amount');
            $table->enum('purpose', ['order', 'wallet_charge']);
            $table->string('receipt_image')->nullable();
            $table->string('depositor_name')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->json('gateway_response')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('reviewed_by_reseller_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index('customer_account_id');
            $table->index('gateway_reference');
            $table->foreign('customer_account_id')->references('id')->on('customer_accounts')->nullOnDelete();
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->restrictOnDelete();
            $table->foreign('reseller_id')->references('id')->on('resellers')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('reviewed_by_reseller_id')->references('id')->on('resellers')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('available_to_resellers')->default(1);
            $table->json('settings')->nullable();
            $table->enum('server_selection_mode', ['manual', 'auto'])->default('auto');
            $table->enum('naming_mode', ['random', 'custom'])->default('random');
            $table->timestamps();
        });

        Schema::create('protocols', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('name');
            $table->bigInteger('main_price');
            $table->bigInteger('reseller_price')->nullable();
            $table->decimal('traffic_gb', 10, 2)->nullable();
            $table->integer('duration_days');
            $table->unsignedBigInteger('protocol_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->integer('sale_limit')->nullable();
            $table->unsignedInteger('units_sold')->default(0);
            $table->json('allowed_panel_ids')->nullable();
            $table->timestamps();
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('protocol_id')->references('id')->on('protocols')->nullOnDelete();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('customer_account_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('reseller_id')->nullable();
            $table->enum('sales_channel', ['main_bot', 'reseller_bot', 'website', 'panel', 'test_account'])->default('main_bot');
            $table->bigInteger('main_price')->nullable();
            $table->bigInteger('reseller_price')->nullable();
            $table->bigInteger('customers_price')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('provision_attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unsignedBigInteger('renews_account_id')->nullable();
            $table->timestamp('next_provision_retry_at')->nullable();
            $table->index('customer_account_id');
            $table->index('next_provision_retry_at');
            $table->index('renews_account_id');
            $table->foreign('customer_account_id')->references('id')->on('customer_accounts')->nullOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('reseller_id')->references('id')->on('resellers')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('server_panels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('panel_type', ['sanaei', 'marzban', 'pasarguard', 'other'])->default('marzban');
            $table->string('host');
            $table->integer('port')->nullable();
            $table->text('credentials');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->integer('capacity')->nullable();
            $table->integer('account_limit_per_user')->nullable();
            $table->enum('health_status', ['healthy', 'degraded', 'down'])->nullable();
            $table->unsignedInteger('active_accounts_count')->default(0);
            $table->json('extra_settings')->nullable();
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('customer_account_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('server_panel_id');
            $table->unsignedBigInteger('protocol_id')->nullable();
            $table->string('panel_username')->nullable();
            $table->string('panel_client_uuid')->nullable();
            $table->string('subscription_id')->nullable();
            $table->text('subscription_url')->nullable();
            $table->string('subscription_token', 64)->nullable();
            $table->text('config_data');
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->decimal('traffic_gb', 10, 2)->nullable();
            $table->decimal('traffic_used_gb', 10, 2)->nullable();
            $table->enum('status', ['active', 'expired', 'disabled', 'deleted', 'suspended'])->default('active');
            $table->boolean('is_test')->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->index('customer_account_id');
            $table->unique('panel_username');
            $table->unique(['server_panel_id', 'panel_username']);
            $table->unique('subscription_token');
            $table->foreign('customer_account_id')->references('id')->on('customer_accounts')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('protocol_id')->references('id')->on('protocols')->restrictOnDelete();
            $table->foreign('server_panel_id')->references('id')->on('server_panels')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('affiliate_settings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('customer_bonus_amount')->default(0);
            $table->bigInteger('referrer_bonus_amount')->default(0);
            $table->decimal('commission_percent', 5, 2)->default(0.00);
            $table->integer('commission_validity_days')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id');
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('bot_content_settings', function (Blueprint $table) {
            $table->id();
            $table->text('purchase_rules_text')->nullable();
            $table->timestamps();
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id')->nullable();
            $table->text('message');
            $table->enum('status', ['queued', 'sending', 'completed', 'failed'])->default('queued');
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('broadcast_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['broadcast_id', 'status']);
            $table->unique(['broadcast_id', 'user_id']);
            $table->foreign('broadcast_id')->references('id')->on('broadcasts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key');
            $table->mediumText('value');
            $table->integer('expiration');
            $table->primary(['key']);
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key');
            $table->string('owner');
            $table->integer('expiration');
            $table->primary(['key']);
        });

        Schema::create('category_server_panel', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('server_panel_id');
            $table->timestamps();
            $table->unique(['category_id', 'server_panel_id']);
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('server_panel_id')->references('id')->on('server_panels')->cascadeOnDelete();
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_id');
            $table->unsignedBigInteger('referrer_customer_account_id')->nullable();
            $table->unsignedBigInteger('referred_user_id');
            $table->unsignedBigInteger('referred_customer_account_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->enum('type', ['first_purchase_bonus', 'ongoing_commission']);
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->bigInteger('base_amount')->nullable();
            $table->bigInteger('amount');
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->timestamps();
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('referred_customer_account_id')->references('id')->on('customer_accounts')->nullOnDelete();
            $table->foreign('referred_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('referrer_customer_account_id')->references('id')->on('customer_accounts')->nullOnDelete();
            $table->foreign('referrer_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->unique('uuid');
        });

        Schema::create('guest_checkouts', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64);
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('reseller_id')->nullable();
            $table->string('guest_name', 100)->nullable();
            $table->string('guest_phone', 32)->nullable();
            $table->string('guest_email', 190);
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->unique('token');
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('reseller_id')->references('id')->on('resellers')->restrictOnDelete();
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id');
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
            $table->primary(['id']);
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
            $table->index('queue');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type']);
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key');
            $table->string('type', 64);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->json('payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique('idempotency_key');
            $table->index(['reference_type', 'reference_id']);
            $table->index(['status', 'available_at']);
            $table->index(['type', 'status']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email');
            $table->string('token');
            $table->timestamp('created_at')->nullable();
            $table->primary(['email']);
        });

        Schema::create('provisioning_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 32);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'attempt_number']);
            $table->foreign('operation_id')->references('id')->on('operations')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::create('provisioning_settings', function (Blueprint $table) {
            $table->id();
            $table->string('failure_policy', 20)->default('retry');
            $table->timestamps();
        });

        Schema::create('reseller_admins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('role', ['owner']);
            $table->timestamps();
            $table->unique(['reseller_id', 'user_id']);
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('reseller_bot_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id');
            $table->boolean('bot_enabled')->default(1);
            $table->string('support_id')->nullable();
            $table->text('rules')->nullable();
            $table->text('connection_guide')->nullable();
            $table->timestamps();
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::create('reseller_category_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('category_id');
            $table->boolean('is_enabled')->default(1);
            $table->timestamps();
            $table->unique(['reseller_id', 'category_id']);
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::create('reseller_conversation_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id');
            $table->bigInteger('telegram_chat_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('step')->default('idle');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['reseller_id', 'telegram_chat_id']);
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('reseller_product_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('product_id');
            $table->bigInteger('customers_price');
            $table->boolean('is_enabled')->default(0);
            $table->timestamps();
            $table->unique(['reseller_id', 'product_id']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::create('reseller_website_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id');
            $table->string('display_name', 100)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->text('about_text')->nullable();
            $table->timestamps();
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('server_panel_protocol', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_panel_id');
            $table->unsignedBigInteger('protocol_id');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['server_panel_id', 'protocol_id']);
            $table->foreign('protocol_id')->references('id')->on('protocols')->cascadeOnDelete();
            $table->foreign('server_panel_id')->references('id')->on('server_panels')->cascadeOnDelete();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');
            $table->primary(['id']);
            $table->index('last_activity');
            $table->index('user_id');
        });

        Schema::create('system_meta', function (Blueprint $table) {
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->timestamps();
            $table->primary(['key']);
        });

        Schema::create('telegram_conversation_states', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_chat_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('step')->default('idle');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique('telegram_chat_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('test_account_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(0);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedInteger('traffic_mb')->default(500);
            $table->unsignedInteger('duration_hours')->default(1);
            $table->unsignedInteger('max_per_user')->default(1);
            $table->timestamps();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('type', ['support', 'reseller_request'])->default('support');
            $table->string('subject');
            $table->enum('status', ['open', 'answered', 'closed'])->default('open');
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->enum('sender_type', ['user', 'admin']);
            $table->unsignedBigInteger('sender_id');
            $table->text('message');
            $table->string('attachment')->nullable();
            $table->timestamps();
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('balance')->default(0);
            $table->timestamps();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('store_type', ['main', 'reseller'])->default('main');
            $table->unsignedBigInteger('reseller_id')->nullable();
            $table->string('scope_key', 40)->nullable();
            $table->unique(['user_id', 'scope_key'], 'wallets_user_scope_unique');
            $table->foreign('reseller_id')->references('id')->on('resellers')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wallet_id');
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->enum('type', ['charge', 'purchase', 'refund', 'commission', 'referral_bonus', 'admin_adjust']);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index('operation_id');
            $table->index(['reference_type', 'reference_id']);
            $table->foreign('operation_id')->references('id')->on('operations')->nullOnDelete();
            $table->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
        });

        CurrencyLock::record();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'IRREVERSIBLE: Baseline اسکیما با Restore از Backup (یا migrate:fresh در Dev) برگردانده می‌شود، نه migrate:rollback.'
        );
    }
};
