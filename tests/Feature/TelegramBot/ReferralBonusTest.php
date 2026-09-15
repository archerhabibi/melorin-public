<?php

namespace Tests\Feature\TelegramBot;

use App\Channels\TelegramBot\Handlers\StartHandler;
use App\Models\AffiliateSetting;
use App\Models\User;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Objects\User as TelegramUser;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * پوشش تست برای رفع باگ گزارش‌شده: لینک دعوت وعده‌ی «پاداش X تومان»
 * می‌داد ولی هیچ‌جا واریز نمی‌شد. طبق تصمیم صریح، فقط پاداش عضویتِ
 * معرف پیاده‌سازی شده — نه کمیسیون، نه پاداش به خودِ کاربر تازه‌وارد.
 *
 * توجه: پرداخت به «اولین ثبت referrer_id» گره خورده، نه به
 * wasRecentlyCreated — پس کاربری که از قبل در دیتابیس بوده ولی هنوز
 * معرفی نداشته هم باید پاداشِ معرفش را بگیرد (نگاه کنید به تست دوم).
 */
class ReferralBonusTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // StartHandler::botDisplayName() برای پیام خوش‌آمدگویی از
        // getMe() استفاده می‌کند (ر.ک. StartHandler.php)؛ بدون این
        // استاب، shouldIgnoreMissing() یک mock خام برمی‌گرداند که
        // getFirstName() (متد مجازی/magic شیء User) را ندارد.
        $this->fakeTelegram()
            ->shouldReceive('getMe')
            ->zeroOrMoreTimes()
            ->andReturn(new TelegramUser([
                'id' => 1,
                'is_bot' => true,
                'first_name' => 'Melorin',
                'username' => 'MelorinBot',
            ]));
    }

    #[Test]
    public function referrer_is_credited_when_a_brand_new_user_joins_via_their_link(): void
    {
        Http::fake();

        AffiliateSetting::current()->update(['referrer_bonus_amount' => 20000]);

        $referrer = User::factory()->create();
        $wallet = app(WalletService::class);

        $this->assertEquals(0, $wallet->balance($referrer));

        // کاربر تازه (هنوز در دیتابیس نیست) درست همان چیزی که
        // WebhookController::__invoke برای یک عضو واقعاً جدید می‌سازد.
        $newUser = User::factory()->make(['telegram_id' => 999888777, 'referrer_id' => null]);
        $newUser->save();

        app(StartHandler::class)->handle(999888777, $newUser, (string) $referrer->id);

        $this->assertEquals($referrer->id, $newUser->fresh()->referrer_id);
        $this->assertEquals(20000, $wallet->balance($referrer));

        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'referral_bonus',
            'amount' => 20000,
        ]);
    }

    #[Test]
    public function referrer_is_credited_even_when_the_referred_user_already_existed_without_a_referrer(): void
    {
        Http::fake();

        AffiliateSetting::current()->update(['referrer_bonus_amount' => 20000]);

        $referrer = User::factory()->create();
        $wallet = app(WalletService::class);

        // کاربری که مدت‌ها قبل و بدون هیچ لینک دعوتی با ربات شروع کرده
        // (wasRecentlyCreated الان قطعاً false است) و هنوز هیچ معرفی
        // ندارد. طبق اصلاح گزارش‌شده، این کاربر نباید معرفش را از پاداش
        // محروم کند فقط به این دلیل که ردیفش تازه نیست.

        $existingUser = User::factory()->create([
            'referrer_id' => null,
        ]);

        $existingUser = User::findOrFail($existingUser->id);

        $this->assertFalse($existingUser->wasRecentlyCreated);

        app(StartHandler::class)->handle($existingUser->telegram_id, $existingUser, (string) $referrer->id);

        $this->assertEquals($referrer->id, $existingUser->fresh()->referrer_id);
        $this->assertEquals(20000, $wallet->balance($referrer));

        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'referral_bonus',
            'amount' => 20000,
        ]);
    }

    #[Test]
    public function bonus_is_not_paid_twice_if_start_is_sent_again_with_the_same_referral_link(): void
    {
        Http::fake();

        AffiliateSetting::current()->update(['referrer_bonus_amount' => 20000]);

        $referrer = User::factory()->create();
        $wallet = app(WalletService::class);

        $newUser = User::factory()->make(['telegram_id' => 444555666, 'referrer_id' => null]);
        $newUser->save();

        // اولین /start با لینک دعوت — پاداش باید دقیقاً یک‌بار پرداخت شود.
        app(StartHandler::class)->handle(444555666, $newUser, (string) $referrer->id);
        $this->assertEquals(20000, $wallet->balance($referrer));

        // همان کاربر دوباره /start را با همان لینک می‌زند (مثلاً وب‌هوک
        // تکراری تلگرام یا اینکه کاربر دوباره لینک را باز می‌کند) — چون
        // referrer_id از قبل ثبت شده، نباید پاداش دوباره پرداخت شود.
        app(StartHandler::class)->handle(444555666, $newUser->fresh(), (string) $referrer->id);

        $this->assertEquals(20000, $wallet->balance($referrer));
        $this->assertEquals(
            1,
            DB::table('wallet_transactions')->where('type', 'referral_bonus')->count()
        );
    }

    #[Test]
    public function unconfigured_zero_bonus_does_not_break_registration(): void
    {
        Http::fake();

        // AffiliateSetting::current() پیش‌فرض صفر است — عمداً چیزی ست
        // نمی‌کنیم تا همان مسیر پیش‌فرض واقعی تست شود.
        $referrer = User::factory()->create();

        $newUser = User::factory()->make(['telegram_id' => 555444333, 'referrer_id' => null]);
        $newUser->save();

        app(StartHandler::class)->handle(555444333, $newUser, (string) $referrer->id);

        $this->assertEquals($referrer->id, $newUser->fresh()->referrer_id);
        $this->assertEquals(0, app(WalletService::class)->balance($referrer));
        $this->assertDatabaseMissing('wallet_transactions', ['type' => 'referral_bonus']);
    }

    #[Test]
    public function self_referral_is_ignored(): void
    {
        Http::fake();

        AffiliateSetting::current()->update(['referrer_bonus_amount' => 20000]);

        $newUser = User::factory()->make(['telegram_id' => 111222333, 'referrer_id' => null]);
        $newUser->save();

        app(StartHandler::class)->handle(111222333, $newUser, (string) $newUser->id);

        $this->assertNull($newUser->fresh()->referrer_id);
        $this->assertDatabaseMissing('wallet_transactions', ['type' => 'referral_bonus']);
    }
}
