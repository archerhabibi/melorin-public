<?php

namespace App\Services\Core\Customer;

use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\Identity\AccountLinkingService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * B3.5 — Profile Center: مشاهده و ویرایش اطلاعات هویتیِ مشتری (نام، موبایل) برای هر Channel.
 *
 * قواعد (قرارداد: `CUSTOMER-PROFILE-CONTRACT.md`):
 *  - **Profile = User، نه CustomerAccount:** نام و موبایل یک بار برای Identity نگه‌داشته می‌شود و در Main و همه‌ی
 *    فروشگاه‌های نماینده یکی است. خواندن Profile هیچ‌چیز نمی‌نویسد (حتی CustomerAccount؛ D4.1).
 *  - **منطق اینجا (Core):** Website و ربات از همین یک اعتبارسنجی و همین یک نوشتن استفاده می‌کنند.
 *  - **ایمیل و روش‌های ورود اینجا نیستند:** Change Email = D-12 (خارج از دامنه)، رمز/Google/Telegram/نشست‌ها =
 *    `AccountLinkingService` / `SessionSecurityService` (B2.4/B2.5). موبایل Proof هویت نیست (Master R9)؛ فقط
 *    اطلاعات تماس است، پس برای تغییرش رمز لازم نیست.
 *  - **نام تلگرام فقط روی نامِ دست‌نخورده می‌نشیند** (`applyTelegramName`)؛ وگرنه هر پیام ربات نام ویرایش‌شده را پاک می‌کرد.
 */
class ProfileCenterService
{
    public const NAME_MIN = 2;

    public const NAME_MAX = 100;

    /** فیلدهای قابل‌ویرایش توسط مشتری */
    public const EDITABLE = ['full_name', 'phone'];

    /** منشأهایی که نامشان از تلگرام می‌آید (مقدار `users.joined_from`) */
    protected const TELEGRAM_MANAGED_ORIGINS = ['bot', 'reseller_bot'];

    public function __construct(
        protected AuditService $audit,
        protected AccountLinkingService $linking,
        protected IdentityService $identity,
    ) {}

    // ───────────────────────── خواندن ─────────────────────────

    public function overview(User $user, StoreContext $store): ProfileOverview
    {
        $google = $this->linking->googleIdentity($user);

        $hasName = trim((string) $user->full_name) !== '';
        $hasPhone = trim((string) $user->phone) !== '';

        $checklist = [
            ProfileOverview::CHECK_NAME => $hasName,
            ProfileOverview::CHECK_PHONE => $hasPhone,
        ];

        // کاربر بدون ایمیل (ربات) نمی‌تواند «ایمیل تأییدشده» داشته باشد ⇒ در حساب نمی‌آید.
        if ($user->email) {
            $checklist[ProfileOverview::CHECK_EMAIL_VERIFIED] = $user->hasVerifiedEmail();
        }

        return new ProfileOverview(
            fullName: $hasName ? (string) $user->full_name : null,
            nameCustomized: $user->full_name_edited_at !== null,
            email: $user->email ?: null,
            emailVerified: $user->email ? $user->hasVerifiedEmail() : false,
            phone: $hasPhone ? (string) $user->phone : null,
            hasPassword: $this->linking->hasPassword($user),
            telegramLinked: $user->telegram_id !== null,
            googleLinked: $google !== null,
            googleEmail: $google?->provider_email,
            memberSince: $user->created_at,
            // فقط خواندن: عضویت این فروشگاه اگر هست؛ ساختن آن کار Checkout/Wallet است نه صفحه‌ی Profile.
            storeMemberSince: $this->identity->findCustomerAccount($user, $store)?->created_at,
            checklist: $checklist,
        );
    }

    // ───────────────────────── نوشتن ─────────────────────────

