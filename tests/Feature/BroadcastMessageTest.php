<?php

namespace Tests\Feature;

use App\Filament\Pages\BroadcastMessage;
use App\Jobs\SendBroadcastMessage;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

class BroadcastMessageTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    #[Test]
    public function admin_sending_a_broadcast_dispatches_the_job_with_the_message_text(): void
    {
        Bus::fake();
        $this->actingAsAdmin();

        User::factory()->create(['telegram_id' => 111]);
        User::factory()->create(['telegram_id' => 222]);

        Livewire::test(BroadcastMessage::class)
            ->fillForm(['text' => 'اطلاعیه: امشب سرورها آپدیت می‌شوند.'])
            ->call('send')
            ->assertHasNoFormErrors();

        Bus::assertDispatched(SendBroadcastMessage::class, fn ($job) => $job->text === 'اطلاعیه: امشب سرورها آپدیت می‌شوند.');
    }

    #[Test]
    public function no_job_is_dispatched_when_there_are_no_recipients(): void
    {
        Bus::fake();
        $this->actingAsAdmin();

        // هیچ کاربری با telegram_id ساخته نشده.
        Livewire::test(BroadcastMessage::class)
            ->fillForm(['text' => 'پیامی که نباید ارسال شود.'])
            ->call('send');

        Bus::assertNotDispatched(SendBroadcastMessage::class);
    }

    #[Test]
    public function the_job_sends_the_message_to_every_user_with_a_telegram_id(): void
    {
        $userA = User::factory()->create(['telegram_id' => 111]);
        $userB = User::factory()->create(['telegram_id' => 222]);
        User::factory()->create(['telegram_id' => null]); // نباید هیچ پیامی بگیرد

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === $userA->telegram_id && $params['text'] === 'متن پیام همگانی تست.')
            ->andReturn($this->fakeMessage());
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === $userB->telegram_id)
            ->andReturn($this->fakeMessage());

        (new SendBroadcastMessage('متن پیام همگانی تست.'))->handle($telegram);
    }

    #[Test]
    public function a_failure_for_one_recipient_does_not_stop_the_rest_of_the_broadcast(): void
    {
        $userA = User::factory()->create(['telegram_id' => 111]);
        $userB = User::factory()->create(['telegram_id' => 222]);

        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === $userA->telegram_id)
            ->andThrow(new \Exception('کاربر ربات را بلاک کرده است.'));
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === $userB->telegram_id)
            ->andReturn($this->fakeMessage());

        (new SendBroadcastMessage('متن پیام.'))->handle($telegram);
    }
}
