<?php

namespace App\Services\Core\Customer;

use Carbon\CarbonInterface;

/**
 * نمای خواندنیِ Profile مشتری (B3.5) — یک DTO برای هر Channel (Website و ربات از همین می‌خوانند).
 *
 * فقط وضعیت است؛ هیچ عملی نیست. «چک‌لیست تکمیل» فقط موارد **قابل‌انجام** را می‌شمارد: برای کاربری که ایمیل
 * ندارد (کاربر ربات) «تأیید ایمیل» شمرده نمی‌شود، چون افزودن ایمیل هنوز ممکن نیست (D-12).
 */
final class ProfileOverview
{
    public const CHECK_NAME = 'name';

    public const CHECK_PHONE = 'phone';

    public const CHECK_EMAIL_VERIFIED = 'email_verified';

    /**
     * @param  array<string, bool>  $checklist  فقط موارد قابل‌اجرا؛ کلید = CHECK_* ، مقدار = انجام‌شده؟
     */
    public function __construct(
        public readonly ?string $fullName,
        public readonly bool $nameCustomized,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $phone,
        public readonly bool $hasPassword,
        public readonly bool $telegramLinked,
        public readonly bool $googleLinked,
        public readonly ?string $googleEmail,
        public readonly ?CarbonInterface $memberSince,
        public readonly ?CarbonInterface $storeMemberSince,
        public readonly array $checklist,
    ) {}

    /** برچسب فارسی مواردِ چک‌لیست — منبع واحد برای Website و ربات */
    public static function checkLabels(): array
    {
        return [
            self::CHECK_NAME => 'نام و نام خانوادگی',
            self::CHECK_PHONE => 'شماره‌ی موبایل',
            self::CHECK_EMAIL_VERIFIED => 'تأیید ایمیل',
        ];
    }

    /** ۰..۱۰۰ */
    public function completionPercent(): int
    {
        $total = count($this->checklist);

        return $total === 0 ? 100 : (int) round(count(array_filter($this->checklist)) / $total * 100);
    }

    /** @return list<string> کلیدهای انجام‌نشده */
    public function missing(): array
    {
        return array_keys(array_filter($this->checklist, fn (bool $done) => ! $done));
    }

    public function isComplete(): bool
    {
        return $this->missing() === [];
    }
}