    /**
     * ویرایش نام و/یا موبایل. فقط کلیدهای موجود در `$input` تغییر می‌کنند (ربات هر بار یک فیلد می‌فرستد).
     * `phone` خالی/null = حذف شماره.
     *
     * @param  array{full_name?: mixed, phone?: mixed}  $input
     * @return list<string> فیلدهایی که واقعاً تغییر کردند (خالی = بدون تغییر؛ Audit ثبت نمی‌شود)
     *
     * @throws ProfileCenterException
     */
    public function update(User $user, array $input): array
    {
        if ($user->status !== 'active') {
            throw new ProfileCenterException('profile', 'حساب شما فعال نیست.');
        }

        $changes = [];

        if (array_key_exists('full_name', $input)) {
            $changes['full_name'] = $this->validName($input['full_name']);
        }

        if (array_key_exists('phone', $input)) {
            $changes['phone'] = $this->validPhone($input['phone']);
        }

        if ($changes === []) {
            return [];
        }

        try {
            $changed = DB::transaction(function () use ($user, $changes) {
                // قفل ردیف: دو ویرایش هم‌زمان (وب + ربات) روی هم نمی‌نشینند و یکتایی موبایل هم‌زمان چک می‌شود.
                $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                if (isset($changes['phone']) && $this->phoneTakenByOther($changes['phone'], $fresh)) {
                    throw new ProfileCenterException('phone', 'ثبت این شماره ممکن نیست. شماره‌ی دیگری را امتحان کنید یا با پشتیبانی تماس بگیرید.');
                }

                $changed = [];

                if (array_key_exists('full_name', $changes) && $changes['full_name'] !== $fresh->full_name) {
                    $fresh->full_name = $changes['full_name'];
                    $fresh->forceFill(['full_name_edited_at' => now()]);
                    $changed[] = 'full_name';
                }

                if (array_key_exists('phone', $changes) && $changes['phone'] !== $this->canonicalOrRaw($fresh->phone)) {
                    $fresh->phone = $changes['phone'];
                    $changed[] = 'phone';
                }

                if ($changed !== []) {
                    $fresh->save();
                }

                return $changed;
            });
        } catch (QueryException) {
            // تصادم unique هم‌زمان (کاربر دیگری همین لحظه همین شماره را گرفت).
            throw new ProfileCenterException('phone', 'ثبت این شماره ممکن نیست. شماره‌ی دیگری را امتحان کنید یا با پشتیبانی تماس بگیرید.');
        }

        // مدل در دست Channel هم به‌روز شود (نمایش بلافاصله، بدون refresh).
        $user->refresh();

        if ($changed !== []) {
            // فقط «کدام فیلد» ثبت می‌شود، نه مقدار (نام/موبایل PII است).
            $this->audit->record('profile.updated', $user, after: ['fields' => $changed], actor: $user);
        }

        return $changed;
    }

    /**
     * نام نمایشی تلگرام را (فقط در صورت مجاز بودن) روی مدل می‌گذارد — **ذخیره نمی‌کند** (Handler وب‌هوک خودش
     * `save()` می‌کند، چون برای کاربر تازه هنوز رکوردی نیست). مرجع واحد وب‌هوک ربات اصلی و ربات نماینده.
     *
     * نام «متعلق به تلگرام» است فقط وقتی: کاربر هرگز نامش را دستی ویرایش نکرده **و** (نام خالی است یا کاربر از
     * خود ربات آمده). کاربری که از Website ثبت‌نام کرده و بعد تلگرام وصل کرده، نام ثبت‌نامش را نگه می‌دارد.
     *
     * @return bool آیا نام عوض شد
     */
    public function applyTelegramName(User $user, string $telegramName): bool
    {
        $name = $this->tidy($telegramName);

        if ($name === '') {
            return false;
        }

        if ($user->full_name_edited_at !== null) {
            return false;
        }

        $hasName = trim((string) $user->full_name) !== '';

        if ($hasName && ! in_array($user->joined_from, self::TELEGRAM_MANAGED_ORIGINS, true)) {
            return false;
        }

        if ($user->full_name === $name) {
            return false;
        }

        $user->full_name = $name;

        return true;
    }

