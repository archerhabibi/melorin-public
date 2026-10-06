<?php

namespace App\Services\Resellers\Domains;

use App\Models\Admin;
use App\Models\Reseller;
use App\Models\ResellerWebsiteSetting;
use App\Models\User;
use App\Services\Core\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * B6.1 — Custom Domain: تنها مسیر ثبت/تأیید/حذف دامنه‌ی اختصاصی فروشگاه نماینده و تنها مسیر «Host → نماینده».
 *
 * قواعد:
 *  - دامنه نرمال‌سازی و سخت‌گیرانه اعتبارسنجی می‌شود (بدون scheme/port/path/IP/wildcard، بدون دامنه‌ی خودِ پلتفرم و زیردامنه‌هایش).
 *  - هر دامنه در کل پلتفرم یکتاست؛ پیام «اشغال است» نمی‌گوید مال کیست.
 *  - فقط دامنه‌ی `verified` مسیریابی می‌شود. تأیید مالکیت با رکورد TXT `{prefix}.{domain}` = توکن تصادفی.
 *  - تغییر دامنه، تأییدِ قبلی را باطل و توکن تازه می‌سازد (دامنه‌ی جدید دوباره باید ثابت شود).
 *  - ادعای تأییدنشده‌ی قدیمی (pending_ttl_hours) آزاد می‌شود تا کسی با «ثبت‌کردن» دامنه‌ی دیگران را اشغال نکند.
 *  - بازبررسی دوره‌ای (`runChecks`): pending خودکار تأیید می‌شود؛ verified اگر TXT مدتِ lost_grace_hours دیده نشود
 *    به pending برمی‌گردد (مسیریابی/TLS قطع) تا دامنه‌ی از‌دست‌رفته همچنان سرو نشود.
 *  - Audit: `reseller.domain.set|verified|removed|revoked|lost|expired` (دامنه محرمانه نیست؛ توکن هرگز ثبت نمی‌شود).
 *  - هیچ Business Rule مالی اینجا نیست؛ فقط هویت Host.
 */
