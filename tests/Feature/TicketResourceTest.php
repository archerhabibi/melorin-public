<?php

namespace Tests\Feature;

use App\Events\TicketAnswered;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Filament\Resources\TicketResource\RelationManagers\MessagesRelationManager;
use App\Models\Admin;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * این تست‌ها مستقیماً همان مسیری را طی می‌کنند که یک ادمین واقعی در
 * پنل طی می‌کند: باز کردن لیست تیکت‌ها، باز کردن یک تیکت، و زدن دکمه‌ی
 * «پاسخ». هدف گرفتن دقیقاً همان نوع باگ silent-500 است که قبلاً در
 * Keyboards.php رخ داده بود — یعنی صرفاً syntax درست بودن کافی نیست،
 * باید واقعاً از طریق Livewire رندر و اجرا شود.
 */
class TicketResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    #[Test]
    public function admin_can_load_the_ticket_list_page(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create();
        $ticket = Ticket::factory()->for($user)->create();
        $ticket->messages()->create([
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => 'سلام، مشکلی دارم.',
        ]);

        Livewire::test(ListTickets::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$ticket]);
    }

    #[Test]
    public function admin_can_open_a_ticket_and_see_its_messages(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create();
        $ticket = Ticket::factory()->for($user)->create();
        $firstMessage = $ticket->messages()->create([
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => 'اتصال ربات قطع می‌شود.',
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertSuccessful();

        Livewire::test(MessagesRelationManager::class, [
            'ownerRecord' => $ticket,
            'pageClass' => ViewTicket::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$firstMessage]);
    }

    #[Test]
    public function admin_replying_creates_a_message_marks_the_ticket_answered_and_dispatches_the_event(): void
    {
        Event::fake([TicketAnswered::class]);

        $admin = $this->actingAsAdmin();

        $user = User::factory()->create();
        $ticket = Ticket::factory()->for($user)->create(['status' => 'open']);
        $ticket->messages()->create([
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => 'لطفاً کمکم کنید.',
        ]);

        Livewire::test(MessagesRelationManager::class, [
            'ownerRecord' => $ticket,
            'pageClass' => ViewTicket::class,
        ])
            ->callTableAction('reply', null, data: [
                'message' => 'مشکل شما بررسی و برطرف شد.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'sender_type' => 'admin',
            'sender_id' => $admin->id,
            'message' => 'مشکل شما بررسی و برطرف شد.',
        ]);

        $this->assertSame('answered', $ticket->fresh()->status);

        Event::assertDispatched(TicketAnswered::class, fn ($event) => $event->message->ticket_id === $ticket->id);
    }

    #[Test]
    public function reply_action_is_hidden_once_a_ticket_is_closed(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create();
        $ticket = Ticket::factory()->for($user)->create(['status' => 'closed']);

        Livewire::test(MessagesRelationManager::class, [
            'ownerRecord' => $ticket,
            'pageClass' => ViewTicket::class,
        ])
            ->assertTableActionHidden('reply');
    }

    #[Test]
    public function closing_a_ticket_from_the_list_updates_its_status(): void
    {
        $this->actingAsAdmin();

        $ticket = Ticket::factory()->create(['status' => 'open']);

        Livewire::test(ListTickets::class)
            ->callTableAction('close', $ticket)
            ->assertHasNoTableActionErrors();

        $this->assertSame('closed', $ticket->fresh()->status);
    }
}
