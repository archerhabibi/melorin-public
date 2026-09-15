<?php

namespace Tests\Feature\TelegramBot;

use App\Channels\TelegramBot\Handlers\MiscHandler;
use App\Channels\TelegramBot\Support\ConversationState;
use App\Events\TicketAnswered;
use App\Events\TicketCreated;
use App\Listeners\NotifyAdminsOfNewTicket;
use App\Listeners\NotifyUserOfTicketAnswer;
use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * قبل از این نسخه (۲.۴.۵) تیکت فقط در دیتابیس ثبت می‌شد و هیچ‌کجا خبری
 * از آن نمی‌رفت — این تست‌ها دقیقاً همان رگرسیون را پوشش می‌دهند: هم
 * ثبت تیکت باید رویداد را منتشر کند، هم هر دو Listener باید واقعاً به
 * تلگرام پیام بفرستند.
 */
class SupportTicketFlowTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    #[Test]
    public function submitting_a_support_message_creates_a_ticket_and_dispatches_ticket_created(): void
    {
        Event::fake([TicketCreated::class]);

        $this->fakeTelegram();

        $user = User::factory()->create();

        /** @var MiscHandler $handler */
        $handler = $this->app->make(MiscHandler::class);
        $handler->supportSubmit(111222333, $user, 'ربات من وصل نمی‌شود، لطفاً کمک کنید.');

        $this->assertDatabaseHas('tickets', [
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('ticket_messages', [
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => 'ربات من وصل نمی‌شود، لطفاً کمک کنید.',
        ]);

        Event::assertDispatched(TicketCreated::class, fn ($event) => $event->ticket->user_id === $user->id);
    }

    #[Test]
    public function support_state_is_reset_after_submitting_a_ticket(): void
    {
        $this->fakeTelegram();

        $user = User::factory()->create();
        $chatId = 555666777;

        $state = $this->app->make(ConversationState::class);
        $state->set($chatId, ConversationState::SUPPORT_AWAITING_MESSAGE, [], $user);

        $this->app->make(MiscHandler::class)->supportSubmit($chatId, $user, 'مشکل من حل نشد.');

        $this->assertSame(ConversationState::IDLE, $state->find($chatId)->step);
    }

    #[Test]
    public function new_ticket_notification_is_sent_to_every_configured_admin_id(): void
    {
        config()->set('telegram.admin_ids', ['111', '222']);

        $user = User::factory()->create(['full_name' => 'کاربر تستی']);
        $ticket = Ticket::factory()->for($user)->create();
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => 'متن اولین پیام تیکت',
        ]);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === '111' && str_contains($params['text'], (string) $ticket->id))
            ->andReturn($this->fakeMessage());
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === '222')
            ->andReturn($this->fakeMessage());

        (new NotifyAdminsOfNewTicket($telegram))->handle(new TicketCreated($ticket));
    }

    #[Test]
    public function no_notification_is_attempted_when_no_admin_ids_are_configured(): void
    {
        config()->set('telegram.admin_ids', []);

        $ticket = Ticket::factory()->create();

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldNotReceive('sendMessage');

        (new NotifyAdminsOfNewTicket($telegram))->handle(new TicketCreated($ticket));
    }

    #[Test]
    public function answering_a_ticket_notifies_the_user_who_opened_it(): void
    {
        $user = User::factory()->create(['telegram_id' => 987654321]);
        $ticket = Ticket::factory()->for($user)->create(['subject' => 'مشکل اتصال']);
        $admin = Admin::factory()->create();

        $message = $ticket->messages()->create([
            'sender_type' => 'admin',
            'sender_id' => $admin->id,
            'message' => 'سلام، لطفاً کانفیگ را دوباره امتحان کنید.',
        ]);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === $user->telegram_id
                && str_contains($params['text'], 'کانفیگ را دوباره امتحان کنید'))
            ->andReturn($this->fakeMessage());

        (new NotifyUserOfTicketAnswer($telegram))->handle(new TicketAnswered($message));
    }

    #[Test]
    public function ticket_answer_notification_is_silently_skipped_when_user_has_no_telegram_id(): void
    {
        $user = User::factory()->create(['telegram_id' => null]);
        $ticket = Ticket::factory()->for($user)->create();
        $admin = Admin::factory()->create();

        $message = $ticket->messages()->create([
            'sender_type' => 'admin',
            'sender_id' => $admin->id,
            'message' => 'پاسخ پشتیبانی',
        ]);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldNotReceive('sendMessage');

        (new NotifyUserOfTicketAnswer($telegram))->handle(new TicketAnswered($message));
    }
}
