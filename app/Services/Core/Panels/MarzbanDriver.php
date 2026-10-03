<?php

namespace App\Services\Core\Panels;

use App\DataTransferObjects\PanelAccountRequest;
use App\DataTransferObjects\PanelAccountResult;
use App\Models\ServerPanel;
use App\Services\Core\Panels\Concerns\BuildsPanelBaseUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * درایور پنل Marzban.
 *
 * احراز هویت: توکن Bearer از مسیر /api/admin/token (فرم username/password)،
 * که برای جلوگیری از لاگین مکرر، به مدت ۵۵ دقیقه کش می‌شود.
 * مدیریت اکانت از طریق /api/user انجام می‌شود.
 *
 * منبع الهام: نحوه‌ی اتصال در ربات mirzabot بررسی و منطق مشابه، با پیاده‌سازی
 * تازه و مبتنی بر Laravel HTTP Client (به‌جای curl خام) نوشته شده است.
 */
class MarzbanDriver implements PanelDriverInterface, SupportsUsageReport
{
    use BuildsPanelBaseUrl;

    protected function token(ServerPanel $panel): string
    {
        return Cache::remember(
            "marzban_token_{$panel->id}",
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
                        "اتصال به پنل مرزبان '{$panel->name}' ناموفق بود: ".$response->status()
                    );
                }

                return $response->json('access_token');
            }
        );
    }

    protected function credentials(ServerPanel $panel): array
    {
        // فیلد credentials به‌صورت JSON رمزنگاری‌شده ذخیره می‌شود؛
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

        $response = $this->http($panel)->post($this->baseUrl($panel).'/api/user', $payload);

        if (! $response->successful()) {
            return PanelAccountResult::fail('ساخت اکانت در مرزبان ناموفق بود: '.$response->body(), $response->json());
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
