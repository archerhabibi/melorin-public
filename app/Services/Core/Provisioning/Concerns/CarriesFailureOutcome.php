<?php

namespace App\Services\Core\Provisioning\Concerns;

use App\Services\Core\Provisioning\ProvisioningFailureHandler;

/**
 * نتیجه‌ی اعمال سیاست شکست را روی استثنا نگه می‌دارد تا کانال‌ها (ربات،
 * پنل) بدانند به مشتری چه بگویند. پیش از این ربات اصلی همیشه می‌گفت
 * «مبلغ بازگشت داده شد» — حتی وقتی سیاست retry بود و هیچ بازگشتی رخ نداده بود.
 */
trait CarriesFailureOutcome
{
    protected ?string $failureOutcome = null;

    public function withOutcome(?string $outcome): static
    {
        $this->failureOutcome = $outcome;

        return $this;
    }

    public function outcome(): ?string
    {
        return $this->failureOutcome;
    }

    public function customerNotice(): string
    {
        return match ($this->failureOutcome) {
            ProvisioningFailureHandler::OUTCOME_REFUNDED => 'مبلغ پرداختی به کیف پول شما بازگردانده شد.',
            ProvisioningFailureHandler::OUTCOME_RETRY_SCHEDULED => 'پرداخت شما ثبت شده و سیستم به‌صورت خودکار دوباره تلاش می‌کند؛ نیازی به پرداخت مجدد نیست و نتیجه به شما اطلاع داده می‌شود.',
            default => 'پرداخت شما ثبت شده و پشتیبانی موضوع را پیگیری می‌کند؛ نیازی به پرداخت مجدد نیست.',
        };
    }
}
