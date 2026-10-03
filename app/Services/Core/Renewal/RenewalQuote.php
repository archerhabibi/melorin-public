<?php

namespace App\Services\Core\Renewal;

use App\Models\Product;

/**
 * B3.2 — «پیش‌فاکتور» فقط‌خواندنیِ تمدید یک سرویس.
 *
 * نتیجه‌ی RenewalService::quote() است و هیچ چیز نمی‌نویسد (نه Wallet، نه Order). مبلغ همان
 * `customerDebit()` همان Context است، پس صفحه هرگز قیمتی غیر از آنچه واقعاً کسر می‌شود نشان نمی‌دهد
 * (Rule 7: در فروشگاه نماینده main_price هیچ نقشی ندارد).
 */
final class RenewalQuote
{
    /** موجودی کافی نیست؛ بقیه‌ی شرط‌ها برقرارند (اکشن «شارژ» معنا دارد). */
    public const REASON_INSUFFICIENT_BALANCE = 'insufficient_balance';

    /** محصول/فروشگاه/سبد قابل‌فروش نیست. */
    public const REASON_NOT_AVAILABLE = 'not_available';

    /** وضعیت سرویس اجازه‌ی تمدید نمی‌دهد (مسدود، تعلیق، حذف‌شده). */
    public const REASON_ACCOUNT_STATE = 'account_state';

    /** سایر دروازه‌های خرید (سقف فروش، سقف بدهی نماینده، Context). */
    public const REASON_NOT_ALLOWED = 'not_allowed';

    public function __construct(
        public readonly int $accountId,
        public readonly ?Product $product,
        public readonly int $price,
        public readonly int $balance,
        public readonly bool $canRenew,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
    ) {}

    public static function blocked(
        int $accountId,
        ?Product $product,
        string $reason,
        string $message,
        int $price = 0,
        int $balance = 0,
    ): self {
        return new self($accountId, $product, $price, $balance, false, $reason, $message);
    }

    public function shortfall(): int
    {
        return max(0, $this->price - $this->balance);
    }

    public function isFree(): bool
    {
        return $this->price <= 0;
    }

    /** اکشن «شارژ کیف‌پول» فقط وقتی معنا دارد که تنها مانع، موجودی باشد. */
    public function needsTopUp(): bool
    {
        return ! $this->canRenew && $this->reason === self::REASON_INSUFFICIENT_BALANCE;
    }
}
