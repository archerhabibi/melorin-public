<?php

namespace App\Services\Core;

use App\Exceptions\InsufficientBalanceException;
use App\Models\CustomerAccount;
use App\Models\Operation;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * WalletService
 *
 * تنها نقطه‌ی مجاز در کل سیستم برای تغییر موجودی کیف پول (User یا Reseller).
 * هیچ بخش دیگری (ربات، پنل، سایت) نباید مستقیم فیلد balance را تغییر دهد؛
 * طبق اصل معماری سند نیازمندی (بند ۲۸ و ۳۴)، هر تغییر موجودی باید از این
 * سرویس عبور کند تا سابقه‌ی تراکنش (wallet_transactions) همیشه با balance
 * واقعی هماهنگ بماند.
 */
class WalletService
{
    /** کیف پول یک مالک (User یا Reseller) را برمی‌گرداند؛ اگر وجود نداشت می‌سازد */
    public function getOrCreateWallet(Model $owner): Wallet
    {
        return Wallet::firstOrCreate([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
        ], [
            'balance' => 0,
        ]);
    }

    public function balance(Model $owner): float
    {
        return (float) $this->getOrCreateWallet($owner)->balance;
    }

    /** شارژ کیف پول (بند ۱۱) */
    public function charge(Model $owner, float $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        $this->assertPositive($amount);

        return $this->applyTransaction($owner, 'charge', $amount, $reference, $description);
    }

    /**
     * کسر مبلغ برای خرید (بند ۹) — در صورت کمبود موجودی خطا می‌دهد.
     *
     * توجه: بررسی کفایت موجودی و کسر آن هر دو داخل applyTransaction() و
     * زیر یک قفل ردیف صحیح (owner_type + owner_id) انجام می‌شود، تا در
     * حالت درخواست‌های هم‌زمان هرگز کیف پول اشتباه خوانده یا تغییر نکند
     * (نسخه‌ی قبلی این متد یک لاک جدا و نادرست روی کل جدول wallets
     * می‌زد که می‌توانست کیف پول کاربر دیگری را قفل/چک کند — رفع شد).
     *
     * @throws InsufficientBalanceException
     */
    public function purchase(Model $owner, float $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        $this->assertPositive($amount);

        return $this->applyTransaction($owner, 'purchase', -$amount, $reference, $description);
    }

    /** بازگشت وجه (بند ۱۲) */
    public function refund(Model $owner, float $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        $this->assertPositive($amount);

        return $this->applyTransaction($owner, 'refund', $amount, $reference, $description);
    }

    /** واریز کمیسیون یا پاداش زیرمجموعه‌گیری (بند ۱۳) */
    public function addCommission(Model $owner, float $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        $this->assertPositive($amount);

        return $this->applyTransaction($owner, 'commission', $amount, $reference, $description);
    }

    public function addReferralBonus(Model $owner, float $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        $this->assertPositive($amount);

        return $this->applyTransaction($owner, 'referral_bonus', $amount, $reference, $description);
    }

    /**
     * تغییر دستی موجودی توسط ادمین (بند ۱۴: افزایش/کاهش موجودی).
     * amount می‌تواند مثبت یا منفی باشد.
     */
    public function adminAdjust(Model $owner, float $amount, ?Model $reference = null, ?string $description = null): WalletTransaction
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
        float $signedAmount,
        ?Model $reference,
        ?string $description,
        ?Wallet $lockedWallet = null,
        ?Operation $operation = null,
        ?float $minimumBalance = null
    ): WalletTransaction {
        $floor = $minimumBalance ?? $this->minimumBalanceFor($owner);

        return DB::transaction(function () use ($owner, $type, $signedAmount, $reference, $description, $lockedWallet, $operation, $floor) {
            $wallet = $lockedWallet ?? Wallet::query()
                ->where('owner_type', $owner::class)
                ->where('owner_id', $owner->getKey())
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = $this->getOrCreateWallet($owner);
                $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
            }

            $newBalance = (float) $wallet->balance + $signedAmount;

            // کف مجاز برای مشتری صفر است و برای نماینده منفیِ سقف بدهی
            // (بند ۲۰ بلوپرینت). مقایسه با یک epsilon کوچک انجام می‌شود
            // چون جمع/تفریق اعداد اعشاری می‌تواند -500 را به
            // -500.0000000001 تبدیل کند و یک خریدِ کاملاً مجاز را به‌
            // اشتباه مسدود کند.
            if ($newBalance < $floor - 0.00001) {
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
        float $amount,
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
        float $amount,
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
    public function canDebit(Model $owner, float $amount): bool
    {
        return $this->balance($owner) >= $amount;
    }

    public function getBalance(Model $owner): float
    {
        return $this->balance($owner);
    }

    /**
     * کیف‌پول یک عضویت فروشگاهی (بند ۷ بلوپرینت).
     *
     * از این نقطه به بعد، کیف‌پول مشتری به CustomerAccount تعلق دارد نه
     * به User — چون یک نفر در هر فروشگاه کیف‌پول جدا دارد. کیف‌پول
     * نماینده (owner = Reseller) کماکان از همان متدهای عمومی بالا
     * استفاده می‌کند و تغییری نمی‌کند.
     */
    public function walletFor(CustomerAccount $customerAccount): Wallet
    {
        return $this->getOrCreateWallet($customerAccount);
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
    protected function minimumBalanceFor(Model $owner): float
    {
        if ($owner instanceof \App\Models\Reseller) {
            return -1 * (float) ($owner->debt_limit ?? 0);
        }

        return 0.0;
    }

    protected function assertPositive(float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بزرگ‌تر از صفر باشد.');
        }
    }
}
