<?php

namespace App\Channels\Website\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Core\Customer\TicketCenterService;
use App\Services\Core\Customer\TicketCounts;
use App\Services\Core\Store\StoreContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Adapter نازک روی TicketCenterService (B3.4). هیچ منطقی اینجا نیست؛ فقط صدا زدن Core.
 */
class WebsiteTicketFacade
{
    public function __construct(protected TicketCenterService $center) {}

    public function counts(User $user, StoreContext $store): TicketCounts
    {
        return $this->center->counts($user, $store);
    }

    /** @return LengthAwarePaginator<Ticket> */
    public function tickets(User $user, StoreContext $store, ?string $status): LengthAwarePaginator
    {
        return $this->center->tickets($user, $store, $status)->appends(array_filter(['status' => $this->status($status)]));
    }

    public function find(User $user, StoreContext $store, int $id): ?Ticket
    {
        return $this->center->find($user, $store, $id);
    }

    /** @return Collection<int, TicketMessage> */
    public function conversation(Ticket $ticket): Collection
    {
        return $this->center->conversation($ticket);
    }

    public function open(User $user, StoreContext $store, string $subject, string $message): Ticket
    {
        return $this->center->open($user, $store, $subject, $message);
    }

    public function reply(User $user, StoreContext $store, int $id, string $message): TicketMessage
    {
        return $this->center->reply($user, $store, $id, $message);
    }

    public function close(User $user, StoreContext $store, int $id): Ticket
    {
        return $this->center->close($user, $store, $id);
    }

    /** فقط open|answered|closed (هر مقدار دیگر/آرایه ⇒ null = همه) */
    public function status(mixed $value): ?string
    {
        return is_string($value) && array_key_exists($value, Ticket::statusLabels()) ? $value : null;
    }
}
