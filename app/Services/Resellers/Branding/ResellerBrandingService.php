<?php

namespace App\Services\Resellers\Branding;

use App\Models\Reseller;
use App\Models\ResellerWebsiteSetting;
use App\Models\User;
use App\Services\Core\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * تنها مسیر نوشتنِ برندینگ فروشگاه نماینده (B5.7). Controller وب (و در آینده پنل/B6) فقط این را صدا می‌زنند.
 *
 * قواعد:
 *  - فقط فهرست‌سفیدِ فیلدها نوشته می‌شود؛ `reseller_id` هرگز از ورودی نمی‌آید.
 *  - متن‌ها از نویسه‌ی کنترلی/صفر-عرض/جهت‌دهی دوطرفه پاک می‌شوند (نیم‌فاصله‌ی فارسی می‌ماند)؛ تهی ⇒ NULL.
 *  - لوگو: فایل جدید **اول** ذخیره می‌شود و فایل قبلی **بعد از موفقیتِ DB** پاک می‌شود (قبلاً ترتیب برعکس بود:
 *    اگر ذخیره‌ی جدید خطا می‌داد، لوگوی قبلی بی‌دلیل از بین رفته بود). حذف لوگو = `removeLogo`.
 *  - B6.2: همین قاعده برای سه «دارایی تصویری» (`logo`، `logo_dark`، `favicon`) یکسان است؛ لوگوی تیره بدون لوگوی
 *    اصلی پذیرفته نمی‌شود و حذف لوگوی اصلی، نسخه‌ی تیره‌اش را هم پاک می‌کند (تنها نمی‌ماند).
 *  - بدون تغییر واقعی ⇒ نه نوشتن، نه Audit.
 *  - Audit `reseller.branding.updated` فقط با **نام فیلدها** (مقدار تماس/متن ثبت نمی‌شود).
 */
class ResellerBrandingService
{
    public const LOGO_MAX_KB = 1024;

    public const LOGO_MAX_PIXELS = 2000;

    public const FAVICON_MAX_KB = 200;

    public const FAVICON_MIN_PIXELS = 32;

    public const FAVICON_MAX_PIXELS = 512;

    /** ستون ذخیره و پوشه‌ی هر دارایی تصویری. */
    private const ASSETS = [
        'logo' => ['column' => 'logo_path', 'dir' => 'reseller-logos'],
        'logo_dark' => ['column' => 'logo_dark_path', 'dir' => 'reseller-logos'],
        'favicon' => ['column' => 'favicon_path', 'dir' => 'reseller-favicons'],
    ];

    public const TEXT_FIELDS = ['display_name', 'contact_phone', 'contact_email', 'about_text', 'meta_description'];

    public function __construct(
        protected StoreBrandResolver $resolver,
        protected AuditService $audit,
    ) {}

