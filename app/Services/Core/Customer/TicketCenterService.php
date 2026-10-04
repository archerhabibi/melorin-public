<?php

namespace App\Services\Core\Customer;

use App\Events\TicketCreated;
use App\Events\TicketUserReplied;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Core\Store\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * B3.4 — Ticket Center: فهرست، ثبت، پاسخ و بستن تیکت توسط مشتری.
 *
 * قواعد:
 *  - **Context-isolated:** مالکیت = `user_id + reseller_id` (null = فروشگاه اصلی؛ همان کلید Payment/Order).
 *    تیکت دیگران یا Context دیگر `null` برمی‌گردد (Channel ⇒ 404، نه 403: وجودش لو نرود).
 *  - **منطق اینجا (Core):** Website و Bot (T3) از یک منبع می‌خوانند؛ Controller شرطی ندارد.
 *  - **وضعیت‌ها:** open ←(ادمین پاسخ)→ answered ←(مشتری پاسخ)→ open؛ `closed` نهایی است — مشتری
 *    تیکت بسته را باز نمی‌کند (تیکت جدید می‌سازد). ادمین پنل هم همین را enforce می‌کند (`isOpen`).
 *  - **ضد سوءاستفاده:** سقف تیکت بازِ هم‌زمان (`MAX_OPEN`) و جلوگیری از ثبت دوباره‌ی همان پیامِ دوبار-کلیک‌شده.
 *  - رویدادها همان رویدادهای موجود ربات‌اند (`TicketCreated`/`TicketUserReplied`) ⇒ اطلاع به ادمین بدون تغییر.
 */
class TicketCenterService
{
    public const PER_PAGE = 15;

    /** تعداد تیکت بازِ هم‌زمان هر مشتری در هر فروشگاه (جلوگیری از انبوه‌سازی) */
    public const MAX_OPEN = 5;

    public const SUBJECT_MAX = 100;

    public const MESSAGE_MAX = 4000;

    /** همان متن تکراری در همین بازه (ثانیه) = دوبار-کلیک، نه پیام جدید */
    public const DUPLICATE_WINDOW_SECONDS = 30;

    public function counts(User $user, StoreContext $store): TicketCounts
    {
        $rows = $this->owned($user, $store)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return new TicketCounts(
            open: (int) ($rows[Ticket::STATUS_OPEN] ?? 0),
            answered: (int) ($rows[Ticket::STATUS_ANSWERED] ?? 0),
            closed: (int) ($rows[Ticket::STATUS_CLOSED] ?? 0),
        );
    }

    /** تعداد تیکت‌های پاسخ‌داده‌شده که مشتری هنوز نبسته/پاسخ نداده (مرجع واحد اعلان داشبورد و نشان منو) */
    public function answeredCount(User $user, StoreContext $store): int
    {
        return $this->owned($user, $store)->where('status', Ticket::STATUS_ANSWERED)->count();
    }

