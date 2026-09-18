<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration ۳ از بلوپرینت (بند ۵۵) — ساخت CustomerAccount برای هر کاربر
 * موجود، بر اساس فروشگاهی که همین الان در آن است.
 *
 * قاعده‌ی نگاشت:
 *     users.reseller_id IS NULL  →  (main,     null)
 *     users.reseller_id = X      →  (reseller, X)
 *
 * چند تصمیم که عمدی‌اند:
 *
 * ۱) idempotent است. با insertOrIgnore و تکیه بر unique ای که در
 *    Migration ۱ ساختیم، اجرای دوباره‌ی این migration (یا اجرای هم‌زمان
 *    با ترافیک زنده) هرگز رکورد تکراری نمی‌سازد. برای یک data migration
 *    روی داده‌ی مالی، این خاصیت واجب است نه تجملاتی.
 *
 * ۲) کاربرانِ soft-delete شده هم CustomerAccount می‌گیرند. وسوسه‌انگیز
 *    است که ردشان کنیم، ولی سفارش‌ها و پرداخت‌های گذشته‌شان هنوز در
 *    دیتابیس است و Migration بعدی باید بتواند آن‌ها را به یک مالک وصل
 *    کند؛ وگرنه ردیف‌های مالی یتیم می‌مانند.
 *
 * ۳) status کاربر منتقل می‌شود تا کاربر مسدود، مشتری مسدود بماند.
 *
 * ۴) chunk می‌زنیم و نه یک INSERT غول‌پیکر، تا روی دیتابیس‌های بزرگ
 *    حافظه و lock طولانی نسازد.
 *
 * برگشت‌پذیری: down() فقط رکوردهایی را پاک می‌کند که خودش ساخته (هرچه
 * در جدول است)، چون این جدول قبل از این migration خالی بوده.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('users')->orderBy('id')->chunkById(500, function ($users) use ($now) {
            $rows = [];

            foreach ($users as $user) {
                $isReseller = ! is_null($user->reseller_id);

                $rows[] = [
                    'user_id' => $user->id,
                    'store_type' => $isReseller ? 'reseller' : 'main',
                    'reseller_id' => $isReseller ? $user->reseller_id : null,
                    // insertOrIgnore خام است و از رویداد saving مدل رد
                    // نمی‌شود، پس scope_key باید همین‌جا صریحاً ساخته شود
                    // (به توضیح ستون در Migration ۱ مراجعه کنید).
                    'scope_key' => $isReseller ? 'reseller:'.$user->reseller_id : 'main',
                    'status' => in_array($user->status, ['active', 'disabled', 'blocked'], true)
                        ? $user->status
                        : 'active',
                    'display_name' => $user->full_name,
                    'created_at' => $user->created_at ?? $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('customer_accounts')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        // ستون‌های customer_account_id در جداول دیگر nullOnDelete/cascade
        // دارند، ولی برای قطعیت ترتیب، اول ارجاع‌ها پاک می‌شوند تا این
        // حذف هرگز به خطای FK نخورد.
        DB::table('wallets')->update(['customer_account_id' => null]);
        DB::table('orders')->update(['customer_account_id' => null]);
        DB::table('accounts')->update(['customer_account_id' => null]);
        DB::table('payments')->update(['customer_account_id' => null]);
        DB::table('commissions')->update([
            'referrer_customer_account_id' => null,
            'referred_customer_account_id' => null,
        ]);

        DB::table('customer_accounts')->delete();
    }
};