class ResellerDomainService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    /** پسوندهایی که هرگز دامنه‌ی عمومی نیستند. */
    private const RESERVED_SUFFIXES = ['local', 'localhost', 'internal', 'lan', 'invalid', 'onion', 'home', 'corp'];

    public function __construct(
        protected DnsTxtResolver $dns,
        protected AuditService $audit,
    ) {}

    /** Hostِ خودِ پلتفرم (از APP_URL)، حروف کوچک. */
    public function platformHost(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /**
     * @throws InvalidArgumentException پیام فارسیِ قابل‌نمایش به کاربر
     */
    public function normalize(string $input): string
    {
        $value = strtolower(trim($input));
        $value = preg_replace('#^https?://#', '', $value) ?? $value;
        $value = preg_split('#[/?\#]#', $value, 2)[0] ?? '';
        $value = rtrim($value, '.');

        if ($value === '') {
            throw new InvalidArgumentException('نام دامنه را وارد کنید.');
        }

        if (str_contains($value, '@') || str_contains($value, ':')) {
            throw new InvalidArgumentException('فقط نام دامنه را بنویسید (بدون پورت، نام‌کاربری یا آدرس کامل).');
        }

        if (str_contains($value, '*')) {
            throw new InvalidArgumentException('دامنه‌ی wildcard پذیرفته نمی‌شود.');
        }

        if (preg_match('/[^\x00-\x7F]/', $value)) {
            $ascii = function_exists('idn_to_ascii')
                ? idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
                : false;

            if (! is_string($ascii) || $ascii === '') {
                throw new InvalidArgumentException('نام دامنه نامعتبر است؛ نسخه‌ی punycode (xn--…) را وارد کنید.');
            }

            $value = strtolower($ascii);
        }

        if (strlen($value) > 253) {
            throw new InvalidArgumentException('نام دامنه بیش از حد طولانی است.');
        }

        $labels = explode('.', $value);

        if (count($labels) < 2) {
            throw new InvalidArgumentException('نام دامنه باید کامل باشد (مثلاً shop.example.com).');
        }

        foreach ($labels as $label) {
            if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                throw new InvalidArgumentException('نام دامنه نامعتبر است.');
            }
        }

        $tld = end($labels);

        if (! preg_match('/^([a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $tld) || in_array($tld, self::RESERVED_SUFFIXES, true)) {
            throw new InvalidArgumentException('پسوند دامنه معتبر نیست.');
        }

        $platform = $this->platformHost();

        if ($platform !== '' && ($value === $platform || str_ends_with($value, '.'.$platform))) {
            throw new InvalidArgumentException('دامنه‌ی خودِ پلتفرم و زیردامنه‌های آن قابل ثبت نیست.');
        }

        return $value;
    }

    /** نام و مقدار رکورد TXT لازم برای تأیید. */
    public function txtName(string $domain): string
    {
        return trim((string) config('melorin.domains.txt_prefix', '_melorin-verify'), '.').'.'.$domain;
    }

    /**
     * وضعیت فعلی دامنه‌ی نماینده برای نمایش.
     *
     * @return array{domain: ?string, status: ?string, verified: bool, txt_name: ?string, txt_value: ?string, verified_at: mixed, checked_at: mixed, expires_at: mixed}
     */
    public function state(Reseller $reseller): array
    {
        $s = ResellerWebsiteSetting::forReseller($reseller);
        $domain = $s?->custom_domain;

        return [
            'domain' => $domain,
            'status' => $domain ? $s->custom_domain_status : null,
            'verified' => $domain !== null && $s->custom_domain_status === self::STATUS_VERIFIED,
            'txt_name' => $domain ? $this->txtName($domain) : null,
            'txt_value' => $domain ? $s->custom_domain_token : null,
            'verified_at' => $s?->custom_domain_verified_at,
            'checked_at' => $s?->custom_domain_checked_at,
            // فقط برای pending: پس از این زمان ادعا آزاد می‌شود (مگر تأیید شود).
            'expires_at' => ($domain && $s->custom_domain_status === self::STATUS_PENDING && $s->custom_domain_claimed_at)
                ? $s->custom_domain_claimed_at->copy()->addHours($this->pendingTtlHours())
                : null,
        ];
    }

    /**
     * ثبت (یا تغییر) دامنه؛ وضعیت = pending با توکن تازه.
     *
     * @return bool true اگر چیزی تغییر کرد (همان دامنه‌ی فعلی ⇒ false، بدون نوشتن و بدون Audit)
     *
     * @throws InvalidArgumentException
     */
    public function set(Reseller $reseller, User $actor, string $input): bool
    {
        $domain = $this->normalize($input);

        try {
            return DB::transaction(function () use ($reseller, $actor, $domain) {
                $setting = ResellerWebsiteSetting::query()
                    ->where('reseller_id', $reseller->id)
                    ->lockForUpdate()
                    ->first();

                if ($setting && $setting->custom_domain === $domain) {
                    return false;
                }

                $holder = ResellerWebsiteSetting::query()
                    ->where('custom_domain', $domain)
                    ->where('reseller_id', '!=', $reseller->id)
                    ->lockForUpdate()
                    ->first();

                if ($holder) {
                    // فقط ادعای تأییدنشده‌ی قدیمی قابل‌آزادسازی است؛ دامنه‌ی تأییدشده/ادعای تازه هرگز.
                    if (! $this->isStalePending($holder)) {
                        throw new InvalidArgumentException('این دامنه قبلاً در پلتفرم ثبت شده است.');
                    }

                    $this->release($holder, 'reseller.domain.expired');
                }

                $setting ??= (new ResellerWebsiteSetting)->forceFill(['reseller_id' => $reseller->id]);
                $old = $setting->custom_domain;

                $setting->forceFill([
                    'custom_domain' => $domain,
                    'custom_domain_token' => Str::random(40),
                    'custom_domain_status' => self::STATUS_PENDING,
                    'custom_domain_verified_at' => null,
                    'custom_domain_checked_at' => null,
                    'custom_domain_claimed_at' => now(),
                ])->save();

                $this->forgetHost($old);
                $this->forgetHost($domain);

                $this->audit->record('reseller.domain.set', $reseller, ['domain' => $old], ['domain' => $domain], $actor);

                return true;
            });
        } catch (QueryException) {
            // رقابت دو ثبت هم‌زمان؛ قید یکتا ستون دومی را رد کرده است.
            throw new InvalidArgumentException('این دامنه قبلاً در پلتفرم ثبت شده است.');
        }
    }

    /**
     * بررسی رکورد TXT. موفق ⇒ verified. شکست ⇒ فقط `checked_at` به‌روز می‌شود.
     *
     * @throws InvalidArgumentException وقتی دامنه‌ای ثبت نشده
     */
    public function verify(Reseller $reseller, User $actor): bool
    {
        $setting = ResellerWebsiteSetting::forReseller($reseller);

        if (! $setting || ! $setting->custom_domain) {
            throw new InvalidArgumentException('ابتدا یک دامنه ثبت کنید.');
        }

        if ($setting->custom_domain_status === self::STATUS_VERIFIED) {
            return true;
        }

        $found = $this->txtMatches((string) $setting->custom_domain, (string) $setting->custom_domain_token);

        $setting->forceFill(['custom_domain_checked_at' => now()]);

        if ($found) {
            $setting->forceFill([
                'custom_domain_status' => self::STATUS_VERIFIED,
                'custom_domain_verified_at' => now(),
            ]);
        }

        $setting->save();

        if ($found) {
            $this->forgetHost($setting->custom_domain);
            $this->audit->record('reseller.domain.verified', $reseller, [], ['domain' => $setting->custom_domain], $actor);
        }

        return $found;
    }

    /** @return bool true اگر دامنه‌ای بود و حذف شد */
    public function remove(Reseller $reseller, User $actor): bool
    {
        return $this->removeAs($reseller, $actor, 'reseller.domain.removed');
    }

    /**
     * لغو دامنه‌ی نماینده توسط ادمین پلتفرم (سوءاستفاده/فیشینگ/درخواست پشتیبانی). همان حذف است با Audit جدا.
     *
     * @return bool true اگر دامنه‌ای بود و لغو شد
     */
    public function revoke(Reseller $reseller, Admin $admin): bool
    {
        return $this->removeAs($reseller, $admin, 'reseller.domain.revoked');
    }

    private function removeAs(Reseller $reseller, Admin|User $actor, string $action): bool
    {
        return DB::transaction(function () use ($reseller, $actor, $action) {
            $setting = ResellerWebsiteSetting::query()
                ->where('reseller_id', $reseller->id)
                ->lockForUpdate()
                ->first();

            if (! $setting || ! $setting->custom_domain) {
                return false;
            }

            $old = $setting->custom_domain;

            $this->clear($setting);
            $this->forgetHost($old);

            $this->audit->record($action, $reseller, ['domain' => $old], [], $actor);

            return true;
        });
    }

    /**
     * بازبررسی زمان‌بندی‌شده (Scheduler): ادعاهای pending خودکار تأیید می‌شوند (بدون کلیک)، ادعای قدیمی آزاد می‌شود،
     * و دامنه‌ی verified که TXT اش مدتی دیده نشود به pending برمی‌گردد. DNS بیرون از Transaction خوانده می‌شود و
     * نتیجه روی ردیف قفل‌شده فقط وقتی اعمال می‌شود که دامنه/توکن در این فاصله عوض نشده باشد.
     *
     * @return array{checked: int, verified: int, confirmed: int, lost: int, expired: int, skipped: int}
     */
    public function runChecks(int $limit = 100): array
    {
        $summary = ['checked' => 0, 'verified' => 0, 'confirmed' => 0, 'lost' => 0, 'expired' => 0, 'skipped' => 0];

        if (! config('melorin.domains.enabled', true)) {
            return $summary;
        }

        $recheckBefore = now()->subHours(max(1, (int) config('melorin.domains.recheck_hours', 6)));

        $rows = ResellerWebsiteSetting::query()
            ->whereNotNull('custom_domain')
            ->where(function ($q) use ($recheckBefore) {
                $q->where('custom_domain_status', self::STATUS_PENDING)
                    ->orWhere(function ($q) use ($recheckBefore) {
                        $q->where('custom_domain_status', self::STATUS_VERIFIED)
                            ->where(fn ($q) => $q->whereNull('custom_domain_checked_at')->orWhere('custom_domain_checked_at', '<', $recheckBefore));
                    });
            })
            ->orderBy('custom_domain_checked_at')
            ->limit(max(1, $limit))
            ->get(['id', 'custom_domain', 'custom_domain_token']);

        foreach ($rows as $row) {
            $summary['checked']++;

            $found = $this->txtMatches((string) $row->custom_domain, (string) $row->custom_domain_token);
            $outcome = $this->applyCheck((int) $row->id, (string) $row->custom_domain, (string) $row->custom_domain_token, $found);

            if (isset($summary[$outcome])) {
                $summary[$outcome]++;
            }
        }

        return $summary;
    }

    /** @return string verified|confirmed|lost|expired|skipped|pending|missing */
    private function applyCheck(int $id, string $domain, string $token, bool $found): string
    {
        return DB::transaction(function () use ($id, $domain, $token, $found) {
            $s = ResellerWebsiteSetting::query()->lockForUpdate()->find($id);

            // در فاصله‌ی خواندن DNS تغییر کرده/حذف شده ⇒ نتیجه‌ی قدیمی اعمال نمی‌شود.
            if (! $s || $s->custom_domain !== $domain || (string) $s->custom_domain_token !== $token) {
                return 'skipped';
            }

            $reseller = $s->reseller;

            if ($s->custom_domain_status === self::STATUS_PENDING) {
                if ($found) {
                    $s->forceFill([
                        'custom_domain_status' => self::STATUS_VERIFIED,
                        'custom_domain_verified_at' => now(),
                        'custom_domain_checked_at' => now(),
                    ])->save();
                    $this->forgetHost($domain);
                    $this->audit->record('reseller.domain.verified', $reseller, [], ['domain' => $domain]);

                    return 'verified';
                }

                if ($this->isStalePending($s)) {
                    $this->release($s, 'reseller.domain.expired');

                    return 'expired';
                }

                $s->forceFill(['custom_domain_checked_at' => now()])->save();

                return 'pending';
            }

            if ($found) {
                $s->forceFill(['custom_domain_checked_at' => now()])->save();

                return 'confirmed';
            }

            // verified ولی TXT دیده نمی‌شود: «آخرین تأیید» = checked_at (یا verified_at). Grace برای خطاهای گذرای DNS.
            $lastSeen = $s->custom_domain_checked_at ?? $s->custom_domain_verified_at;
            $grace = max(1, (int) config('melorin.domains.lost_grace_hours', 72));

            if ($lastSeen && $lastSeen->gt(now()->subHours($grace))) {
                return 'missing';
            }

            // به pending برمی‌گردد (ادعا را از دست نمی‌دهد؛ TTL تازه) ⇒ مسیریابی و صدور TLS قطع.
            $s->forceFill([
                'custom_domain_status' => self::STATUS_PENDING,
                'custom_domain_verified_at' => null,
                'custom_domain_checked_at' => now(),
                'custom_domain_claimed_at' => now(),
            ])->save();
            $this->forgetHost($domain);
            $this->audit->record('reseller.domain.lost', $reseller, ['domain' => $domain, 'status' => self::STATUS_VERIFIED], ['domain' => $domain, 'status' => self::STATUS_PENDING]);

            return 'lost';
        });
    }

    /** مقایسه‌ی TXT با توکن (hash_equals؛ نقل‌قول‌های اطراف پذیرفته). */
    private function txtMatches(string $domain, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        foreach ($this->dns->txt($this->txtName($domain)) as $record) {
            if (hash_equals($token, trim($record, " \t\n\r\0\x0B\"'"))) {
                return true;
            }
        }

        return false;
    }

    private function pendingTtlHours(): int
    {
        return max(1, (int) config('melorin.domains.pending_ttl_hours', 72));
    }

    /** ادعای تأییدنشده‌ای که از TTL گذشته (claimed_at ناموجود هم قدیمی حساب می‌شود). */
    private function isStalePending(ResellerWebsiteSetting $s): bool
    {
        return $s->custom_domain_status !== self::STATUS_VERIFIED
            && (! $s->custom_domain_claimed_at || $s->custom_domain_claimed_at->lt(now()->subHours($this->pendingTtlHours())));
    }

    /** آزادسازی ادعا (با Audit) — برای ادعای منقضی. */
    private function release(ResellerWebsiteSetting $s, string $action): void
    {
        $old = $s->custom_domain;
        $reseller = $s->reseller;

        $this->clear($s);
        $this->forgetHost($old);

        $this->audit->record($action, $reseller, ['domain' => $old], []);
    }

    private function clear(ResellerWebsiteSetting $s): void
    {
        $s->forceFill([
            'custom_domain' => null,
            'custom_domain_token' => null,
            'custom_domain_status' => null,
            'custom_domain_verified_at' => null,
            'custom_domain_checked_at' => null,
            'custom_domain_claimed_at' => null,
        ])->save();
    }

    private function hostCacheKey(string $host): string
    {
        return 'melorin:domain-host:'.md5($host);
    }

    /** کش Host → نماینده باید با هر تغییر وضعیت دامنه باطل شود (فقط این سرویس می‌نویسد). */
    private function forgetHost(?string $host): void
    {
        if ($host) {
            Cache::forget($this->hostCacheKey(strtolower($host)));
        }
    }

    /**
     * Host → نماینده‌ی صاحب آن (فقط دامنه‌ی تأییدشده). وضعیت فعال‌بودنِ نماینده را صدا‌زننده چک می‌کند.
     * فقط «Host → شناسه‌ی نماینده» کش می‌شود (cache_ttl ثانیه، با هر تغییر وضعیت باطل می‌شود)؛ خودِ نماینده
     * (و وضعیت فعال/غیرفعالش) همیشه تازه خوانده می‌شود. Hostِ بی‌شکلِ دامنه‌نما بدون DB/کش رد می‌شود.
     */
    public function resellerForHost(string $host): ?Reseller
    {
        $host = strtolower(rtrim(trim($host), '.'));

        if ($host === '' || strlen($host) > 253 || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }

        $lookup = fn (): int => (int) ResellerWebsiteSetting::query()
            ->where('custom_domain', $host)
            ->where('custom_domain_status', self::STATUS_VERIFIED)
            ->value('reseller_id');

        $ttl = (int) config('melorin.domains.cache_ttl', 60);
        $id = $ttl > 0 ? (int) Cache::remember($this->hostCacheKey($host), $ttl, $lookup) : $lookup();

        return $id > 0 ? Reseller::query()->find($id) : null;
    }

    /** آیا برای این Host باید سرویس داد (و مثلاً گواهی TLS صادر شود)؟ تأییدشده + نماینده‌ی فعال. */
    public function isServable(string $host): bool
    {
        return $this->resellerForHost($host)?->status === 'active';
    }
}