    // ───────────────────────── اعتبارسنجی ─────────────────────────

    protected function validName(mixed $value): string
    {
        if (! is_string($value)) {
            throw new ProfileCenterException('full_name', 'نام را وارد کنید.');
        }

        $name = $this->tidy($value);
        $length = mb_strlen($name);

        if ($length < self::NAME_MIN) {
            throw new ProfileCenterException('full_name', 'نام باید حداقل '.self::NAME_MIN.' نویسه باشد.');
        }

        if ($length > self::NAME_MAX) {
            throw new ProfileCenterException('full_name', 'نام نباید بیشتر از '.self::NAME_MAX.' نویسه باشد.');
        }

        // حداقل یک حرف (نه فقط رقم/علامت) و بدون نشانه‌ی HTML/لینک — نام در پنل ادمین، ایمیل و ربات نمایش داده می‌شود.
        if (preg_match('/\p{L}/u', $name) !== 1 || preg_match('/[<>]|https?:\/\/|www\./iu', $name) === 1) {
            throw new ProfileCenterException('full_name', 'نام واردشده معتبر نیست.');
        }

        return $name;
    }

    /** @return string|null  نرمال‌شده؛ null = حذف شماره */
    protected function validPhone(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new ProfileCenterException('phone', 'شماره‌ی موبایل معتبر نیست.');
        }

        if (trim($value) === '') {
            return null;
        }

        $phone = PhoneNumber::normalize($value);

        if ($phone === null) {
            throw new ProfileCenterException('phone', 'شماره‌ی موبایل معتبر نیست. نمونه: 09123456789');
        }

        return $phone;
    }

    // ───────────────────────── کمکی ─────────────────────────

    /**
     * شماره‌ی کاربر دیگری (حتی حذف‌شده‌ی نرم) با هر فرمتِ قدیمیِ ذخیره‌شده؟ ثبت‌نام قدیمی `phone` را بدون نرمال‌سازی
     * (با فاصله/خط‌تیره/پرانتز و پیش‌شماره‌های مختلف) ذخیره کرده است؛ پس علاوه بر مقدار دقیق، مقدارِ «بدون جداکننده»
     * هم با همه‌ی شکل‌های همان شماره سنجیده می‌شود. (ارقام فارسیِ ذخیره‌شده در دیتابیس پوشش داده نمی‌شوند؛ ثبت‌نام
     * هیچ‌وقت آن‌ها را نرمال نمی‌کرد و عملاً دیده نمی‌شوند.)
     */
    protected function phoneTakenByOther(?string $phone, User $self): bool
    {
        if ($phone === null) {
            return false;
        }

        $variants = PhoneNumber::variants($phone);
        $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '')";

        return User::withTrashed()
            ->where('id', '!=', $self->id)
            ->where(function ($q) use ($variants, $stripped) {
                $q->whereIn('phone', $variants)
                    ->orWhereRaw($stripped.' IN ('.implode(',', array_fill(0, count($variants), '?')).')', $variants);
            })
            ->exists();
    }

    /** شماره‌ی ذخیره‌شده‌ی قدیمی (`0912 345 6789`) با همان شماره‌ی نرمال‌شده «بدون تغییر» حساب شود. */
    protected function canonicalOrRaw(?string $stored): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }

        return PhoneNumber::normalize($stored) ?? $stored;
    }

    /**
     * trim، فاصله‌های پیاپی ⇒ یکی، حذف نویسه‌ی کنترلی، صفر-عرض مخرب و نویسه‌های جهت‌دهی دوطرفه (Spoofing).
     * نیم‌فاصله‌ی فارسی (ZWNJ) عمداً می‌ماند.
     */
    protected function tidy(string $value): string
    {
        $value = preg_replace('/[\p{Cc}\x{200B}\x{200D}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }
}
