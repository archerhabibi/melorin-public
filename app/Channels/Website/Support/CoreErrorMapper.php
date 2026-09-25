<?php

namespace App\Channels\Website\Support;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\ProductNotSellableException;
use App\Exceptions\ResellerScopeViolationException;
use App\Services\Core\Panels\PanelConnectionException;
use App\Services\Core\Payments\InvalidPaymentTransitionException;
use App\Services\Core\Provisioning\ProvisioningFailedException;
use App\Services\Core\Purchase\PurchaseNotAllowedException;
use App\Services\Core\Purchase\ResellerDebtLimitException;
use App\Services\Core\Renewal\RenewalFailedException;
use Illuminate\Support\Str;
use Throwable;

/**
 * فاز W0 بند ۶ + TBD ۹.۵: «یک آرایه‌ی ثابت از Exception کلاس Core →
 * پیام فارسی نمایشی». این کلاس عمداً هیچ تصمیمی نمی‌گیرد — فقط
 * *ترجمه‌ی نمایشی* است (بند ۵۶ زیرسند). اگر Exception ناشناخته بود،
 * پیام عمومی + Reference ID برگردانده می‌شود (بخش ۵ Roadmap: «یک
 * صفحه‌ی خطای عمومی به‌جای نمایش پیام خام Exception»)، و خطای کامل با
 * همان Reference ID لاگ می‌شود تا پشتیبانی بتواند پیگیری کند.
 */
class CoreErrorMapper
{
    /** @var array<class-string<Throwable>, string> */
    private const MAP = [
        InsufficientBalanceException::class => 'موجودی کیف پول شما برای این خرید کافی نیست.',
        ProductNotSellableException::class => 'این تعرفه در حال حاضر از این فروشگاه قابل خرید نیست.',
        PurchaseNotAllowedException::class => 'این خرید در حال حاضر امکان‌پذیر نیست.',
        ResellerDebtLimitException::class => 'سقف اعتبار این فروشگاه به پایان رسیده؛ لطفاً با پشتیبانی تماس بگیرید.',
        ResellerScopeViolationException::class => 'این عملیات برای این فروشگاه مجاز نیست.',
        ProvisioningFailedException::class => 'در ساخت اکانت شما مشکلی پیش آمد. در صورت کسر وجه، سفارش شما ثبت شده و پیگیری می‌شود.',
        PanelConnectionException::class => 'ارتباط با سرور در حال حاضر برقرار نیست؛ چند دقیقه‌ی دیگر تلاش کنید.',
        RenewalFailedException::class => 'تمدید اکانت با مشکل مواجه شد.',
        InvalidPaymentTransitionException::class => 'وضعیت این پرداخت تغییر کرده؛ صفحه را تازه‌سازی کنید.',
    ];

    /**
     * @return array{message: string, reference: string}
     */
    public function map(Throwable $e): array
    {
        $reference = Str::upper(Str::random(8));

        $message = self::MAP[$e::class]
            ?? 'خطایی پیش آمد. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.';

        // شناسه‌ی کامل فقط در Log — طبق تصمیم ۹.۸ (Correlation ID)، فقط
        // کد کوتاه به کاربر نشان داده می‌شود.
        logger()->error('[website] mapped exception', [
            'reference' => $reference,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return ['message' => $message, 'reference' => $reference];
    }
}
