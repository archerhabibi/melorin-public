<?php

namespace App\Channels\Website\Http\Controllers\Account;

use App\Channels\Website\Services\WebsiteTicketFacade;
use App\Models\Ticket;
use App\Services\Core\Customer\TicketCenterException;
use App\Services\Core\Customer\TicketCenterService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ticket Center (B3.4): فهرست، ثبت، مشاهده‌ی گفتگو، پاسخ و بستن تیکت.
 * منطق در Core است (`TicketCenterService`)؛ این کنترلر فقط اعتبارسنجی ورودی و تحویل می‌دهد.
 * تیکت دیگران یا Context دیگر ⇒ 404 (نه 403). قرارداد: `CUSTOMER-TICKETS-CONTRACT.md`.
 */
class TicketsController
{
    public function __construct(protected WebsiteTicketFacade $tickets) {}

    public function index(Request $request, StoreContext $store): View
    {
        $status = $this->tickets->status($request->query('status'));

        return view('website.account.tickets.index', [
            'tickets' => $this->tickets->tickets($request->user(), $store, $status),
            'counts' => $this->tickets->counts($request->user(), $store),
            'status' => $status,
            'statusLabels' => Ticket::statusLabels(),
            'store' => $store,
            'route' => $this->route($store),
        ]);
    }

    public function create(Request $request, StoreContext $store): View
    {
        return view('website.account.tickets.create', [
            'counts' => $this->tickets->counts($request->user(), $store),
            'maxOpen' => TicketCenterService::MAX_OPEN,
            'store' => $store,
            'route' => $this->route($store),
        ]);
    }

    public function store(Request $request, StoreContext $store): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:'.TicketCenterService::SUBJECT_MAX],
            'message' => ['required', 'string', 'min:5', 'max:'.TicketCenterService::MESSAGE_MAX],
        ], [], ['subject' => 'موضوع', 'message' => 'متن پیام']);

        try {
            $ticket = $this->tickets->open($request->user(), $store, $data['subject'], $data['message']);
        } catch (TicketCenterException $e) {
            return back()->withInput()->withErrors(['ticket' => $e->getMessage()]);
        }

        return redirect($this->url($store, 'tickets.show', ['ticket' => $ticket->id]))
            ->with('status', 'تیکت شما ثبت شد. پشتیبانی به‌زودی پاسخ می‌دهد.');
    }

    public function show(Request $request, StoreContext $store, int $ticket): View
    {
        $model = $this->tickets->find($request->user(), $store, $ticket) ?? abort(404);

        return view('website.account.tickets.show', [
            'ticket' => $model,
            'messages' => $this->tickets->conversation($model),
            'maxLength' => TicketCenterService::MESSAGE_MAX,
            'store' => $store,
            'route' => $this->route($store),
        ]);
    }

    public function reply(Request $request, StoreContext $store, int $ticket): RedirectResponse
    {
        $this->tickets->find($request->user(), $store, $ticket) ?? abort(404);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:'.TicketCenterService::MESSAGE_MAX],
        ], [], ['message' => 'متن پیام']);

        try {
            $this->tickets->reply($request->user(), $store, $ticket, $data['message']);
        } catch (TicketCenterException $e) {
            return back()->withInput()->withErrors(['ticket' => $e->getMessage()]);
        }

        return redirect($this->url($store, 'tickets.show', ['ticket' => $ticket]).'#last')
            ->with('status', 'پیام شما ارسال شد.');
    }

    public function close(Request $request, StoreContext $store, int $ticket): RedirectResponse
    {
        $this->tickets->find($request->user(), $store, $ticket) ?? abort(404);

        try {
            $this->tickets->close($request->user(), $store, $ticket);
        } catch (TicketCenterException $e) {
            return back()->withErrors(['ticket' => $e->getMessage()]);
        }

        return redirect($this->url($store, 'tickets.index'))->with('status', 'تیکت بسته شد.');
    }

    protected function route(StoreContext $store): \Closure
    {
        return fn (string $name, array $params = []) => $this->url($store, $name, $params);
    }

    /** @param array<string, mixed> $params */
    protected function url(StoreContext $store, string $name, array $params = []): string
    {
        return $store->isReseller()
            ? route('website.store.'.$name, ['slug' => $store->reseller->slug, ...$params])
            : route('website.'.$name, $params);
    }
}
