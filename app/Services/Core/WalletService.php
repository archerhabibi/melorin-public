<?php

namespace App\Services\Core;

use App\Exceptions\InsufficientBalanceException;
use App\Models\CustomerAccount;
use App\Models\Operation;
use App\Models\Reseller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * WalletService
 *
 * تنها نقطه‌ی مجاز در کل سیستم برای تغییر موجودی کیف پول.
 *
 * Wallet = User + StoreContext (فاز ۱۳، بند ۲۱ تا ۲۸ سند):
 *     CustomerAccount → Wallet همان (user, store) خودش
 *     Reseller        → Wallet صاحبِ نماینده در Main (Rule 6) — نه Wallet جدا
 *     User            → از طریق نگاشت به CustomerAccount (سازگاری قدیمی)
 * تفاوت «مشتری» و «نماینده» فقط در کف مجاز موجودی است (minimumBalanceFor):
 * کسر reseller_price از Wallet صاحب با کفِ -debt_limit، و خرید شخصی همان
 * صاحب در Main با کفِ صفر — روی یک Wallet ولی دو قاعده‌ی متفاوت.
 * هیچ بخش دیگری (ربات، پنل، سایت) نباید مستقیم فیلد balance را تغییر دهد؛
 * طبق اصل معماری سند نیازمندی (بند ۲۸ و ۳۴)، هر تغییر موجودی باید از این
 * سرویس عبور کند تا سابقه‌ی تراکنش (wallet_transactions) همیشه با balance
 * واقعی هماهنگ بماند.
 */
class WalletService
{
    /** Wallet یک مالک را برمی‌گرداند؛ اگر وجود نداشت می‌سازد (بند ۲۸: Wallet از روی Context Resolve می‌شود) */
    public function getOrCreateWallet(Model $owner): Wallet
    {
        [$userId, $storeType, $resellerId] = $this->keyFor($owner);

        return $this->resolveWallet($userId, $storeType, $resellerId);
    }

    /**
     * Wallet یک User در یک StoreContext:
     *   walletForContext($c, StoreContext::main())          → C / Main
     *   walletForContext($ali, StoreContext::reseller($a))  → Ali / Reseller A
     */
    public function walletForContext(User $user, StoreContext $context): Wallet
    {
        return $this->resolveWallet((int) $user->getKey(), $context->storeType, $context->resellerId());
    }

    /** موجودی Wallet یک User در یک Context صریح (بدون نیاز به ساخت CustomerAccount) */
    public function balanceIn(User $user, StoreContext $context): int
    {
        return (int) $this->walletForContext($user, $context)->balance;
    }

