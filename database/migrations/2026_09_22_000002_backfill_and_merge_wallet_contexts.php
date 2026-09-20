<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * فاز ۱۳ — انتقال و ادغام Walletهای قدیمی به مدل «User + StoreContext».
 *
 * هر Wallet قدیمی به یک کلید (user_id, store_type, reseller_id) نگاشت
 * می‌شود:
 *
 *   owner = CustomerAccount → همان (user, store) خودِ آن عضویت
 *   owner = User (قدیمی)     → از customer_account_id؛ اگر نبود از users.reseller_id
 *   owner = Reseller         → (صاحبِ نماینده, main, null)   ← Rule 6 سند
 *
 * اگر چند Wallet به یک کلید برسند (مثلاً Wallet قدیمیِ یک کاربر + Walletی
 * که بعداً برای همان CustomerAccount ساخته شده؛ یا اعتبار نماینده + Wallet
 * شخصیِ صاحبِ او در Main) در یک ردیف ادغام می‌شوند:
 *   - موجودی‌ها جمع می‌شود (با عدد صحیح ریزترین واحد، نه float)؛
 *   - همه‌ی wallet_transactions به ردیف بازمانده منتقل می‌شود؛
 *   - یک تراکنش admin_adjust «نشانگر ادغام» ثبت می‌شود تا
 *     balance == balance_after آخرین تراکنش بماند؛
 *   - مجموع موجودی قبل و بعد باید دقیقاً برابر باشد، وگرنه کل Migration
 *     rollback می‌شود.
 *
 * ⚠️ پیامد کسب‌وکاری (تصمیم صریح سند): موجودی شخصیِ صاحبِ نماینده در Main
 * و اعتبار نمایندگی‌اش از این به بعد «یک موجودی» هستند.
 *
 * Idempotent است: فقط ردیف‌هایی که scope_key ندارند را پردازش می‌کند.
 * Walletهایی که به هیچ User نگاشت نمی‌شوند (مثلاً عضویتِ مهمان) دست‌نخورده
 * می‌مانند و در لاگ گزارش می‌شوند.
 */
return new class extends Migration
{
    public function up(): void
    {
        // بعد از Migration «drop_legacy_wallet_owner_columns» دیگر ردیف legacy وجود ندارد.
        if (! Schema::hasTable('wallets')
            || ! Schema::hasColumn('wallets', 'scope_key')
            || ! Schema::hasColumn('wallets', 'owner_type')) {
            return;
        }

        DB::transaction(function () {
            $legacy = DB::table('wallets')->whereNull('scope_key')->orderBy('id')->get();

            if ($legacy->isEmpty()) {
                return;
            }

            $groups = [];
            $unresolved = [];
            $beforeCents = 0;

            foreach ($legacy as $row) {
                $target = $this->resolveTarget($row);

                if (! $target) {
                    $unresolved[] = ['wallet_id' => $row->id, 'balance' => $row->balance];

                    continue;
                }

                $beforeCents += $this->cents($row->balance);
                $groups[$target['user_id'].'|'.$target['scope_key']][] = ['row' => $row, 'target' => $target];
            }

            $afterCents = 0;

            foreach ($groups as $group) {
                $survivor = $group[0]['row'];
                $target = $group[0]['target'];
                $absorbed = array_slice($group, 1);

                $totalCents = $this->cents($survivor->balance);
                $absorbedCents = 0;
                $absorbedIds = [];

                // ابتدا جذب‌شده‌ها حذف می‌شوند و بعد ردیف بازمانده به‌روز
                // می‌شود، تا unique نهایی هیچ‌گاه موقتاً نقض نشود.
                foreach ($absorbed as $item) {
                    $row = $item['row'];

                    DB::table('wallet_transactions')->where('wallet_id', $row->id)->update(['wallet_id' => $survivor->id]);
                    DB::table('wallets')->where('id', $row->id)->delete();

                    $absorbedCents += $this->cents($row->balance);
                    $absorbedIds[] = $row->id;
                }

                $totalCents += $absorbedCents;
                $afterCents += $totalCents;

                DB::table('wallets')->where('id', $survivor->id)->update([
                    'user_id' => $target['user_id'],
                    'store_type' => $target['store_type'],
                    'reseller_id' => $target['reseller_id'],
                    'scope_key' => $target['scope_key'],
                    'balance' => $this->format($totalCents),
                    'owner_type' => null,
                    'owner_id' => null,
                    'customer_account_id' => null,
                    'updated_at' => now(),
                ]);

                if ($absorbed !== []) {
                    DB::table('wallet_transactions')->insert([
                        'wallet_id' => $survivor->id,
                        'type' => 'admin_adjust',
                        'amount' => $this->format($absorbedCents),
                        'balance_after' => $this->format($totalCents),
                        'description' => 'ادغام Walletهای قدیمی ('.implode('، ', array_map(fn ($id) => '#'.$id, $absorbedIds)).') در Wallet یکتای این Context',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($beforeCents !== $afterCents) {
                throw new RuntimeException("ادغام Wallet مجموع موجودی را تغییر داد ({$beforeCents} ≠ {$afterCents}) — Migration rollback شد.");
            }

            if ($unresolved !== []) {
                Log::warning('wallet_context_backfill_unresolved', ['wallets' => $unresolved]);
            }

            Log::info('wallet_context_backfill_done', [
                'legacy_rows' => $legacy->count(),
                'resulting_wallets' => count($groups),
                'total_balance' => $this->format($afterCents),
            ]);
        });
    }

    public function down(): void
    {
        // ادغام برگشت‌پذیر نیست (موجودی‌ها جمع شده‌اند)؛ عمداً no-op.
    }

    /** @return array{user_id:int, store_type:string, reseller_id:?int, scope_key:string}|null */
    protected function resolveTarget(object $row): ?array
    {
        switch ($row->owner_type) {
            case \App\Models\CustomerAccount::class:
                $account = DB::table('customer_accounts')->where('id', $row->owner_id)->first();

                return $account ? $this->fromCustomerAccount($account) : null;

            case \App\Models\User::class:
                if ($row->customer_account_id) {
                    $account = DB::table('customer_accounts')->where('id', $row->customer_account_id)->first();

                    if ($account) {
                        return $this->fromCustomerAccount($account);
                    }
                }

                $user = DB::table('users')->where('id', $row->owner_id)->first();

                if (! $user) {
                    return null;
                }

                $resellerId = $user->reseller_id ?? null;

                return $this->build((int) $user->id, $resellerId ? 'reseller' : 'main', $resellerId ? (int) $resellerId : null);

            case \App\Models\Reseller::class:
                $reseller = DB::table('resellers')->where('id', $row->owner_id)->first();

                return $reseller && $reseller->user_id
                    ? $this->build((int) $reseller->user_id, 'main', null)
                    : null;
        }

        return null;
    }

    protected function fromCustomerAccount(object $account): ?array
    {
        if (! $account->user_id) {
            return null;
        }

        return $this->build(
            (int) $account->user_id,
            $account->store_type,
            $account->reseller_id ? (int) $account->reseller_id : null,
        );
    }

    protected function build(int $userId, string $storeType, ?int $resellerId): array
    {
        return [
            'user_id' => $userId,
            'store_type' => $storeType,
            'reseller_id' => $storeType === 'reseller' ? $resellerId : null,
            'scope_key' => $storeType === 'reseller' ? 'reseller:'.$resellerId : 'main',
        ];
    }

    protected function cents(mixed $balance): int
    {
        return (int) round(((float) $balance) * 100);
    }

    protected function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
};
