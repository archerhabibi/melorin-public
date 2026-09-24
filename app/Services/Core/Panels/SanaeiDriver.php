<?php

namespace App\Services\Core\Panels;

use App\DataTransferObjects\PanelAccountRequest;
use App\DataTransferObjects\PanelAccountResult;
use App\Models\ServerPanel;
use App\Services\Core\Panels\Concerns\BuildsPanelBaseUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * درایور پنل سنایی (3X-UI).
 *
 * احراز هویت: Bearer API Token (Settings → Security → API Token در پنل)،
 * نه یوزر/پس + کوکی نشست. توکن یک دسترسی full-admin است و باید مثل رمز
 * پنل با احتیاط نگه‌داری شود؛ در ستون credentials (رمزنگاری‌شده) با کلید
 * api_token ذخیره می‌شود.
 *
 * الگوی «کلون از یوزر نمونه»: به‌جای این‌که ادمین برای هر محصول مجبور
 * باشد inbound_id و flow و بقیه‌ی تنظیمات پروتکل را دستی وارد کند (که
 * دقیقاً همان چیزی بود که نسخه‌ی قبلیِ این درایور نیاز داشت)، کافی است
 * یک‌بار یوزرنیم (email) یک کلاینت نمونه که از قبل روی پنل و روی
 * inbound(های) درست ساخته شده را در extra_settings.template_username
 * پنل ثبت کنید. این درایور همان کلاینت را با GET /panel/api/clients/get
 * می‌خواند، inboundIds و flow/limitIp آن را می‌گیرد، و هر اکانت جدید را
 * دقیقاً با همان تنظیمات (فقط با email/uuid/حجم/انقضای خودش) می‌سازد.
 * این دقیقاً همان روشی است که در ربات میرزا هم استفاده می‌شود.
 */
class SanaeiDriver implements PanelDriverInterface, SupportsServerStatus, SupportsUsernameAvailability
{
    use BuildsPanelBaseUrl;

    protected function credentials(ServerPanel $panel): array
    {
        return json_decode($panel->credentials, true) ?? [];
    }

    protected function templateUsername(ServerPanel $panel): ?string
    {
        return $panel->extra_settings['template_username'] ?? null;
    }

    /**
     * برخلاف نسخه‌ی پیش‌فرض این متد در Trait (که در نبودِ تنظیم صریح،
     * baseUrl مدیریت پنل را به‌عنوان fallback برمی‌گرداند)، برای سنایی
     * این fallback عملاً همیشه غلط است: در 3X-UI سرویس Subscription روی
     * پورت کاملاً جدا از پورت مدیریت پنل سرو می‌شود (پیش‌فرض پنل معمولاً
     * چیزی شبیه «:2053/kharej» و Subscription چیزی شبیه «:2096/sub» —
     * با مسیر و پورت متفاوت که فقط ادمین می‌داند). به همین دلیل اینجا
     * override می‌شود تا بدون تنظیم صریح extra_settings['sub_base_url']،
     * با خطای روشن متوقف شویم به‌جای ساختن لینکی که اصلاً کار نمی‌کند.
     *
     * توجه مهم: این مقدار عیناً و بدون هیچ افزوده‌ای (نه «/sub»، نه هیچ
     * چیز دیگر) مستقیماً با subId چسبانده می‌شود (سطر
     * `$this->subBaseUrl($panel) . '/' . $subId` در createAccount).
     * یعنی خودِ ادمین باید مسیر Sub Path پنل (مثلاً «/sub») را داخل
     * همین مقدار وارد کند — کد هیچ بخشی را خودکار اضافه نمی‌کند.
     */
    protected function subBaseUrl(ServerPanel $panel): string
    {
        $base = $panel->extra_settings['sub_base_url'] ?? null;

        if (! $base) {
            throw new PanelConnectionException(
                "برای پنل سنایی '{$panel->name}' آدرس عمومی سرویس Subscription (sub_base_url) در تنظیمات سرور ثبت نشده. ".
                'این آدرس را از پنل 3X-UI ببینید: Settings → Subscription → Sub Port + Sub Path، و کامل (شامل مسیر) وارد کنید — چیزی خودکار اضافه نمی‌شود. '.
                'مثال: اگر Sub Port=2096 و Sub Path=/sub/ باشد، مقدار درست https://your-domain:2096/sub است.'
            );
        }

        return rtrim($base, '/');
    }