    /**
     * قوانین اعتبارسنجی فرم؛ یک‌جا تا Controller هیچ قاعده‌ای نداشته باشد.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:100'],
            'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'about_text' => ['nullable', 'string', 'max:2000'],
            'allow_indexing' => ['nullable', 'boolean'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'remove_logo' => ['nullable', 'boolean'],
            'logo' => [
                'nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.self::LOGO_MAX_KB,
                'dimensions:max_width='.self::LOGO_MAX_PIXELS.',max_height='.self::LOGO_MAX_PIXELS,
            ],
            'remove_logo_dark' => ['nullable', 'boolean'],
            'logo_dark' => [
                'nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.self::LOGO_MAX_KB,
                'dimensions:max_width='.self::LOGO_MAX_PIXELS.',max_height='.self::LOGO_MAX_PIXELS,
            ],
            'remove_favicon' => ['nullable', 'boolean'],
            // Favicon مربعی و کوچک؛ SVG/ICO عمداً پذیرفته نمی‌شود (همان سیاست لوگو).
            'favicon' => [
                'nullable', 'image', 'mimes:png,webp', 'max:'.self::FAVICON_MAX_KB,
                'dimensions:ratio=1,min_width='.self::FAVICON_MIN_PIXELS.',min_height='.self::FAVICON_MIN_PIXELS
                    .',max_width='.self::FAVICON_MAX_PIXELS.',max_height='.self::FAVICON_MAX_PIXELS,
            ],
        ];
    }

    public function setting(Reseller $reseller): ?ResellerWebsiteSetting
    {
        return ResellerWebsiteSetting::forReseller($reseller);
    }

    public function readiness(Reseller $reseller): BrandingReadiness
    {
        return BrandingReadiness::of($this->setting($reseller));
    }

    /**
     * @param  array<string, mixed>  $data  فقط کلیدهای فهرست‌سفید خوانده می‌شود
     * @return list<string> نام فیلدهای واقعاً تغییرکرده (خالی = هیچ تغییری)
     *
     * @throws ValidationException لوگوی تیره بدون هیچ لوگوی اصلی
     */
    public function update(
        Reseller $reseller,
        User $actor,
        array $data,
        ?UploadedFile $logo = null,
        bool $removeLogo = false,
        ?UploadedFile $logoDark = null,
        bool $removeLogoDark = false,
        ?UploadedFile $favicon = null,
        bool $removeFavicon = false,
    ): array {
        /** @var array<string, array{0: ?UploadedFile, 1: bool}> $assets */
        $assets = [
            'logo' => [$logo, $removeLogo],
            'logo_dark' => [$logoDark, $removeLogoDark],
            'favicon' => [$favicon, $removeFavicon],
        ];

        $newPaths = [];
        $oldToDelete = [];

        try {
            $changed = DB::transaction(function () use ($reseller, $data, $assets, &$newPaths, &$oldToDelete): array {
                $setting = ResellerWebsiteSetting::query()->where('reseller_id', $reseller->id)->lockForUpdate()->first();

                $incoming = [];

                foreach (self::TEXT_FIELDS as $field) {
                    if (array_key_exists($field, $data)) {
                        $incoming[$field] = $field === 'about_text'
                            ? $this->tidyMultiline($data[$field])
                            : $this->tidy($data[$field]);
                    }
                }

                if (array_key_exists('brand_color', $data)) {
                    $color = trim((string) $data['brand_color']);
                    $incoming['brand_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : null;
                }

                if (array_key_exists('allow_indexing', $data)) {
                    $incoming['allow_indexing'] = filter_var($data['allow_indexing'], FILTER_VALIDATE_BOOLEAN);
                }

                // ─── دارایی‌های تصویری (لوگو، لوگوی تیره، Favicon) ───
                // لوگوی اصلی پس از این درخواست وجود دارد؟ (لوگوی تیره فقط کنار آن معنی دارد)
                [$logoFile, $logoRemoved] = $assets['logo'];
                $oldLogo = $setting?->logo_path;
                $logoWillExist = $logoFile !== null || (! $logoRemoved && $oldLogo);

                if ($assets['logo_dark'][0] !== null && ! $logoWillExist) {
                    throw ValidationException::withMessages([
                        'logo_dark' => 'لوگوی حالت تیره فقط کنار لوگوی اصلی معنی دارد؛ ابتدا لوگوی اصلی را بارگذاری کنید.',
                    ]);
                }

                foreach ($assets as $slot => [$file, $remove]) {
                    $column = self::ASSETS[$slot]['column'];
                    $old = $setting?->{$column};

                    if ($file) {
                        $path = $file->store(self::ASSETS[$slot]['dir'].'/'.$reseller->id, 'public');
                        $newPaths[] = $path;
                        $incoming[$column] = $path;
                    } elseif (($remove || ($slot === 'logo_dark' && ! $logoWillExist)) && $old) {
                        // نسخه‌ی تیره‌ی بی‌لوگوی اصلی تنها نمی‌ماند.
                        $incoming[$column] = null;
                    }
                }

                $changed = [];

                foreach ($incoming as $field => $value) {
                    $current = $setting?->{$field};

                    // ردیفِ هنوز نساخته: «بدون مقدار» (NULL/false) یعنی هیچ تغییری.
                    if ($field === 'allow_indexing') {
                        if ((bool) $current !== (bool) $value) {
                            $changed[] = $field;
                        }
                    } elseif ($current !== $value) {
                        $changed[] = $field;
                    }
                }

                if ($changed === []) {
                    return [];
                }

                ResellerWebsiteSetting::query()->updateOrCreate(
                    ['reseller_id' => $reseller->id],
                    array_intersect_key($incoming, array_flip($changed)),
                );

                foreach (self::ASSETS as $meta) {
                    $old = $setting?->{$meta['column']};

                    if ($old && in_array($meta['column'], $changed, true)) {
                        $oldToDelete[] = $old;
                    }
                }

                return $changed;
            });
        } catch (Throwable $e) {
            // DB (یا اعتبارسنجی) شکست خورد ⇒ فایل‌های تازه ذخیره‌شده را رها نکن؛ دارایی‌های قبلی دست‌نخورده می‌مانند.
            foreach ($newPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        if ($changed === []) {
            return [];
        }

        foreach ($oldToDelete as $path) {
            Storage::disk('public')->delete($path);
        }

        $this->resolver->forget($reseller);

        $this->audit->record(
            'reseller.branding.updated',
            $reseller,
            [],
            ['fields' => $changed],
            $actor,
        );

        return $changed;
    }

    /** trim، فاصله‌های پیاپی ⇒ یکی، حذف نویسه‌ی کنترلی/صفر-عرض/جهت‌دهی دوطرفه؛ تهی ⇒ null. نیم‌فاصله می‌ماند. */
    protected function tidy(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\p{Cc}\x{200B}\x{200D}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', ' ', (string) $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * مثل tidy ولی برای متن چندخطی «درباره‌ی فروشگاه»: خط‌های جدید حفظ می‌شوند (حداکثر یک خط خالی پیاپی)،
     * هر خط جداگانه پاک‌سازی می‌شود. ترتیب مهم است: اول `\r\n` به `\n`، بعد تقسیم به خط، بعد پاک‌سازی هر خط
     * (چون کلاس `\p{Cc}` خودِ `\n` را هم شامل می‌شود).
     */
    protected function tidyMultiline(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string) $value));
        $out = [];

        foreach ($lines as $line) {
            $line = $this->tidy($line) ?? '';

            if ($line === '' && ($out === [] || end($out) === '')) {
                continue; // خط خالیِ ابتدا یا پیاپی
            }

            $out[] = $line;
        }

        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }

        return $out === [] ? null : implode("\n", $out);
    }
}