    /**
     * @param  string|null  $status  فقط open|answered|closed؛ هر مقدار دیگر = همه
     * @return LengthAwarePaginator<Ticket>
     */
    public function tickets(User $user, StoreContext $store, ?string $status = null, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->owned($user, $store)
            ->when(in_array($status, array_keys(Ticket::statusLabels()), true), fn (Builder $q) => $q->where('status', $status))
            ->withCount('messages')
            ->with('latestMessage')
            // «پاسخ داده‌شده» بالاتر از بقیه (نیاز به توجه مشتری)، بعد جدیدترین فعالیت
            ->orderByRaw("CASE status WHEN 'answered' THEN 0 WHEN 'open' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(User $user, StoreContext $store, int $ticketId): ?Ticket
    {
        return $this->owned($user, $store)->whereKey($ticketId)->first();
    }

    /** @return Collection<int, TicketMessage> قدیمی‌ترین اول */
    public function conversation(Ticket $ticket)
    {
        return $ticket->messages()->orderBy('id')->get();
    }

    /**
     * ثبت تیکت جدید پشتیبانی.
     *
     * @throws TicketCenterException
     */
    public function open(User $user, StoreContext $store, string $subject, string $message): Ticket
    {
        $subject = $this->clean($subject, self::SUBJECT_MAX);
        $message = $this->clean($message, self::MESSAGE_MAX);

        if ($subject === '' || $message === '') {
            throw new TicketCenterException('موضوع و متن پیام الزامی است.');
        }

        $ticket = DB::transaction(function () use ($user, $store, $subject, $message) {
            // قفل ردیف کاربر ⇒ دو درخواست هم‌زمان نمی‌توانند هر دو از سقف رد شوند.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $open = $this->owned($user, $store)->where('status', '!=', Ticket::STATUS_CLOSED)->count();

            if ($open >= self::MAX_OPEN) {
                throw new TicketCenterException('شما '.self::MAX_OPEN.' تیکت باز دارید. ابتدا یکی را ببندید یا منتظر پاسخ بمانید.');
            }

            // دوبار-کلیک: همان موضوع+متن در چند ثانیه‌ی اخیر ⇒ همان تیکت، نه تکراری.
            $duplicate = $this->owned($user, $store)
                ->where('type', 'support')
                ->where('subject', $subject)
                ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
                ->whereHas('messages', fn ($q) => $q->where('message', $message))
                ->latest('id')
                ->first();

            if ($duplicate) {
                return [$duplicate, false];
            }

            $ticket = Ticket::create([
                'user_id' => $user->id,
                'reseller_id' => $store->resellerId(),
                'type' => 'support',
                'subject' => $subject,
                'status' => Ticket::STATUS_OPEN,
                'priority' => 'normal',
            ]);

            $ticket->messages()->create([
                'sender_type' => 'user',
                'sender_id' => $user->id,
                'message' => $message,
            ]);

            return [$ticket, true];
        });

        [$ticket, $created] = $ticket;

        if ($created) {
            $this->notify(fn () => TicketCreated::dispatch($ticket->fresh()), $ticket->id);
        }

        return $ticket;
    }

    /**
     * پاسخ مشتری به تیکت خودش. تیکت `answered` دوباره `open` می‌شود (منتظر ادمین).
     *
     * @throws TicketCenterException تیکت پیدا نشد/بسته است/متن خالی
     */
    public function reply(User $user, StoreContext $store, int $ticketId, string $message): TicketMessage
    {
        $message = $this->clean($message, self::MESSAGE_MAX);

        if ($message === '') {
            throw new TicketCenterException('متن پیام نمی‌تواند خالی باشد.');
        }

        [$reply, $created] = DB::transaction(function () use ($user, $store, $ticketId, $message) {
            $ticket = $this->owned($user, $store)->whereKey($ticketId)->lockForUpdate()->first();

            if (! $ticket) {
                throw new TicketCenterException('این تیکت در دسترس نیست.');
            }

            if (! $ticket->isOpen()) {
                throw new TicketCenterException('این تیکت بسته شده است. برای ادامه، تیکت جدید ثبت کنید.');
            }

            $last = $ticket->messages()->where('sender_type', 'user')->latest('id')->first();

            if ($last
                && $last->message === $message
                && $last->created_at->gte(now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
                && $ticket->status === Ticket::STATUS_OPEN) {
                return [$last, false];
            }

            $reply = $ticket->messages()->create([
                'sender_type' => 'user',
                'sender_id' => $user->id,
                'message' => $message,
            ]);

            $ticket->update(['status' => Ticket::STATUS_OPEN]);
            $ticket->touch();

            return [$reply, true];
        });

        if ($created) {
            $this->notify(fn () => TicketUserReplied::dispatch($reply->fresh()), $reply->ticket_id);
        }

        return $reply;
    }

    /**
     * بستن تیکت توسط مشتری (idempotent). تیکت دیگران ⇒ خطا.
     *
     * @throws TicketCenterException
     */
    public function close(User $user, StoreContext $store, int $ticketId): Ticket
    {
        $ticket = $this->find($user, $store, $ticketId);

        if (! $ticket) {
            throw new TicketCenterException('این تیکت در دسترس نیست.');
        }

        if ($ticket->isOpen()) {
            $ticket->update(['status' => Ticket::STATUS_CLOSED]);
        }

        return $ticket;
    }

    /**
     * اطلاع به ادمین‌ها best-effort است: تیکت از قبل ذخیره شده و خرابیِ Listener (ربات/شبکه/تنظیمات)
     * نباید به مشتری 500 بدهد یا او را به ثبت دوباره (و تیکت تکراری) وادار کند.
     */
    protected function notify(\Closure $dispatch, int $ticketId): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            Log::warning('اطلاع‌رسانی تیکت مشتری به ادمین ناموفق بود؛ تیکت ثبت شده است.', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** تیکت‌های شخصیِ این کاربر در این Context */
    protected function owned(User $user, StoreContext $store): Builder
    {
        return Ticket::query()
            ->where('user_id', $user->id)
            ->where('reseller_id', $store->resellerId());
    }

    /** حذف کاراکترهای کنترلی (به‌جز خط جدید/Tab) و برش فاصله‌ها؛ HTML در View escape می‌شود. */
    protected function clean(string $text, int $max): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return mb_substr(trim($text), 0, $max);
    }
}
