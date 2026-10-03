<?php

namespace App\Services\Core\Identity;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * E1 — نرمال‌سازی Email برای احراز هویت (EMAIL-AUTH-CONTRACT.md).
 *
 * Email = trim + lowercase. تطبیق همیشه Case-insensitive است (`LOWER(email)`)، مستقل از Collation
 * دیتابیس؛ تا SQLite/Postgres و MySQL یک رفتار داشته باشند و ردیف‌های قدیمیِ Mixed-case
 * (مثل `Ali@Example.com`) هم قابل ورود/بازیابی بمانند. Soft-deleted هرگز Resolve نمی‌شود.
 */
final class EmailIdentity
{
    public static function normalize(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }

    /** User فعالِ-نه-حذف‌شده با این Email (Case-insensitive) یا null. */
    public static function findUser(?string $email): ?User
    {
        $email = self::normalize($email);

        return $email === ''
            ? null
            : User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    /**
     * Email آن‌طور که در DB ذخیره شده (برای Auth::attempt / Password Broker که تطبیق دقیق دارند)؛
     * اگر User نبود، همان مقدار نرمال‌شده.
     */
    public static function canonical(?string $email): string
    {
        return self::findUser($email)?->email ?? self::normalize($email);
    }

    /** آیا Email (Case-insensitive) برای هر User — حتی Soft-deleted — گرفته شده؟ (Unique Index شامل آن‌ها می‌شود) */
    public static function isTaken(?string $email): bool
    {
        $email = self::normalize($email);

        return $email !== '' && User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists();
    }
}
