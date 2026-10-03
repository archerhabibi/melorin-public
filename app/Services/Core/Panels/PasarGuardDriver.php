<?php

namespace App\Services\Core\Panels;

use App\DataTransferObjects\PanelAccountRequest;
use App\DataTransferObjects\PanelAccountResult;
use App\Models\ServerPanel;
use App\Services\Core\Panels\Concerns\BuildsPanelBaseUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * درایور پنل PasarGuard (نسخه‌ی ۴ به بعد).
 *
 * تحقیق فنی:
 * PasarGuard یک فورک است که مسیر مهاجرت رسمی و مستقیم از مرزبان دارد
 * (مستندسازی شده توسط خود پروژه) و توسعه‌دهنده‌ی mirzabot هم پس از تست
 * کامل، سازگاری آن با منطق مرزبان را تأیید کرده — به همین دلیل عملیات
 * پایه‌ی CRUD کاربر (ساخت/دریافت/ویرایش/حذف) همان الگوی /api/user مرزبان
 * را دنبال می‌کند: همان فیلدها (proxies, inbounds, data_limit, expire,
 * note) و همان شناسه‌گذاری بر مبنای username.
 *
 * تفاوت مهمی که باید در نظر داشت:
 * از نسخه ۴ به بعد، PasarGuard دیگر صرفاً کپی مرزبان نیست — RBAC
 * پیشرفته‌تری اضافه شده و برخی endpoint های آماری/فهرست‌گیری (نه CRUD
 * پایه) اکنون بر مبنای شناسه‌ی عددی کار می‌کنند، نه username. چون
 * AccountService ما فقط از عملیات CRUD پایه استفاده می‌کند (نه فهرست‌گیری
 * انبوه)، این تفاوت روی createAccount/getAccount/updateAccount/deleteAccount
 * تأثیری ندارد؛ صرفاً اگر بعداً بخش «آمار و گزارشات» (بند ۱۷ سند) به
 * endpoint های فهرست‌گیری وصل شد، باید این نکته دوباره بررسی شود.
 *
 * ⚠️ پیشنهاد: پیش از استفاده در production، createAccount را روی یک
 * پنل تستی PasarGuard واقعی اجرا کنید تا ساختار دقیق پاسخ (مخصوصاً
 * subscription_url) با نسخه‌ی نصب‌شده‌ی شما مطابقت داشته باشد.
 */
class PasarGuardDriver implements PanelDriverInterface, SupportsUsageReport
{
    use BuildsPanelBaseUrl;

    protected function token(ServerPanel $panel): string
    {
        return Cache::remember(
            "pasarguard_token_{$panel->id}",
            now()->addMinutes(55),
            function () use ($panel) {
                $credentials = $this->credentials($panel);

                $response = Http::asForm()->post(
                    $this->baseUrl($panel).'/api/admin/token',
                    [
                        'username' => $credentials['username'],
                        'password' => $credentials['password'],
                    ]
                );

                if (! $response->successful()) {
                    throw new PanelConnectionException(
                        "اتصال به پنل پاسارگارد '{$panel->name}' ناموفق بود: ".$response->status()
                    );
                }

                return $response->json('access_token');
            }
        );
    }

    protected function credentials(ServerPanel $panel): array
    {
        // ساختار مورد انتظار: {"username": "...", "password": "..."}
        return json_decode($panel->credentials, true) ?? [];
    }

    protected function http(ServerPanel $panel)
    {
        return Http::withToken($this->token($panel))->acceptJson();
    }

    public function createAccount(ServerPanel $panel, PanelAccountRequest $request): PanelAccountResult
    {
        $payload = [
            'username' => $request->username,
            'proxies' => $request->extra['proxies'] ?? new \stdClass,
            'inbounds' => $request->extra['inbounds'] ?? new \stdClass,
            'data_limit' => $request->trafficBytes,
            'expire' => $request->expireTimestamp,
            'note' => $request->note ?? '',
            'data_limit_reset_strategy' => 'no_reset',
        ];

        // در صورتی که گروه (group_ids) به‌جای inbound مشخص شده باشد —
        // مفهوم جدیدتری که PasarGuard اضافه کرده و مرزبان ندارد.
        if (! empty($request->extra['group_ids'])) {
            $payload['group_ids'] = $request->extra['group_ids'];
        }

        $response = $this->http($panel)->post($this->baseUrl($panel).'/api/user', $payload);

        if (! $response->successful()) {
            return PanelAccountResult::fail('ساخت اکانت در پاسارگارد ناموفق بود: '.$response->body(), $response->json());
        }

        $body = $response->json();

        return PanelAccountResult::ok($body, $this->toAbsoluteSubscriptionUrl($panel, $body['subscription_url'] ?? null));
    }

    /** Marzban/PasarGuard: فیلد used_traffic (بایت) در پاسخ GET /api/user/{username}. */
    public function usedTrafficBytes(PanelAccountResult $result): ?int
    {
        $used = $result->rawResponse['used_traffic'] ?? null;

        return is_numeric($used) ? max(0, (int) $used) : null;
    }

    public function getAccount(ServerPanel $panel, string $username): PanelAccountResult
    {
        $response = $this->http($panel)->get($this->baseUrl($panel)."/api/user/{$username}");

        if (! $response->successful()) {
            return PanelAccountResult::fail('اکانت یافت نشد یا خطا در دریافت اطلاعات.', $response->json());
        }

        $body = $response->json();

        return PanelAccountResult::ok($body, $this->toAbsoluteSubscriptionUrl($panel, $body['subscription_url'] ?? null));
    }

    public function updateAccount(ServerPanel $panel, string $username, PanelAccountRequest $request): PanelAccountResult
    {
        $payload = array_filter([
            'data_limit' => $request->trafficBytes,
            'expire' => $request->expireTimestamp,
            'note' => $request->note,
            'group_ids' => $request->extra['group_ids'] ?? null,
        ], fn ($v) => $v !== null);

        $response = $this->http($panel)->put($this->baseUrl($panel)."/api/user/{$username}", $payload);

        if (! $response->successful()) {
            return PanelAccountResult::fail('ویرایش اکانت ناموفق بود.', $response->json());
        }

        $body = $response->json();

        return PanelAccountResult::ok($body, $this->toAbsoluteSubscriptionUrl($panel, $body['subscription_url'] ?? null));
    }

    public function deleteAccount(ServerPanel $panel, string $username): PanelAccountResult
    {
        $response = $this->http($panel)->delete($this->baseUrl($panel)."/api/user/{$username}");

        return $response->successful()
            ? PanelAccountResult::ok()
            : PanelAccountResult::fail('حذف اکانت ناموفق بود.', $response->json());
    }

    public function resetUsage(ServerPanel $panel, string $username): PanelAccountResult
    {
        $response = $this->http($panel)->post($this->baseUrl($panel)."/api/user/{$username}/reset");

        return $response->successful()
            ? PanelAccountResult::ok()
            : PanelAccountResult::fail('بازنشانی حجم مصرفی ناموفق بود.', $response->json());
    }
}