    protected function resolveWallet(int $userId, string $storeType, ?int $resellerId): Wallet
    {
        $scopeKey = Wallet::scopeKeyFor($storeType, $resellerId);

        $find = fn () => Wallet::query()->where('user_id', $userId)->where('scope_key', $scopeKey)->first();

        if ($wallet = $find()) {
            return $wallet;
        }

        try {
            return Wallet::create([
                'user_id' => $userId,
                'store_type' => $storeType,
                'reseller_id' => $resellerId,
                'balance' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            // درخواست هم‌زمانِ دیگری همین Wallet را ساخته (unique
            // (user_id, scope_key)) — همان را برمی‌گردانیم، نه Wallet دوم.
            return $find() ?? throw new \RuntimeException('Wallet پس از تداخل unique پیدا نشد.');
        }
    }

    /**
     * کلید Wallet یک مالک: [user_id, store_type, reseller_id].
     *
     * @return array{0:int, 1:string, 2:?int}
     */
    protected function keyFor(Model $owner): array
    {
        $owner = $this->normalizeOwner($owner);

        if ($owner instanceof CustomerAccount) {
            if (! $owner->user_id) {
                throw new \InvalidArgumentException('Wallet فقط برای عضویتی معنا دارد که به یک User وصل است (مهمان Wallet ندارد).');
            }

            return $owner->store_type === 'reseller'
                ? [(int) $owner->user_id, 'reseller', (int) $owner->reseller_id]
                : [(int) $owner->user_id, 'main', null];
        }

        if ($owner instanceof Reseller) {
            if (! $owner->user_id) {
                throw new \InvalidArgumentException('نماینده باید صاحب (User) داشته باشد.');
            }

            // Rule 6: Wallet صاحبِ نماینده برای reseller_price = Wallet او در Main.
            return [(int) $owner->user_id, 'main', null];
        }

        throw new \InvalidArgumentException('مالک Wallet نامعتبر: '.$owner::class);
    }

    public function balance(Model $owner): int
    {
        return (int) $this->getOrCreateWallet($owner)->balance;
    }

    /** شارژ کیف پول (بند ۱۱) */
    public function charge(Model $owner, int $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        $this->assertPositive($amount);

        return $this->applyTransaction($owner, 'charge', $amount, $reference, $description);
    }

    /**
     * کسر مبلغ برای خرید (بند ۹) — در صورت کمبود موجودی خطا می‌دهد.
     *
     * توجه: بررسی کفایت موجودی و کسر آن هر دو داخل applyTransaction() و
     * زیر یک قفل ردیف صحیح (user_id + scope_key) انجام می‌شود، تا در
     * حالت درخواست‌های هم‌زمان هرگز کیف پول اشتباه خوانده یا تغییر نکند
     * (نسخه‌ی قبلی این متد یک لاک جدا و نادرست روی کل جدول wallets
     * می‌زد که می‌توانست کیف پول کاربر دیگری را قفل/چک کند — رفع شد).
     *
     * @throws InsufficientBalanceException
     */
    public function purchase(Model $owner, int $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        $this->assertPositive($amount);

        return $this->applyTransaction($owner, 'purchase', -$amount, $reference, $description);
    }

    /** بازگشت وجه (بند ۱۲) */
    public function refund(Model $owner, int $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        $this->assertPositive($amount);

        return $this->applyTransaction($owner, 'refund', $amount, $reference, $description);
    }

    /** واریز کمیسیون یا پاداش زیرمجموعه‌گیری (بند ۱۳) */
    public function addCommission(Model $owner, int $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        $this->assertPositive($amount);

        return $this->applyTransaction($owner, 'commission', $amount, $reference, $description);
    }

    public function addReferralBonus(Model $owner, int $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        $this->assertPositive($amount);

        return $this->applyTransaction($owner, 'referral_bonus', $amount, $reference, $description);
    }

    /**
     * تغییر دستی موجودی توسط ادمین (بند ۱۴: افزایش/کاهش موجودی).
     * amount می‌تواند مثبت یا منفی باشد.
     */
    public function adminAdjust(Model $owner, int $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        return $this->applyTransaction($owner, 'admin_adjust', $amount, $reference, $description);
    }

    /**
     * هسته‌ی مشترک همه‌ی عملیات: در یک تراکنش دیتابیسی، موجودی را با قفل
     * ردیف (lockForUpdate) به‌روزرسانی می‌کند و رکورد wallet_transactions
     * را با balance_after ثبت می‌کند تا گردش حساب همیشه قابل بازسازی باشد.
     */
    protected function applyTransaction(
        Model $owner,
        string $type,
        int $signedAmount,
        ?Model $reference,
        ?string $description,
        ?Wallet $lockedWallet = null,
        ?Operation $operation = null,
        ?int $minimumBalance = null
    ): WalletTransaction {
        $owner = $this->normalizeOwner($owner);
        $floor = $minimumBalance ?? $this->minimumBalanceFor($owner);

        return DB::transaction(function () use ($owner, $type, $signedAmount, $reference, $description, $lockedWallet, $operation, $floor) {
            [$userId, $storeType, $resellerId] = $this->keyFor($owner);

            $wallet = $lockedWallet ?? Wallet::query()
                ->where('user_id', $userId)
                ->where('scope_key', Wallet::scopeKeyFor($storeType, $resellerId))
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = $this->resolveWallet($userId, $storeType, $resellerId);
                $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
            }

            $newBalance = (int) $wallet->balance + $signedAmount;

            // کف مجاز برای مشتری صفر است و برای نماینده منفیِ سقف بدهی
            // (بند ۲۰ بلوپرینت). از فاز ۵ همه‌چیز عدد صحیحِ Minor Unit است؛
            // مقایسه‌ی دقیق است و epsilon لازم نیست.
            if ($newBalance < $floor) {
                throw new InsufficientBalanceException;
            }

            $wallet->update(['balance' => $newBalance]);

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'operation_id' => $operation?->id,
                'type' => $type,
                'amount' => $signedAmount,
                'balance_after' => $newBalance,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'description' => $description,
            ]);
        });
    }

    /* ------------------------------------------------------------------
     | API جدید بند ۹ بلوپرینت — credit / debit / canDebit / getBalance
     |
     | متدهای قدیمی (charge/purchase/refund/...) عمداً دست‌نخورده باقی
     | مانده‌اند چون ده‌ها فراخوانی در ربات‌ها و پنل دارند و بلوپرینت
     | (بند ۷۰) صریحاً می‌گوید مسیرهای موجود نباید یک‌جا شکسته شوند.
     | این‌ها یک لایه‌ی صریح‌تر روی همان هسته‌اند که علاوه بر آن،
     | Operation را هم برای Idempotency می‌پذیرند.
     ------------------------------------------------------------------ */

    /** واریز به کیف‌پول با نوع مشخص و اتصال اختیاری به یک Operation */
    public function credit(
        Model $owner,
        int $amount,
        string $type = 'charge',
        ?Model $reference = null,
        ?string $description = null,
        ?Operation $operation = null
    ): WalletTransaction {
        $this->assertPositive($amount);
        $this->assertCreditType($type);

        return $this->applyTransaction($owner, $type, $amount, $reference, $description, null, $operation);
    }

    /**
     * برداشت از کیف‌پول. اگر موجودی کافی نباشد، InsufficientBalanceException
     * از دل applyTransaction (زیر قفل ردیف) پرتاب می‌شود — نه با یک چک
     * جداگانه‌ی قبلی که بین چک و کسر جا برای رقابت باز می‌گذارد.
     */
    public function debit(
        Model $owner,
        int $amount,
        string $type = 'purchase',
        ?Model $reference = null,
        ?string $description = null,
        ?Operation $operation = null
    ): WalletTransaction {
        $this->assertPositive($amount);

        return $this->applyTransaction($owner, $type, -$amount, $reference, $description, null, $operation);
    }

    /**
     * فقط یک بررسی مشورتی برای تصمیم‌های UI («دکمه‌ی خرید را نشان بده یا
     * پیام موجودی ناکافی؟»).
     *
     * هرگز به‌عنوان محافظ قبل از debit استفاده نشود: بین این بررسی و
     * کسر واقعی، یک خرید هم‌زمان دیگر می‌تواند موجودی را خالی کند.
     * تنها تضمین واقعی، خودِ debit است که زیر قفل ردیف کار می‌کند.
     */
    public function canDebit(Model $owner, int $amount): bool
    {
        return $this->balance($owner) >= $amount;
    }

    public function getBalance(Model $owner): int
    {
        return $this->balance($owner);
    }

    /**
     * کیف‌پول یک عضویت فروشگاهی: Wallet همان (user, store). یک نفر در هر
     * فروشگاه Wallet جدا دارد. نماینده هم کیف‌پول جدا ندارد؛ Wallet او
     * همان Wallet صاحبش در Main است (Rule 6).
     */
    public function walletFor(CustomerAccount $customerAccount): Wallet
    {
        return $this->getOrCreateWallet($customerAccount);
    }

    /**
     * فاز ۱۵ (Rule 12): یک `User` بدون Context صریح **همیشه Main** است.
     *
     * یک User می‌تواند هم‌زمان مشتری Main و مشتری چند نماینده باشد و هر
     * Context Wallet جدا دارد؛ پس «Wallet یک User» بدون Context فقط در
     * Main معنا دارد (بند ۲۲). قبلاً Context از `users.reseller_id`
     * (تک‌مقداری) حدس زده می‌شد که هم Rule 12 را می‌شکست و هم بی‌صدا
     * Wallet اشتباهی را نشان می‌داد.
     *
     * هر مسیرِ خارج از Main باید Context را صریح بدهد:
     *   - یک CustomerAccount (عضویت در فروشگاه نماینده)،
     *   - یا walletForContext() / balanceIn() با StoreContext.
     *
     * Reseller از این نگاشت مستثناست و در keyFor() به Wallet صاحبش در Main
     * می‌رسد؛ کفِ مجاز آن (-debt_limit) از minimumBalanceFor می‌آید.
     */
    protected function normalizeOwner(Model $owner): Model
    {
        if (! $owner instanceof User) {
            return $owner;
        }

        return app(IdentityService::class)->resolveCustomerAccount($owner, StoreContext::main());
    }

    protected function assertCreditType(string $type): void
    {
        $allowed = ['charge', 'refund', 'commission', 'referral_bonus', 'admin_adjust'];

        if (! in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException("نوع تراکنش واریز نامعتبر است: {$type}");
        }
    }

    /**
     * کف مجاز موجودی این مالک.
     *
     * مشتری هرگز نمی‌تواند منفی شود. نماینده می‌تواند تا سقف بدهی‌ای که
     * ادمین برایش تعیین کرده منفی شود — این همان چیزی است که اجازه
     * می‌دهد فروش نماینده در لحظه‌ی تمام‌شدن اعتبار قطع نشود، بدون
     * این‌که بدهی بی‌انتها ممکن باشد.
     */
    protected function minimumBalanceFor(Model $owner): int
    {
        if ($owner instanceof Reseller) {
            return -1 * (int) ($owner->debt_limit ?? 0);
        }

        return 0;
    }

    protected function assertPositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بزرگ‌تر از صفر باشد.');
        }
    }
}
