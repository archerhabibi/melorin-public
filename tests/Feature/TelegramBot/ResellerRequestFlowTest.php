<?php

namespace Tests\Feature\TelegramBot;

use App\Channels\TelegramBot\Handlers\MiscHandler;
use App\Channels\TelegramBot\Support\ConversationState;
use App\Channels\TelegramBot\UpdateRouter;
use App\Events\TicketCreated;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * پوشش تست برای «🤖 درخواست ربات نماینده و همکاری»:
 * - قبلاً این دکمه فقط پیام ثابتِ «این بخش به‌زودی فعال می‌شود.» می‌داد
 *   و متن دکمه هم با متنی که واقعاً در UpdateRouter چک می‌شد یکی نبود
 *   (باگ دکمه‌ی بی‌صدا — همان الگویی که در ۲.۴.۸ با دکمه‌ی دعوت از
 *   دوستان رخ داده بود).
 * - ادمین ربات باید پیام ثابتِ نیاز به نسخه‌ی پرو را ببیند و وارد
 *   جریان توضیحات نشود.
 * - طبق درخواست صریح، این دیگر یک broadcast خام نیست — یک تیکتِ واقعی
 *   (type=reseller_request) می‌سازد؛ broadcast به ادمین‌ها را همان
 *   TicketCreated → NotifyAdminsOfNewTicket موجود انجام می‌دهد (پوشش
 *   تستِ خودِ آن Listener در SupportTicketFlowTest از قبل هست).
 */
class ResellerRequestFlowTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    #[Test]
    public function bot_admin_sees_the_pro_version_message_and_is_not_put_into_the_description_flow(): void
    {
        config(['telegram.admin_ids' => ['999']]);

        $admin = User::factory()->create(['telegram_id' => 999]);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 999
                && str_contains($params['text'], 'نسخه‌ی پرو')
                && str_contains($params['text'], 'https://t.me/melorinpro'))
            ->andReturn($this->fakeMessage());

        $this->app->instance(Api::class, $telegram);

        app(MiscHandler::class)->resellerRequestStart(999, $admin);

        $state = app(ConversationState::class)->find(999);
        $this->assertNotSame(ConversationState::RESELLER_REQUEST_AWAITING_DESCRIPTION, $state->step);
    }

    #[Test]
    public function regular_user_without_an_open_request_is_prompted_for_a_new_one(): void
    {
        config(['telegram.admin_ids' => []]);

        $user = User::factory()->create(['telegram_id' => 123]);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 123
                && str_contains($params['text'], 'توضیحات خود را برای ثبت درخواست نمایندگی'))
            ->andReturn($this->fakeMessage());

        $this->app->instance(Api::class, $telegram);

        app(MiscHandler::class)->resellerRequestStart(123, $user);

        $state = app(ConversationState::class)->find(123);
        $this->assertSame(ConversationState::RESELLER_REQUEST_AWAITING_DESCRIPTION, $state->step);
    }

    #[Test]
    public function regular_user_with_an_already_open_request_continues_that_thread_instead_of_starting_a_new_one(): void
    {
        config(['telegram.admin_ids' => []]);

        $user = User::factory()->create(['telegram_id' => 123]);
        $existingTicket = Ticket::factory()->for($user)->create(['type' => 'reseller_request', 'status' => 'open']);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 123
                && str_contains($params['text'], "#{$existingTicket->id}")
                && str_contains($params['text'], 'پیام خود را بفرستید'))
            ->andReturn($this->fakeMessage());

        $this->app->instance(Api::class, $telegram);

        app(MiscHandler::class)->resellerRequestStart(123, $user);

        $state = app(ConversationState::class)->find(123);
        $this->assertSame(ConversationState::TICKET_AWAITING_REPLY, $state->step);
        $this->assertSame($existingTicket->id, $state->payload['ticket_id']);

        // نباید یک تیکت دومِ reseller_request برای همین کاربر ساخته شده باشد.
        $this->assertSame(1, Ticket::where('user_id', $user->id)->where('type', 'reseller_request')->count());
    }

    #[Test]
    public function submitting_a_description_creates_a_ticket_of_type_reseller_request_and_dispatches_ticket_created(): void
    {
        Event::fake([TicketCreated::class]);

        $user = User::factory()->create(['telegram_id' => 123, 'full_name' => 'کاربر تستی']);
        app(ConversationState::class)->set(123, ConversationState::RESELLER_REQUEST_AWAITING_DESCRIPTION, [], $user);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 123 && str_contains($params['text'], 'ثبت و برای بررسی ارسال شد'))
            ->andReturn($this->fakeMessage());

        $this->app->instance(Api::class, $telegram);

        app(MiscHandler::class)->resellerRequestSubmit(123, $user, 'من قبلاً ۵۰۰ نفر مشتری دارم و می‌خواهم نماینده شوم.');

        $this->assertDatabaseHas('tickets', [
            'user_id' => $user->id,
            'type' => 'reseller_request',
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('ticket_messages', [
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => 'من قبلاً ۵۰۰ نفر مشتری دارم و می‌خواهم نماینده شوم.',
        ]);

        Event::assertDispatched(TicketCreated::class, fn ($event) => $event->ticket->user_id === $user->id
            && $event->ticket->type === 'reseller_request');

        $state = app(ConversationState::class)->find(123);
        $this->assertSame(ConversationState::IDLE, $state->step);
    }

    #[Test]
    public function tapping_the_menu_button_actually_routes_to_the_handler(): void
    {
        // همان رگرسیونی که باعث شد این دکمه در نسخه‌های قبلی بی‌صدا از
        // کار بیفتد: متن دکمه در Keyboards عوض شده بود ولی UpdateRouter
        // هنوز متن قدیمی را چک می‌کرد.
        config(['telegram.admin_ids' => []]);

        $user = User::factory()->create(['telegram_id' => 123]);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => str_contains($params['text'], 'توضیحات خود را برای ثبت درخواست نمایندگی'))
            ->andReturn($this->fakeMessage());

        $this->app->instance(Api::class, $telegram);

        $update = new Update([
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'date' => time(),
                'chat' => ['id' => 123, 'type' => 'private'],
                'text' => '🤖 درخواست ربات نماینده و همکاری',
            ],
        ]);

        app(UpdateRouter::class)->handle($update, $user, 123);

        $state = app(ConversationState::class)->find(123);
        $this->assertSame(ConversationState::RESELLER_REQUEST_AWAITING_DESCRIPTION, $state->step);
    }
}