    /** درخواست آماده با هدر Authorization: Bearer <token> */
    protected function client(ServerPanel $panel): PendingRequest
    {
        $token = $this->credentials($panel)['api_token'] ?? null;

        if (! $token) {
            throw new PanelConnectionException(
                "برای پنل سنایی '{$panel->name}' هیچ API Token ثبت نشده — از Settings → Security → API Token در خودِ پنل بسازید."
            );
        }

        return Http::baseUrl($this->baseUrl($panel))
            ->withToken($token)
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * کلاینت نمونه را می‌خواند و inboundIds + flow + limitIp آن را
     * برمی‌گرداند. نتیجه به مدت ۵ دقیقه کش می‌شود تا هر ساخت اکانت یک
     * درخواست اضافه به پنل نزند.
     */
    protected function resolveTemplate(ServerPanel $panel): array
    {
        $templateUsername = $this->templateUsername($panel);

        if (! $templateUsername) {
            throw new PanelConnectionException(
                "برای پنل سنایی '{$panel->name}' یوزرنیم کلاینت نمونه (template_username) در تنظیمات پنل ثبت نشده."
            );
        }

        return Cache::remember(
            "sanaei_template_{$panel->id}",
            now()->addMinutes(5),
            function () use ($panel, $templateUsername) {
                $response = $this->client($panel)->get("/panel/api/clients/get/{$templateUsername}");

                $body = $response->json();
                $obj = $body['obj'] ?? null;

                // باگ واقعی: قبلاً `$response->json('obj') ?? $response->json()`
                // بود — وقتی پنل پاسخِ کاملاً معمولیِ «پیدا نشد» را می‌دهد
                // (HTTP 200 + success=false + obj=null)، obj خالی با کل
                // بدنه‌ی پاسخ (که خودش خالی نیست) جایگزین می‌شد و این تابع
                // آن را «کلاینتِ نمونه‌ی معتبر با inboundIds خالی» تلقی
                // می‌کرد. نتیجه‌ی نادرست هم ۵ دقیقه Cache می‌شد، یعنی حتی
                // بعد از برگشتنِ پنل، اولین retry همچنان همان خطای غلط
                // («به هیچ inbound‌ای متصل نیست») را می‌گرفت. اینجا صریحاً
                // فلگِ success پنل هم بررسی می‌شود تا این حالت واقعاً یک
                // Exception بدهد (و در نتیجه هرگز Cache نشود)، نه یک نتیجه‌ی
                // «موفقِ» جعلی.
                if (! $response->successful() || ($body['success'] ?? false) !== true || empty($obj)) {
                    throw new PanelConnectionException(
                        "کلاینت نمونه‌ی '{$templateUsername}' روی پنل سنایی '{$panel->name}' پیدا نشد. ابتدا یک کاربر با این نام و تنظیمات درست (پروتکل/inbound) دستی روی پنل بسازید."
                    );
                }

                return [
                    'inboundIds' => $obj['inboundIds'] ?? (isset($obj['inboundId']) ? [$obj['inboundId']] : []),
                    'flow' => $obj['flow'] ?? '',
                    'limitIp' => $obj['limitIp'] ?? 0,
                ];
            }
        );
    }

    protected function buildClientPayload(PanelAccountRequest $request, array $template, string $subId, ?string $existingId = null): array
    {
        return [
            'id' => $existingId ?? ($request->extra['uuid'] ?? (string) Str::uuid()),
            'password' => $request->extra['password'] ?? Str::random(16),
            'email' => $request->username,
            'flow' => $template['flow'] ?? '',
            'limitIp' => $template['limitIp'] ?? 0,
            'totalGB' => $request->trafficBytes,
            'expiryTime' => $request->expireTimestamp > 0 ? $request->expireTimestamp * 1000 : 0,
            'enable' => true,
            // باگ واقعی: قبلاً همیشه رشته‌ی خالی '' فرستاده می‌شد (چون
            // 'tg_id' هیچ‌وقت داخل extra ست نمی‌شد). ساختار Client در
            // بک‌اند Go پنل 3X-UI فیلد tgId را int64 تعریف کرده، نه
            // string — فرستادن '' (یا هر رشته‌ای) دقیقاً همان خطای واقعی
            // را می‌داد که گزارش شد:
            // «cannot unmarshal string into Go struct field Client.client.tgId of type int64»
            // اینجا هم نوع درست (int) رعایت می‌شود، هم — چون AccountService
            // حالا telegram_id واقعی خریدار را در extra['tg_id'] می‌گذارد —
            // مقدار واقعی کاربر ثبت می‌شود (نه صرفاً صفر)، که همان قابلیتی
            // است که در ربات میرزا هم برای اتصال کلاینت به شناسه‌ی تلگرام
            // کاربر استفاده می‌شود (GET /panel/api/clients/get/tgId/{tgId}).
            'tgId' => isset($request->extra['tg_id']) && $request->extra['tg_id'] !== null
                ? (int) $request->extra['tg_id']
                : 0,
            // subId دیگر اینجا به‌صورت تصادفی و بی‌نام تولید نمی‌شود —
            // AccountService از قبل آن را در extra['sub_id'] می‌گذارد تا
            // بلافاصله بعد از موفقیت این متد در دیتابیس مرکزی هم ذخیره
            // شود؛ در غیر این صورت (فراخوانی مستقیم درایور بدون AccountService)
            // یک مقدار تصادفی fallback تولید می‌شود.
            'subId' => $subId,
            'reset' => 0,
            'comment' => $request->note ?? '',
        ];
    }

    public function usernameExists(ServerPanel $panel, string $username): bool
    {
        try {
            $response = $this->client($panel)->get(
                '/panel/api/clients/get/'.rawurlencode($username)
            );

            $data = $response->json();

            // سنایی برای اکانت موجود معمولاً success=true برمی‌گرداند.
            if ($response->successful() && ($data['success'] ?? false) === true) {
                return true;
            }

            // رفتار واقعی سنایی برای username ناموجود:
            // HTTP 200 + success=false + obj=null
            if (
                $response->successful()
                && ($data['success'] ?? null) === false
                && ($data['obj'] ?? null) === null
                && str_contains(
                    Str::lower((string) ($data['msg'] ?? '')),
                    'record not found'
                )
            ) {
                return false;
            }

            // هیچ پاسخ غیرقابل‌تشخیصی را به‌عنوان username آزاد قبول نکن.
            throw new PanelConnectionException(
                "بررسی وجود نام کاربری '{$username}' روی پنل سنایی '{$panel->name}' ناموفق بود ({$response->status()}): ".
                $response->body()
            );
        } catch (PanelConnectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PanelConnectionException(
                "بررسی وجود نام کاربری '{$username}' روی پنل سنایی '{$panel->name}' ناموفق بود: ".
                $e->getMessage()
            );
        }
    }

    public function createAccount(ServerPanel $panel, PanelAccountRequest $request): PanelAccountResult
    {
        try {
            // fail-fast: قبل از ساخت واقعی کلاینت روی پنل بررسی می‌کنیم که
            // اصلاً بتوانیم لینک سابسکریپشن بسازیم — وگرنه در صورت نبودن
            // sub_base_url، یک کلاینت یتیم روی پنل ساخته می‌شد که هرگز به
            // دیتابیس مرکزی متصل نمی‌شد (چون AccountService نتیجه را fail
            // می‌دید و رول‌بک می‌کرد، ولی خودِ کلاینت روی 3X-UI باقی می‌ماند).
            $this->subBaseUrl($panel);
            $template = $this->resolveTemplate($panel);
        } catch (PanelConnectionException $e) {
            return PanelAccountResult::fail($e->getMessage());
        }

        if (empty($template['inboundIds'])) {
            return PanelAccountResult::fail('کلاینت نمونه به هیچ inbound‌ای متصل نیست — روی پنل بررسی کنید.');
        }

        $subId = $request->extra['sub_id'] ?? Str::random(16);
        $client = $this->buildClientPayload($request, $template, $subId);

        $response = $this->client($panel)->post('/panel/api/clients/add', [
            'client' => $client,
            'inboundIds' => $template['inboundIds'],
        ]);

        if (! $response->successful() || ($response->json('success') === false)) {
            return PanelAccountResult::fail('ساخت اکانت در سنایی ناموفق بود: '.$response->body(), $response->json());
        }

        return PanelAccountResult::ok(
            $response->json(),
            $this->subBaseUrl($panel).'/'.$subId,
            panelExtra: ['subscription_id' => $subId],
        );
    }

    public function getAccount(ServerPanel $panel, string $username): PanelAccountResult
    {
        $info = $this->client($panel)->get("/panel/api/clients/get/{$username}");
        $obj = $info->json('obj') ?? $info->json();

        if (! $info->successful() || empty($obj)) {
            return PanelAccountResult::fail('اکانت یافت نشد یا خطا در دریافت اطلاعات.', $info->json());
        }

        $traffic = $this->client($panel)->get("/panel/api/clients/traffic/{$username}");
        $subId = $obj['subId'] ?? null;

        return PanelAccountResult::ok(
            array_merge(
                ['client' => $obj],
                ['traffic' => $traffic->successful() ? ($traffic->json('obj') ?? $traffic->json()) : null]
            ),
            $subId ? $this->safeSubscriptionUrl($panel, $subId) : null,
            panelExtra: ['subscription_id' => $subId],
        );
    }

    public function updateAccount(ServerPanel $panel, string $username, PanelAccountRequest $request): PanelAccountResult
    {
        // API «/clients/update» کل ردیف را جایگزین می‌کند، نه patch —
        // پس باید کلاینت فعلی را کامل بخوانیم و فقط فیلدهای لازم را عوض
        // کنیم، وگرنه فیلدهایی مثل flow/uuid موجود پاک می‌شوند.
        $current = $this->client($panel)->get("/panel/api/clients/get/{$username}");
        $currentObj = $current->json('obj') ?? $current->json();

        if (! $current->successful() || empty($currentObj)) {
            return PanelAccountResult::fail('اکانت برای ویرایش پیدا نشد.', $current->json());
        }

        $merged = array_merge($currentObj, [
            'totalGB' => $request->trafficBytes,
            'expiryTime' => $request->expireTimestamp > 0 ? $request->expireTimestamp * 1000 : 0,
            'enable' => true,
        ]);

        $response = $this->client($panel)->post("/panel/api/clients/update/{$username}", $merged);

        if (! $response->successful()) {
            return PanelAccountResult::fail('ویرایش اکانت ناموفق بود.', $response->json());
        }

        // subId موقع تمدید عوض نمی‌شود؛ همان مقدار قبلی (که از currentObj
        // خوانده شده) استفاده می‌شود — لینک سابسکریپشن قبلی کاربر معتبر
        // می‌ماند، نیازی به تحویل دوباره‌ی QR جدید نیست.
        $subId = $currentObj['subId'] ?? ($request->extra['sub_id'] ?? null);

        return PanelAccountResult::ok(
            $response->json(),
            $subId ? $this->safeSubscriptionUrl($panel, $subId) : null,
            panelExtra: ['subscription_id' => $subId],
        );
    }

    /**
     * نسخه‌ی «نرم» ساخت لینک سابسکریپشن برای مسیرهایی (renew/getAccount)
     * که نبودِ sub_base_url نباید کل عملیات را متوقف کند — چون اکانت روی
     * پنل از قبل موجود است و عملیات اصلی (تمدید/استعلام) با موفقیت انجام
     * شده؛ فقط بازسازی لینک برای نمایش ممکن نیست.
     */
    protected function safeSubscriptionUrl(ServerPanel $panel, string $subId): ?string
    {
        try {
            return $this->subBaseUrl($panel).'/'.$subId;
        } catch (PanelConnectionException) {
            return null;
        }
    }

    public function deleteAccount(ServerPanel $panel, string $username): PanelAccountResult
    {
        $response = $this->client($panel)->post("/panel/api/clients/del/{$username}");

        return $response->successful()
            ? PanelAccountResult::ok($response->json())
            : PanelAccountResult::fail('حذف اکانت ناموفق بود.', $response->json());
    }

    public function resetUsage(ServerPanel $panel, string $username): PanelAccountResult
    {
        $response = $this->client($panel)->post("/panel/api/clients/resetTraffic/{$username}");

        return $response->successful()
            ? PanelAccountResult::ok($response->json())
            : PanelAccountResult::fail('بازنشانی حجم مصرفی ناموفق بود.', $response->json());
    }

    /**
     * وضعیت لحظه‌ای سرور (بند ۵.۲ سند: «مدیر سیستم بتواند وضعیت سلامت
     * سرور را مشاهده کند»)، دقیقاً از GET /panel/api/server/status —
     * همان اطلاعاتی که در پنل خودِ 3X-UI و در ربات میرزا («🖥 وضعیت
     * سرور») نمایش داده می‌شود.
     */
    public function getServerStatus(ServerPanel $panel): array
    {
        $response = $this->client($panel)->get('/panel/api/server/status');

        if (! $response->successful()) {
            throw new PanelConnectionException(
                "دریافت وضعیت سرور ناموفق بود ({$response->status()}): ".$response->body()
            );
        }

        $status = $response->json('obj') ?? $response->json();

        $toGb = fn ($bytes) => $bytes ? round($bytes / 1024 / 1024 / 1024, 2) : 0;

        return [
            'پردازنده' => isset($status['cpu']) ? round($status['cpu'], 1).'%' : '—',
            'هسته‌های CPU' => $status['cpuCores'] ?? '—',
            'رم مصرفی' => isset($status['mem']['current'])
                ? $toGb($status['mem']['current']).' / '.$toGb($status['mem']['total'] ?? 0).' گیگابایت'
                : '—',
            'دیسک مصرفی' => isset($status['disk']['current'])
                ? $toGb($status['disk']['current']).' / '.$toGb($status['disk']['total'] ?? 0).' گیگابایت'
                : '—',
            'آپلود کل' => $toGb($status['netTraffic']['sent'] ?? 0).' گیگابایت',
            'دانلود کل' => $toGb($status['netTraffic']['recv'] ?? 0).' گیگابایت',
            'وضعیت Xray' => ($status['xray']['state'] ?? null) === 'running' ? '🟢 فعال' : '🔴 غیرفعال',
            'نسخه Xray' => $status['xray']['version'] ?? '—',
            'آپتایم (ثانیه)' => $status['uptime'] ?? '—',
        ];
    }
}
