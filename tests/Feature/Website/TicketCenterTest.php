<?php

namespace Tests\Feature\Website;

use App\Channels\TelegramBot\Handlers\MiscHandler;
use App\Events\TicketCreated;
use App\Events\TicketUserReplied;
use App\Models\Admin;
use App\Models\CustomerAccount;
use App\Models\Reseller;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Core\Customer\CustomerDashboardService;
use App\Services\Core\Customer\TicketCenterException;
use App\Services\Core\Customer\TicketCenterService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use Tests\TestCase;

/**
 * B3.4 — Ticket Center. Contract: docs/canonical/CUSTOMER-TICKETS-CONTRACT.md
 */
class TicketCenterTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(User $user, array $attrs = [], array $messages = ['سلام، مشکل دارم']): Ticket
    {
        $ticket = Ticket::factory()->create(array_merge(['user_id' => $user->id], $attrs));

        foreach ($messages as $i => $text) {
            $ticket->messages()->create([
                'sender_type' => is_array($text) ? $text[0] : 'user',
                'sender_id' => $user->id,
                'message' => is_array($text) ? $text[1] : $text,
            ]);
        }

        return $ticket;
    }

    private function admin(Ticket $ticket, string $text): void
    {
        $admin = Admin::factory()->create(['name' => 'علی مدیر واقعی']);
        $ticket->messages()->create(['sender_type' => 'admin', 'sender_id' => $admin->id, 'message' => $text]);
        $ticket->update(['status' => Ticket::STATUS_ANSWERED]);
    }

    // --- دسترسی ---

    #[Test]
    public function every_ticket_route_requires_login(): void
    {
        $this->get(route('website.tickets.index'))->assertRedirect(route('website.login'));
        $this->get(route('website.tickets.create'))->assertRedirect(route('website.login'));
        $this->post(route('website.tickets.store'), [])->assertRedirect(route('website.login'));
        $this->get(route('website.tickets.show', 1))->assertRedirect(route('website.login'));
    }

    #[Test]
    public function the_support_link_is_in_the_account_navigation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('website.dashboard'))
            ->assertOk()->assertSee(route('website.tickets.index'), false);
    }

    // --- فهرست ---

    #[Test]
    public function the_list_shows_own_tickets_with_status_and_counts_and_answered_first(): void
    {
        $user = User::factory()->create();
        $waiting = $this->ticket($user, ['subject' => 'منتظر پاسخ', 'status' => 'open']);
        $answered = $this->ticket($user, ['subject' => 'پاسخ گرفته']);
        $this->admin($answered, 'راهنمایی شما');
        $this->ticket($user, ['subject' => 'بسته‌شده', 'status' => 'closed']);

        $html = $this->actingAs($user)->get(route('website.tickets.index'))
            ->assertOk()
            ->assertSee('منتظر پاسخ')->assertSee('پاسخ گرفته')->assertSee('بسته‌شده')
            ->assertSee('یک تیکت شما پاسخ جدید دارد')
            ->getContent();

        $this->assertLessThan(strpos($html, 'منتظر پاسخ'), strpos($html, 'پاسخ گرفته'), 'تیکت پاسخ‌داده‌شده باید بالاتر بیاید');
        $this->assertSame(3, app(TicketCenterService::class)->counts($user, StoreContext::main())->total());
    }

    #[Test]
    public function the_status_filter_works_and_garbage_is_ignored(): void
    {
        $user = User::factory()->create();
        $this->ticket($user, ['subject' => 'تیکت باز اول', 'status' => 'open']);
        $this->ticket($user, ['subject' => 'تیکت بسته اول', 'status' => 'closed']);

        $this->actingAs($user)->get(route('website.tickets.index', ['status' => 'closed']))
            ->assertOk()->assertSee('تیکت بسته اول')->assertDontSee('تیکت باز اول');

        $this->actingAs($user)->get(route('website.tickets.index', ['status' => 'hack']))
            ->assertOk()->assertSee('تیکت بسته اول')->assertSee('تیکت باز اول');

        $this->actingAs($user)->get(route('website.tickets.index', ['status' => ['x']]))->assertOk();
    }

    #[Test]
    public function the_list_has_an_empty_state(): void
    {
        $this->actingAs(User::factory()->create())->get(route('website.tickets.index'))
            ->assertOk()->assertSee('هنوز تیکتی ثبت نکرده‌اید');
    }

    // --- ثبت ---

    #[Test]
    public function a_customer_can_open_a_ticket_and_admins_are_notified(): void
    {
        Event::fake([TicketCreated::class]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('website.tickets.store'), [
            'subject' => 'مشکل اتصال',
            'message' => "سرویس من وصل نمی‌شود\nخط دوم",
        ]);

        $ticket = Ticket::query()->firstOrFail();
        $response->assertRedirect(route('website.tickets.show', $ticket->id));

        $this->assertSame($user->id, $ticket->user_id);
        $this->assertNull($ticket->reseller_id);
        $this->assertSame('support', $ticket->type);
        $this->assertSame('open', $ticket->status);
        $this->assertSame("سرویس من وصل نمی‌شود\nخط دوم", $ticket->messages()->first()->message);
        Event::assertDispatched(TicketCreated::class, 1);

        $this->actingAs($user)->get(route('website.tickets.show', $ticket->id))
            ->assertOk()->assertSee('مشکل اتصال')->assertSee('سرویس من وصل نمی‌شود');
    }

    #[Test]
    public function opening_validates_the_input(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('website.tickets.store'), ['subject' => 'ab', 'message' => 'x'])
            ->assertSessionHasErrors(['subject', 'message']);

        $this->actingAs($user)->post(route('website.tickets.store'), [
            'subject' => str_repeat('م', 101), 'message' => str_repeat('م', 4001),
        ])->assertSessionHasErrors(['subject', 'message']);

        $this->actingAs($user)->post(route('website.tickets.store'), ['subject' => ['a'], 'message' => ['b']])
            ->assertSessionHasErrors(['subject', 'message']);

        $this->assertSame(0, Ticket::query()->count());
    }

    #[Test]
    public function html_in_a_ticket_is_escaped_never_rendered(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, ['subject' => '<script>alert(1)</script>سلام'], ['<img src=x onerror=alert(2)> متن']);

        $html = $this->actingAs($user)->get(route('website.tickets.show', $ticket->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);

        $this->actingAs($user)->get(route('website.tickets.index'))->assertDontSee('<script>alert(1)</script>', false);
    }

    #[Test]
    public function the_open_ticket_limit_is_enforced_per_store_and_closing_frees_a_slot(): void
    {
        $user = User::factory()->create();
        $service = app(TicketCenterService::class);

        for ($i = 1; $i <= TicketCenterService::MAX_OPEN; $i++) {
            $service->open($user, StoreContext::main(), "موضوع {$i}", "متن شماره {$i}");
        }

        try {
            $service->open($user, StoreContext::main(), 'موضوع اضافه', 'متن اضافه');
            $this->fail('باید رد می‌شد');
        } catch (TicketCenterException $e) {
            $this->assertStringContainsString('تیکت باز', $e->getMessage());
        }

        // فروشگاه دیگر سقف جدا دارد
        $reseller = Reseller::factory()->create();
        $service->open($user, StoreContext::reseller($reseller), 'در فروشگاه', 'متن فروشگاه');

        // بستن یک تیکت جا باز می‌کند
        $service->close($user, StoreContext::main(), Ticket::query()->where('reseller_id', null)->firstOrFail()->id);
        $service->open($user, StoreContext::main(), 'موضوع جدید', 'متن جدید');
        $this->assertSame(TicketCenterService::MAX_OPEN + 1, Ticket::query()->where('status', '!=', 'closed')->count());

        $this->actingAs($user)->post(route('website.tickets.store'), ['subject' => 'یکی دیگر', 'message' => 'متن دیگر'])
            ->assertSessionHasErrors('ticket');
    }

    #[Test]
    public function a_double_submitted_ticket_is_created_once(): void
    {
        Event::fake([TicketCreated::class]);
        $user = User::factory()->create();
        $service = app(TicketCenterService::class);

        $a = $service->open($user, StoreContext::main(), 'دوبار کلیک', 'همین متن');
        $b = $service->open($user, StoreContext::main(), 'دوبار کلیک', 'همین متن');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Ticket::query()->count());
        Event::assertDispatched(TicketCreated::class, 1);
    }

    #[Test]
    public function control_characters_are_stripped_and_text_is_trimmed(): void
    {
        $user = User::factory()->create();

        $ticket = app(TicketCenterService::class)->open($user, StoreContext::main(), "  موضوع\x00\x07  ", "\r\n متن\x1F اصلی \r\n");

        $this->assertSame('موضوع', $ticket->subject);
        $this->assertSame('متن اصلی', $ticket->messages()->first()->message);
    }

    #[Test]
    public function a_failing_admin_notification_never_breaks_ticket_creation_or_reply(): void
    {
        $user = User::factory()->create();
        Log::spy();

        // Listenerها در تست شکست می‌خورند (Telegram token ندارد) — مشتری باید همچنان موفق شود.
        Event::listen(TicketCreated::class, fn () => throw new \RuntimeException('telegram down'));
        Event::listen(TicketUserReplied::class, fn () => throw new \RuntimeException('telegram down'));

        $this->actingAs($user)->post(route('website.tickets.store'), ['subject' => 'با خطای اطلاع', 'message' => 'متن آزمایشی'])
            ->assertRedirect();
        $ticket = Ticket::query()->firstOrFail();

        $this->actingAs($user)->post(route('website.tickets.reply', $ticket->id), ['message' => 'پاسخ آزمایشی'])->assertRedirect();

        $this->assertSame(2, $ticket->messages()->count());
        Log::shouldHaveReceived('warning')->twice();
    }

    // --- گفتگو و پاسخ ---

    #[Test]
    public function the_conversation_never_shows_the_admins_real_name(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user);
        $this->admin($ticket, 'پاسخ تیم پشتیبانی');

        $this->actingAs($user)->get(route('website.tickets.show', $ticket->id))
            ->assertOk()
            ->assertSee('پاسخ تیم پشتیبانی')
            ->assertSee('پشتیبانی')
            ->assertSee('شما')
            ->assertDontSee('علی مدیر واقعی');
    }

    #[Test]
    public function replying_reopens_an_answered_ticket_and_notifies_admins(): void
    {
        Event::fake([TicketUserReplied::class]);
        $user = User::factory()->create();
        $ticket = $this->ticket($user);
        $this->admin($ticket, 'لطفاً توضیح بدهید');

        $this->actingAs($user)->post(route('website.tickets.reply', $ticket->id), ['message' => 'این هم توضیحات من'])
            ->assertRedirect();

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertSame(3, $ticket->messages()->count());
        $this->assertSame('user', $ticket->messages()->latest('id')->first()->sender_type);
        Event::assertDispatched(TicketUserReplied::class, 1);
    }

    #[Test]
    public function a_double_submitted_reply_is_stored_once(): void
    {
        Event::fake([TicketUserReplied::class]);
        $user = User::factory()->create();
        $ticket = $this->ticket($user);
        $this->admin($ticket, 'پاسخ');
        $service = app(TicketCenterService::class);

        $service->reply($user, StoreContext::main(), $ticket->id, 'پاسخ من');
        $service->reply($user, StoreContext::main(), $ticket->id, 'پاسخ من');

        $this->assertSame(1, $ticket->messages()->where('message', 'پاسخ من')->count());
        Event::assertDispatched(TicketUserReplied::class, 1);
    }

    #[Test]
    public function a_closed_ticket_rejects_replies_and_the_form_is_hidden(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, ['status' => 'closed']);

        $this->actingAs($user)->post(route('website.tickets.reply', $ticket->id), ['message' => 'هنوز هستم'])
            ->assertSessionHasErrors('ticket');
        $this->assertSame(1, $ticket->messages()->count());

        $this->actingAs($user)->get(route('website.tickets.show', $ticket->id))
            ->assertOk()->assertSee('این تیکت بسته شده است')->assertDontSee('ارسال پاسخ');
    }

    #[Test]
    public function the_customer_can_close_a_ticket_and_closing_twice_is_harmless(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user);

        $this->actingAs($user)->post(route('website.tickets.close', $ticket->id))->assertRedirect(route('website.tickets.index'));
        $this->assertSame('closed', $ticket->fresh()->status);

        $this->actingAs($user)->post(route('website.tickets.close', $ticket->id))->assertRedirect(route('website.tickets.index'));
        $this->assertSame('closed', $ticket->fresh()->status);
    }

    // --- Isolation ---

    #[Test]
    public function other_peoples_tickets_are_404_for_every_action(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $ticket = $this->ticket($owner, ['subject' => 'خصوصی مالک']);

        $this->actingAs($stranger)->get(route('website.tickets.show', $ticket->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('website.tickets.reply', $ticket->id), ['message' => 'نفوذ'])->assertNotFound();
        $this->actingAs($stranger)->post(route('website.tickets.close', $ticket->id))->assertNotFound();
        $this->actingAs($stranger)->get(route('website.tickets.index'))->assertOk()->assertDontSee('خصوصی مالک');

        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame('open', $ticket->fresh()->status);
    }

    #[Test]
    public function tickets_never_leak_between_the_main_store_and_a_reseller_store(): void
    {
        $reseller = Reseller::factory()->create();
        $user = User::factory()->create();
        $main = $this->ticket($user, ['subject' => 'تیکت فروشگاه اصلی']);
        $shop = $this->ticket($user, ['subject' => 'تیکت فروشگاه نماینده', 'reseller_id' => $reseller->id]);

        $this->actingAs($user)->get(route('website.tickets.index'))
            ->assertOk()->assertSee('تیکت فروشگاه اصلی')->assertDontSee('تیکت فروشگاه نماینده');
        $this->actingAs($user)->get(route('website.store.tickets.index', $reseller->slug))
            ->assertOk()->assertSee('تیکت فروشگاه نماینده')->assertDontSee('تیکت فروشگاه اصلی');

        // دسترسی مستقیم از Context اشتباه ⇒ 404
        $this->actingAs($user)->get(route('website.tickets.show', $shop->id))->assertNotFound();
        $this->actingAs($user)->get(route('website.store.tickets.show', [$reseller->slug, $main->id]))->assertNotFound();
        $this->actingAs($user)->post(route('website.tickets.reply', $shop->id), ['message' => 'متن آزمایشی'])->assertNotFound();
    }

    #[Test]
    public function a_ticket_opened_in_a_reseller_store_is_tagged_with_it_and_links_stay_in_the_store(): void
    {
        $reseller = Reseller::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('website.store.tickets.store', $reseller->slug), [
            'subject' => 'سؤال از فروشگاه', 'message' => 'متن سؤال من',
        ]);

        $ticket = Ticket::query()->firstOrFail();
        $this->assertSame($reseller->id, $ticket->reseller_id);
        $response->assertRedirect(route('website.store.tickets.show', [$reseller->slug, $ticket->id]));

        $this->actingAs($user)->get(route('website.store.tickets.show', [$reseller->slug, $ticket->id]))
            ->assertOk()
            ->assertSee(route('website.store.tickets.reply', [$reseller->slug, $ticket->id]), false);
    }

    #[Test]
    public function bot_tickets_appear_in_the_main_store_and_website_reseller_tickets_stay_out_of_the_bot(): void
    {
        $reseller = Reseller::factory()->create();
        $user = User::factory()->create(['telegram_id' => 555001]);

        // تیکتِ ثبت‌شده از ربات = فروشگاه اصلی (reseller_id = null)
        $fromBot = $this->ticket($user, ['subject' => 'از ربات']);
        $this->actingAs($user)->get(route('website.tickets.index'))->assertSee('از ربات');

        // تیکت باز در فروشگاه نماینده نباید ربات را به «ادامه‌ی همان تیکت» ببرد
        $fromBot->update(['status' => 'closed']);
        $this->ticket($user, ['subject' => 'فقط نماینده', 'reseller_id' => $reseller->id]);

        $mock = \Mockery::mock(Api::class);
        $mock->shouldReceive('sendMessage')->andReturnUsing(function (array $p) {
            $this->assertStringNotContainsString('شما یک تیکت باز دارید', $p['text']);

            return new Message([]);
        });
        $this->app->instance(Api::class, $mock);

        app(MiscHandler::class)->supportStart(777, $user);
    }

    // --- داشبورد ---

    #[Test]
    public function the_dashboard_shows_a_notice_for_an_answered_ticket_and_it_disappears_after_replying(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user);
        $store = StoreContext::main();
        $snapshot = fn () => app(CustomerDashboardService::class)->snapshot($user, $store, app(IdentityService::class)->findCustomerAccount($user, $store));

        $this->assertNull($snapshot()->notices->firstWhere('key', 'ticket.answered'));

        $this->admin($ticket, 'جواب');

        $notice = $snapshot()->notices->firstWhere('key', 'ticket.answered');
        $this->assertNotNull($notice);
        $this->assertSame($ticket->id, $notice->targetId);

        // لینک اعلان داشبورد مستقیم به همان تیکت است
        $this->actingAs($user)->get(route('website.dashboard'))
            ->assertOk()->assertSee(route('website.tickets.show', $ticket->id), false);

        app(TicketCenterService::class)->reply($user, $store, $ticket->id, 'ممنون، حل شد');
        $this->assertNull($snapshot()->notices->firstWhere('key', 'ticket.answered'));
    }

    #[Test]
    public function several_answered_tickets_link_the_notice_to_the_list(): void
    {
        $user = User::factory()->create();
        $this->admin($this->ticket($user), 'جواب یک');
        $this->admin($this->ticket($user), 'جواب دو');

        $this->actingAs($user)->get(route('website.dashboard'))
            ->assertOk()->assertSee('2 تیکت شما پاسخ جدید دارد')->assertSee(route('website.tickets.index'), false);
    }

    #[Test]
    public function the_ticket_pages_never_write_anything_when_just_viewed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('website.tickets.index'))->assertOk();
        $this->actingAs($user)->get(route('website.tickets.create'))->assertOk();

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, CustomerAccount::query()->count());
    }
}
