<?php

namespace Tests\Feature\TelegramBot;

use App\Channels\TelegramBot\Handlers\MiscHandler;
use App\Channels\TelegramBot\Support\ConversationState;
use App\Events\TicketAnswered;
use App\Events\TicketUserReplied;
use App\Listeners\NotifyAdminsOfTicketReply;
use App\Listeners\NotifyUserOfTicketAnswer;
use App\Models\Admin;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * قبل از این نسخه، مکالمه‌ی تیکت فقط یک‌طرفه بود: کاربر یک‌بار پیام
 * می‌فرستاد، ادمین از پنل جواب می‌داد و کاربر آن را در تلگرام می‌دید —
 * ولی کاربر هیچ راهی برای پاسخ دادن به همان تیکت نداشت (پیام بعدی‌اش،
 * اگر می‌فرستاد، به هیچ‌جا وصل نمی‌شد). این تست‌ها مسیر برعکس (کاربر →
 * ادمین، روی یک تیکتِ موجود) را پوشش می‌دهند.
 */
class TicketConversationFlowTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    #[Test]
    public function support_start_continues_an_existing_open_ticket_instead_of_creating_a_new_one(): void
    {
        $user = User::factory()->create(['telegram_id' => 123]);
        $ticket = Ticket::factory()->for($user)->create(['type' => 'support', 'status' => 'answered']);
        $ticket->messages()->create(['sender_type' => 'user', 'sender_id' => $user->id, 'message' => 'سلام، مشکل من این است.']);
        $ticket->messages()->create(['sender_type' => 'admin', 'sender_id' => 1, 'message' => 'لطفاً کانفیگ را دوباره امتحان کنید.']);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 123
                && str_contains($params['text'], "#{$ticket->id}")
                && str_contains($params['text'], 'کانفیگ را دوباره امتحان کنید')
                && str_contains($params['text'], 'پیام خود را بفرستید'))
            ->andReturn($this->fakeMessage());

        $this->app->instance(Api::class, $telegram);

        app(MiscHandler::class)->supportStart(123, $user);

        $state = app(ConversationState::class)->find(123);
        $this->assertSame(ConversationState::TICKET_AWAITING_REPLY, $state->step);
        $this->assertSame($ticket->id, $state->payload['ticket_id']);

        $this->assertSame(1, Ticket::where('user_id', $user->id)->count());
    }

    #[Test]
    public function support_start_starts_a_fresh_ticket_when_the_previous_one_is_closed(): void
    {
        $user = User::factory()->create(['telegram_id' => 123]);
        Ticket::factory()->for($user)->create(['type' => 'support', 'status' => 'closed']);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => str_contains($params['text'], 'پیام خود را برای پشتیبانی بنویسید'))
            ->andReturn($this->fakeMessage());

        $this->app->instance(Api::class, $telegram);

        app(MiscHandler::class)->supportStart(123, $user);

        $state = app(ConversationState::class)->find(123);
        $this->assertSame(ConversationState::SUPPORT_AWAITING_MESSAGE, $state->step);
    }

    #[Test]
    public function ticket_reply_submit_appends_the_message_reopens_the_ticket_and_dispatches_event(): void
    {
        Event::fake([TicketUserReplied::class]);

        $user = User::factory()->create(['telegram_id' => 123]);
        $ticket = Ticket::factory()->for($user)->create(['status' => 'answered']);
        app(ConversationState::class)->set(123, ConversationState::TICKET_AWAITING_REPLY, ['ticket_id' => $ticket->id], $user);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 123 && str_contains($params['text'], "به تیکت #{$ticket->id} اضافه شد"))
            ->andReturn($this->fakeMessage());

        $this->app->instance(Api::class, $telegram);

        app(MiscHandler::class)->ticketReplySubmit(123, $user, 'هنوز حل نشده، ممنون می‌شوم پیگیری کنید.');

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => 'هنوز حل نشده، ممنون می‌شوم پیگیری کنید.',
        ]);

        $this->assertSame('open', $ticket->fresh()->status);

        $state = app(ConversationState::class)->find(123);
        $this->assertSame(ConversationState::IDLE, $state->step);

        Event::assertDispatched(TicketUserReplied::class, fn ($event) => $event->message->ticket_id === $ticket->id);
    }

    #[Test]
    public function ticket_reply_submit_refuses_a_ticket_that_does_not_belong_to_the_user(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create(['telegram_id' => 999]);
        $ticket = Ticket::factory()->for($owner)->create();

        app(ConversationState::class)->set(999, ConversationState::TICKET_AWAITING_REPLY, ['ticket_id' => $ticket->id], $attacker);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 999 && str_contains($params['text'], 'در دسترس نیست'))
            ->andReturn($this->fakeMessage());

        $this->app->instance(Api::class, $telegram);

        app(MiscHandler::class)->ticketReplySubmit(999, $attacker, 'تلاش برای دسترسی به تیکت شخص دیگر.');

        $this->assertDatabaseMissing('ticket_messages', [
            'ticket_id' => $ticket->id,
            'sender_id' => $attacker->id,
        ]);
    }

    #[Test]
    public function admin_answer_notification_now_tells_the_user_how_to_reply(): void
    {
        $user = User::factory()->create(['telegram_id' => 987654321]);
        $ticket = Ticket::factory()->for($user)->create();
        $admin = Admin::factory()->create();

        $message = $ticket->messages()->create([
            'sender_type' => 'admin',
            'sender_id' => $admin->id,
            'message' => 'لطفاً دوباره امتحان کنید.',
        ]);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => str_contains($params['text'], 'لطفاً دوباره امتحان کنید')
                && str_contains($params['text'], 'برای پاسخ'))
            ->andReturn($this->fakeMessage());

        (new NotifyUserOfTicketAnswer($telegram))->handle(new TicketAnswered($message));
    }

    #[Test]
    public function user_reply_notification_is_sent_to_every_configured_admin_id(): void
    {
        config(['telegram.admin_ids' => ['111', '222']]);

        $user = User::factory()->create(['full_name' => 'کاربر تستی']);
        $ticket = Ticket::factory()->for($user)->create();
        $message = $ticket->messages()->create([
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => 'پیام بعدی من در همین تیکت.',
        ]);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === '111' && str_contains($params['text'], "#{$ticket->id}"))
            ->andReturn($this->fakeMessage());
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === '222')
            ->andReturn($this->fakeMessage());

        (new NotifyAdminsOfTicketReply($telegram))->handle(new TicketUserReplied($message));
    }

    #[Test]
    public function no_reply_notification_is_attempted_when_no_admin_ids_are_configured(): void
    {
        config(['telegram.admin_ids' => []]);

        $ticket = Ticket::factory()->create();
        $message = $ticket->messages()->create([
            'sender_type' => 'user',
            'sender_id' => $ticket->user_id,
            'message' => 'پیام بدون ادمین تنظیم‌شده.',
        ]);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldNotReceive('sendMessage');

        (new NotifyAdminsOfTicketReply($telegram))->handle(new TicketUserReplied($message));
    }
}
