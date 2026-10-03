<?php

namespace App\Services\Core\Identity;

use App\Models\User;
use App\Services\Core\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * B2.5 — مدیریت و ابطال نشست‌های کاربر (SESSION-SECURITY-CONTRACT.md).
 *
 * فقط با `SESSION_DRIVER=database` ممکن است (فهرست و حذف سطر). با Driver دیگر `supported()` false
 * است و همه‌ی عملیات بی‌اثرند؛ در آن حالت ابطال دستگاه‌های دیگر به `AuthenticateSession` (مقایسه‌ی Hash
 * رمز) سپرده می‌شود.
 *
 * نکته‌ی مالکیت: Filament با `shouldUse(guard)` گارد پیش‌فرض را در پنل‌ها عوض می‌کند، پس ستون
 * `sessions.user_id` برای نشست Admin/Reseller هم پر می‌شود و می‌تواند با id یک User عادی برخورد کند.
 * بنابراین مالکیت از روی خودِ payload (کلید login_web_*) تأیید می‌شود، نه فقط `user_id`.
 */
class SessionSecurityService
{
    public function __construct(protected AuditService $audit) {}

    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * نشست‌های فعال (منقضی‌نشده) User، تازه‌ترین اول. فهرست «Fail-closed» است: سطری که مالکیتش
     * از payload قابل‌اثبات نیست نمایش داده نمی‌شود.
     *
     * @return Collection<int, ActiveSession>
     */
    public function activeFor(User $user, ?string $currentSessionId): Collection
    {
        if (! $this->supported()) {
            return collect();
        }

        $cutoff = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        try {
            $rows = DB::table($this->table())
                ->where('user_id', $user->id)
                ->where('last_activity', '>=', $cutoff)
                ->orderByDesc('last_activity')
                ->get(['id', 'ip_address', 'user_agent', 'payload', 'last_activity']);
        } catch (Throwable $e) {
            Log::warning('session_list_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return collect();
        }

        return $rows
            ->filter(fn ($row) => $this->ownedByWebUser($row, $user, strict: true))
            ->map(fn ($row) => new ActiveSession(
                handle: $this->handle((string) $row->id),
                ip: $row->ip_address,
                userAgent: $row->user_agent,
                lastActivity: Carbon::createFromTimestamp((int) $row->last_activity),
                isCurrent: $currentSessionId !== null && hash_equals((string) $row->id, $currentSessionId),
            ))
            ->values();
    }

    /**
     * پایان همه‌ی نشست‌های دیگر (نه نشست فعلی). تعداد حذف‌شده را برمی‌گرداند و فقط اگر چیزی حذف شد
     * Audit می‌کند (بدون IP/UA؛ فقط تعداد و دلیل).
     */
    public function revokeOthers(User $user, ?string $currentSessionId, string $reason = 'user_request'): int
    {
        $ids = $this->sessionIdsOf($user, strict: false)
            ->reject(fn (string $id) => $currentSessionId !== null && hash_equals($id, $currentSessionId))
            ->all();

        $count = $this->deleteIds($ids);

        if ($count > 0) {
            $this->audit->record('identity.sessions_revoked', $user, after: ['scope' => 'others', 'count' => $count, 'reason' => $reason], actor: $user);
        }

        return $count;
    }

    /**
     * پایان یک نشست مشخص با handle. نشست فعلی و نشست متعلق به دیگری هرگز با این مسیر حذف نمی‌شود.
     */
    public function revokeByHandle(User $user, string $handle, ?string $currentSessionId): bool
    {
        foreach ($this->sessionIdsOf($user, strict: true) as $id) {
            if (! hash_equals($this->handle($id), $handle)) {
                continue;
            }

            if ($currentSessionId !== null && hash_equals($id, $currentSessionId)) {
                return false;
            }

            if ($this->deleteIds([$id]) === 1) {
                $this->audit->record('identity.sessions_revoked', $user, after: ['scope' => 'one', 'count' => 1, 'reason' => 'user_request'], actor: $user);

                return true;
            }
        }

        return false;
    }

    /**
     * پایان همه‌ی نشست‌های User (مثلاً پس از Reset رمز). Audit را فراخوان انجام می‌دهد.
     * Fail-open عمداً: سطری که payload آن قابل‌خواندن نیست حذف می‌شود (ابطال ناکامل بدتر از ابطال اضافه
     * است)؛ فقط نشستی که اثبات می‌شود متعلق به Guard دیگری است (Admin/Reseller) دست‌نخورده می‌ماند.
     */
    public function revokeAll(User $user): int
    {
        return $this->deleteIds($this->sessionIdsOf($user, strict: false)->all());
    }

    /** شناسه‌ی مبهم نشست برای فرم‌ها؛ HMAC با APP_KEY، نه Session ID. */
    public function handle(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    // ───────────────────────── internals ─────────────────────────

    protected function table(): string
    {
        return (string) config('session.table', 'sessions');
    }

    /** @return Collection<int, string> */
    protected function sessionIdsOf(User $user, bool $strict): Collection
    {
        if (! $this->supported()) {
            return collect();
        }

        try {
            return DB::table($this->table())
                ->where('user_id', $user->id)
                ->get(['id', 'payload'])
                ->filter(fn ($row) => $this->ownedByWebUser($row, $user, $strict))
                ->map(fn ($row) => (string) $row->id)
                ->values();
        } catch (Throwable $e) {
            Log::warning('session_revoke_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return collect();
        }
    }

    /** @param  array<int,string>  $ids */
    protected function deleteIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        try {
            return DB::table($this->table())->whereIn('id', $ids)->delete();
        } catch (Throwable $e) {
            Log::warning('session_revoke_failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * آیا این سطر نشست Guard «web» همین User است؟ strict=true یعنی فقط وقتی اثبات شود؛
     * strict=false یعنی payloadِ ناخوانا هم قبول است (مسیر ابطال).
     */
    protected function ownedByWebUser(object $row, User $user, bool $strict): bool
    {
        $data = $this->decode((string) ($row->payload ?? ''));

        if ($data === null) {
            return ! $strict;
        }

        return (int) ($data[Auth::guard('web')->getName()] ?? 0) === (int) $user->id;
    }

    /** @return array<string,mixed>|null */
    protected function decode(string $payload): ?array
    {
        try {
            $raw = base64_decode($payload, true);

            if ($raw === false || $raw === '') {
                return null;
            }

            if (config('session.encrypt')) {
                $raw = Crypt::decrypt($raw, false);
            }

            $data = config('session.serialization', 'php') === 'json'
                ? json_decode($raw, true)
                : unserialize($raw, ['allowed_classes' => false]);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }
}
