<?php

namespace Tests\Feature\Website;

use App\Channels\ResellerBot\ResellerApiFactory;
use App\Channels\TelegramBot\Support\ConversationState;
use App\Channels\TelegramBot\UpdateRouter;
use App\Models\AuditLog;
use App\Models\CustomerAccount;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\Customer\ProfileCenterException;
use App\Services\Core\Customer\ProfileCenterService;
use App\Services\Core\Customer\ProfileOverview;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

/**
 * B3.5 — Profile Center. Contract: docs/canonical/CUSTOMER-PROFILE-CONTRACT.md
 */
class ProfileCenterTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array> پیام‌های ارسال‌شده توسط ربات */
    protected array $sent = [];

    private function web(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'telegram_id' => null,
            'joined_from' => 'website',
            'full_name' => 'علی رضایی',
            'email' => 'ali'.random_int(1, 999999).'@example.test',
            'email_verified_at' => now(),
            'password' => 'secret-pass-123',
        ], $attrs));
    }

    private function core(): ProfileCenterService
    {
        return app(ProfileCenterService::class);
    }

    private function fakeBot(): Api
    {
        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')->zeroOrMoreTimes()->andReturnUsing(function (array $params) {
            $this->sent[] = $params;

            return new Message(['message_id' => 1, 'date' => time(), 'chat' => ['id' => $params['chat_id'] ?? 1, 'type' => 'private'], 'text' => 'x']);
        });
        $telegram->shouldReceive('answerCallbackQuery')->zeroOrMoreTimes();
        $telegram->shouldReceive('getMe')->zeroOrMoreTimes()->andReturn(
            new \Telegram\Bot\Objects\User(['id' => 1, 'is_bot' => true, 'first_name' => 'Melorin', 'username' => 'MelorinBot'])
        );
        $this->app->instance(Api::class, $telegram);

        return $telegram;
    }

    private function lastSent(): string
    {
        return (string) (end($this->sent)['text'] ?? '');
    }

    private function botText(User $user, string $text): void
    {
        $chat = (int) $user->telegram_id;
        app(UpdateRouter::class)->handle(new Update([
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => $chat, 'type' => 'private'], 'text' => $text],
        ]), $user, $chat);
    }

    private function botCallback(User $user, string $data): void
    {
        $chat = (int) $user->telegram_id;
        app(UpdateRouter::class)->handle(new Update([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb1', 'data' => $data,
                'from' => ['id' => $chat, 'is_bot' => false, 'first_name' => 'X'],
                'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => $chat, 'type' => 'private']],
            ],
        ]), $user, $chat);
    }

    private function mainWebhook(int $telegramId, string $firstName, string $text = '/start'): void
    {
        config(['telegram.bots.main.token' => 'tok-main', 'telegram.webhook_secret' => 'sec-main']);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'sec-main')
            ->postJson('/telegram/webhook/tok-main', [
                'update_id' => random_int(1, PHP_INT_MAX),
                'message' => [
                    'message_id' => 1, 'date' => time(),
                    'chat' => ['id' => $telegramId, 'type' => 'private'],
                    'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => $firstName],
                    'text' => $text,
                ],
            ])->assertOk();
    }

    // ───────────────────────── PhoneNumber ─────────────────────────

    #[Test]
    public function phone_numbers_normalize_to_one_canonical_form(): void
    {
        foreach (['09123456789', '۰۹۱۲۳۴۵۶۷۸۹', '+989123456789', '00989123456789', '989123456789', '9123456789', '0912 345 6789', '0912-345-6789', '+98 (912) 345-6789'] as $in) {
            $this->assertSame('09123456789', PhoneNumber::normalize($in), $in);
        }

        $this->assertSame('+14155552671', PhoneNumber::normalize('+1 415 555 2671'));

        foreach (['09123', 'abc', '09123456789x', '415 555 2671', '+0123456789', '0812345678900', ''] as $bad) {
            $this->assertNull(PhoneNumber::normalize($bad), $bad);
        }

        $this->assertContains('+989123456789', PhoneNumber::variants('09123456789'));
        $this->assertSame(['+14155552671'], PhoneNumber::variants('+14155552671'));
        $this->assertSame('0912***6789', PhoneNumber::mask('09123456789'));
    }

    // ───────────────────────── Core: خواندن ─────────────────────────

    #[Test]
    public function the_overview_reports_identity_state_and_a_checklist_of_what_is_doable(): void
    {
        $user = $this->web(['phone' => null, 'email_verified_at' => null]);

        $o = $this->core()->overview($user, StoreContext::main());

        $this->assertSame('علی رضایی', $o->fullName);
        $this->assertNull($o->phone);
        $this->assertFalse($o->emailVerified);
        $this->assertTrue($o->hasPassword);
        $this->assertFalse($o->telegramLinked);
        $this->assertFalse($o->googleLinked);
        $this->assertSame([ProfileOverview::CHECK_NAME => true, ProfileOverview::CHECK_PHONE => false, ProfileOverview::CHECK_EMAIL_VERIFIED => false], $o->checklist);
        $this->assertSame(33, $o->completionPercent());
        $this->assertEqualsCanonicalizing([ProfileOverview::CHECK_PHONE, ProfileOverview::CHECK_EMAIL_VERIFIED], $o->missing());
    }

    #[Test]
    public function a_bot_user_without_email_is_not_asked_to_verify_an_email_it_cannot_add(): void
    {
        $user = User::factory()->create(['email' => null, 'phone' => '09123456789', 'full_name' => 'کاربر ربات']);

        $o = $this->core()->overview($user, StoreContext::main());

        $this->assertArrayNotHasKey(ProfileOverview::CHECK_EMAIL_VERIFIED, $o->checklist);
        $this->assertTrue($o->isComplete());
        $this->assertSame(100, $o->completionPercent());
        $this->assertTrue($o->telegramLinked);
    }

    #[Test]
    public function reading_the_overview_never_creates_a_customer_account(): void
    {
        $user = $this->web();
        $reseller = Reseller::factory()->create();

        $this->core()->overview($user, StoreContext::main());
        $this->core()->overview($user, StoreContext::reseller($reseller));

        $this->assertSame(0, CustomerAccount::query()->where('user_id', $user->id)->count());
        $this->assertNull($this->core()->overview($user, StoreContext::main())->storeMemberSince);
    }

    #[Test]
    public function store_membership_date_comes_from_the_existing_account_of_that_context_only(): void
    {
        $user = $this->web();
        $reseller = Reseller::factory()->create();
        app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());

        $this->assertNotNull($this->core()->overview($user, StoreContext::main())->storeMemberSince);
        $this->assertNull($this->core()->overview($user, StoreContext::reseller($reseller))->storeMemberSince);
    }

    // ───────────────────────── Core: نام ─────────────────────────

    #[Test]
    public function the_name_is_cleaned_marked_as_customized_and_audited_without_its_value(): void
    {
        $user = $this->web();

        // فاصله‌ی اضافه، نویسه‌ی کنترلی، RLO (Spoofing)؛ نیم‌فاصله‌ی فارسی می‌ماند.
        $changed = $this->core()->update($user, ['full_name' => "  محمد \t رضا\u{202E}  می‌رسد\n"]);

        $this->assertSame(['full_name'], $changed);
        $user->refresh();
        $this->assertSame('محمد رضا می‌رسد', $user->full_name);
        $this->assertNotNull($user->full_name_edited_at);

        $log = AuditLog::query()->where('action', 'profile.updated')->latest('id')->firstOrFail();
        $this->assertSame($user->id, (int) $log->target_id);
        $this->assertSame(['fields' => ['full_name']], $log->after);
        $this->assertStringNotContainsString('رضا', json_encode($log->after, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function invalid_names_are_rejected_and_change_nothing(): void
    {
        $user = $this->web();

        foreach (['ع', str_repeat('ا', 101), '12345', '<script>alert(1)</script>', 'علی http://evil.test', 'علی www.evil.test', '   ', ['x']] as $bad) {
            try {
                $this->core()->update($user, ['full_name' => $bad]);
                $this->fail('پذیرفته شد: '.json_encode($bad, JSON_UNESCAPED_UNICODE));
            } catch (ProfileCenterException $e) {
                $this->assertSame('full_name', $e->field);
            }
        }

        $user->refresh();
        $this->assertSame('علی رضایی', $user->full_name);
        $this->assertNull($user->full_name_edited_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'profile.updated')->count());
    }

    #[Test]
    public function saving_the_same_values_is_a_silent_no_op_without_audit(): void
    {
        $user = $this->web(['phone' => '09123456789']);

        $this->assertSame([], $this->core()->update($user, ['full_name' => ' علی   رضایی ', 'phone' => '+98 912 345 6789']));
        $this->assertSame([], $this->core()->update($user, []));

        $this->assertNull($user->fresh()->full_name_edited_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'profile.updated')->count());
    }

    // ───────────────────────── Core: موبایل ─────────────────────────

    #[Test]
    public function a_phone_is_stored_normalized_and_can_be_removed(): void
    {
        $user = $this->web();

        $this->assertSame(['phone'], $this->core()->update($user, ['phone' => '۰۹۱۲ ۳۴۵ ۶۷۸۹']));
        $this->assertSame('09123456789', $user->fresh()->phone);
        // تغییر فقط موبایل ⇒ نام «ویرایش‌شده» حساب نمی‌شود
        $this->assertNull($user->fresh()->full_name_edited_at);

        $this->assertSame(['phone'], $this->core()->update($user, ['phone' => '  ']));
        $this->assertNull($user->fresh()->phone);

        // کلید phone نیامده ⇒ دست نمی‌خورد
        $user->forceFill(['phone' => '09120000000'])->save();
        $this->core()->update($user, ['full_name' => 'نام تازه']);
        $this->assertSame('09120000000', $user->fresh()->phone);
    }

    #[Test]
    public function an_invalid_phone_is_rejected(): void
    {
        $user = $this->web();

        foreach (['0912', 'abc', '09123456789; DROP', '۱۲۳'] as $bad) {
            try {
                $this->core()->update($user, ['phone' => $bad]);
                $this->fail('پذیرفته شد: '.$bad);
            } catch (ProfileCenterException $e) {
                $this->assertSame('phone', $e->field);
                $this->assertStringContainsString('معتبر نیست', $e->getMessage());
            }
        }

        $this->assertNull($user->fresh()->phone);
    }

    #[Test]
    public function a_phone_owned_by_another_user_is_refused_in_any_stored_format_without_revealing_the_owner(): void
    {
        $owner = $this->web(['phone' => '+98 912 345 6789', 'full_name' => 'مالک شماره']);
        $trashed = $this->web(['phone' => '09350000000']);
        $trashed->delete();
        $user = $this->web();

        foreach (['09123456789', '09350000000'] as $taken) {
            try {
                $this->core()->update($user, ['phone' => $taken]);
                $this->fail('شماره‌ی دیگری پذیرفته شد');
            } catch (ProfileCenterException $e) {
                $this->assertSame('phone', $e->field);
                $this->assertStringNotContainsString('مالک', $e->getMessage());
                $this->assertStringNotContainsString((string) $owner->email, $e->getMessage());
            }
        }

        // فرمت قدیمیِ ذخیره‌شده‌ی شماره‌ی کاربرِ خودم «بدون تغییر» است نه «تکراری».
        $this->assertSame([], $this->core()->update($owner, ['phone' => '09123456789']));
    }

    #[Test]
    public function an_inactive_account_cannot_edit_its_profile(): void
    {
        $user = $this->web(['status' => 'blocked']);

        $this->expectException(ProfileCenterException::class);
        $this->core()->update($user, ['full_name' => 'نام تازه']);
    }

    // ───────────────────────── Core: نام تلگرام ─────────────────────────

    #[Test]
    public function the_telegram_display_name_only_applies_to_a_name_that_belongs_to_telegram(): void
    {
        $svc = $this->core();

        // کاربر ربات، نام ویرایش‌نشده ⇒ دنبال تلگرام (رفتار قبلی حفظ شد)
        $bot = User::factory()->create(['joined_from' => 'bot', 'full_name' => 'نام قدیمی تلگرام']);
        $this->assertTrue($svc->applyTelegramName($bot, 'نام جدید تلگرام'));
        $this->assertSame('نام جدید تلگرام', $bot->full_name);

        // همان کاربر پس از ویرایش دستی ⇒ دیگر بازنویسی نمی‌شود
        $edited = User::factory()->create(['joined_from' => 'bot', 'full_name' => 'نام من', 'full_name_edited_at' => now()]);
        $this->assertFalse($svc->applyTelegramName($edited, 'نام تلگرام'));
        $this->assertSame('نام من', $edited->full_name);

        // ثبت‌نام‌کرده از Website که بعداً تلگرام وصل کرده ⇒ نام ثبت‌نامش می‌ماند
        $web = $this->web(['telegram_id' => 555001]);
        $this->assertFalse($svc->applyTelegramName($web, 'نام تلگرام'));
        $this->assertSame('علی رضایی', $web->full_name);

        // ولی اگر نام خالی است، از تلگرام پر می‌شود
        $blank = $this->web(['full_name' => null, 'telegram_id' => 555002]);
        $this->assertTrue($svc->applyTelegramName($blank, 'نام تلگرام'));

        // نام خالیِ تلگرام هیچ‌چیز را پاک نمی‌کند
        $this->assertFalse($svc->applyTelegramName($bot, "  \u{200B} "));
        $this->assertSame('نام جدید تلگرام', $bot->full_name);
    }

    // ───────────────────────── Website ─────────────────────────

    #[Test]
    public function the_profile_post_requires_login(): void
    {
        $this->post(route('website.identity.profile.update'), ['full_name' => 'x'])->assertRedirect(route('website.login'));
    }

    #[Test]
    public function the_profile_page_shows_the_form_checklist_and_membership_without_writing_anything(): void
    {
        $user = $this->web(['phone' => null, 'email_verified_at' => null]);

        $this->actingAs($user)->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertSee(route('website.identity.profile.update'), false)
            ->assertSee('value="علی رضایی"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('تکمیل پروفایل')
            ->assertSee('role="progressbar"', false)
            ->assertSee('تأییدنشده')
            ->assertSee('عضو Melorin از')
            ->assertDontSee('پروفایل کامل است');

        $this->assertSame(0, CustomerAccount::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'profile.updated')->count());
    }

    #[Test]
    public function a_complete_profile_shows_the_complete_badge_instead_of_the_meter(): void
    {
        $user = $this->web(['phone' => '09123456789']);

        $this->actingAs($user)->get(route('website.identity.profile.show'))
            ->assertOk()->assertSee('پروفایل کامل است')->assertDontSee('role="progressbar"', false);
    }

    #[Test]
    public function saving_the_form_updates_name_and_phone_and_redirects_with_a_message(): void
    {
        $user = $this->web();

        $this->actingAs($user)->post(route('website.identity.profile.update'), [
            'full_name' => 'سارا محمدی', 'phone' => '۰۹۱۲۱۲۳۴۵۶۷',
        ])->assertRedirect(route('website.identity.profile.show'))->assertSessionHas('status', 'اطلاعات پروفایل ذخیره شد.');

        $user->refresh();
        $this->assertSame('سارا محمدی', $user->full_name);
        $this->assertSame('09121234567', $user->phone);

        $this->actingAs($user)->post(route('website.identity.profile.update'), ['full_name' => 'سارا محمدی', 'phone' => '09121234567'])
            ->assertSessionHas('status', 'اطلاعات شما بدون تغییر است.');
    }

    #[Test]
    public function validation_errors_stay_on_the_form_with_the_entered_values(): void
    {
        $user = $this->web();

        $this->actingAs($user)->from(route('website.identity.profile.show'))
            ->post(route('website.identity.profile.update'), ['full_name' => '<b>x</b>', 'phone' => 'abc'])
            ->assertRedirect(route('website.identity.profile.show'))
            ->assertSessionHasErrors(['full_name']);

        $this->actingAs($user)->from(route('website.identity.profile.show'))
            ->post(route('website.identity.profile.update'), ['full_name' => 'نام درست', 'phone' => 'abc'])
            ->assertSessionHasErrors(['phone']);

        $this->actingAs($user)->post(route('website.identity.profile.update'), ['phone' => '09123456789'])
            ->assertSessionHasErrors(['full_name']);

        $this->actingAs($user)->post(route('website.identity.profile.update'), ['full_name' => ['a'], 'phone' => ['b']])
            ->assertSessionHasErrors(['full_name', 'phone']);

        $this->assertSame('علی رضایی', $user->fresh()->full_name);
        $this->assertNull($user->fresh()->phone);
    }

    #[Test]
    public function only_name_and_phone_can_be_changed_from_this_route(): void
    {
        $user = $this->web(['email' => 'keep@example.test']);
        $other = $this->web();

        $this->actingAs($user)->post(route('website.identity.profile.update'), [
            'full_name' => 'نام تازه', 'phone' => '',
            'email' => 'evil@example.test', 'status' => 'blocked', 'telegram_id' => 777, 'referrer_id' => $other->id,
            'id' => $other->id, 'password' => 'hacked-pass-1', 'email_verified_at' => null,
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('نام تازه', $user->full_name);
        $this->assertSame('keep@example.test', $user->email);
        $this->assertSame('active', $user->status);
        $this->assertNull($user->telegram_id);
        $this->assertNull($user->referrer_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('علی رضایی', $other->fresh()->full_name);
    }

    #[Test]
    public function a_phone_conflict_shows_a_generic_message_that_does_not_reveal_the_owner(): void
    {
        $this->web(['phone' => '09123456789', 'full_name' => 'صاحب واقعی', 'email' => 'owner@example.test']);
        $user = $this->web();

        $html = $this->actingAs($user)->followingRedirects()->from(route('website.identity.profile.show'))
            ->post(route('website.identity.profile.update'), ['full_name' => 'علی رضایی', 'phone' => '09123456789'])
            ->assertOk()->assertSee('ثبت این شماره ممکن نیست')->getContent();

        $this->assertStringNotContainsString('صاحب واقعی', $html);
        $this->assertStringNotContainsString('owner@example.test', $html);
        $this->assertNull($user->fresh()->phone);
    }

    #[Test]
    public function a_stored_name_is_always_escaped_on_the_page(): void
    {
        $user = $this->web(['full_name' => '<img src=x onerror=alert(1)>']);

        $html = $this->actingAs($user)->get(route('website.identity.profile.show'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }

    #[Test]
    public function the_profile_post_is_throttled_per_user(): void
    {
        $user = $this->web();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->post(route('website.identity.profile.update'), ['full_name' => 'نام '.$i.' تست'])->assertRedirect();
        }

        $this->actingAs($user)->post(route('website.identity.profile.update'), ['full_name' => 'نام یازدهم'])->assertStatus(429);
    }

    #[Test]
    public function the_reseller_store_edits_the_same_profile_and_stays_inside_the_store(): void
    {
        $reseller = Reseller::factory()->create();
        $user = $this->web();

        $this->actingAs($user)->get(route('website.store.identity.profile.show', $reseller->slug))
            ->assertOk()->assertSee(route('website.store.identity.profile.update', $reseller->slug), false)
            ->assertSee('مشترک است');

        $this->actingAs($user)->post(route('website.store.identity.profile.update', $reseller->slug), ['full_name' => 'نام در فروشگاه', 'phone' => '09123456789'])
            ->assertRedirect(route('website.store.identity.profile.show', $reseller->slug));

        // Profile = User: همان نام در فروشگاه اصلی هم دیده می‌شود.
        $this->actingAs($user)->get(route('website.identity.profile.show'))->assertOk()->assertSee('value="نام در فروشگاه"', false);
        $this->assertSame(0, CustomerAccount::query()->where('user_id', $user->id)->count());
    }

    // ───────────────────────── Telegram: هماهنگی با Core ─────────────────────────

    #[Test]
    public function the_bot_profile_shows_the_same_overview_as_the_website_with_edit_buttons(): void
    {
        $this->fakeBot();
        $user = User::factory()->create(['telegram_id' => 4001, 'full_name' => 'کاربر تلگرام', 'phone' => null, 'email' => 'bot@example.test', 'email_verified_at' => null]);

        $this->botText($user, '👤 حساب کاربری');

        $text = $this->lastSent();
        $this->assertStringContainsString('شناسه‌ی تلگرام: 4001', $text);
        $this->assertStringContainsString('نام: کاربر تلگرام', $text);
        $this->assertStringContainsString('موبایل: ثبت نشده', $text);
        $this->assertStringContainsString('bot@example.test ⏳ تأییدنشده', $text);
        $this->assertStringContainsString('موجودی کیف پول', $text);
        $this->assertStringContainsString('برای تکمیل پروفایل: شماره‌ی موبایل، تأیید ایمیل', $text);

        $markup = (string) end($this->sent)['reply_markup'];
        $this->assertStringContainsString('profile:edit:name', $markup);
        $this->assertStringContainsString('profile:edit:phone', $markup);
    }

    #[Test]
    public function a_bot_user_without_email_sees_no_email_hint_and_no_website_instruction(): void
    {
        $this->fakeBot();
        $user = User::factory()->create(['telegram_id' => 4002, 'email' => null, 'phone' => '09123456789']);

        $this->botText($user, '👤 حساب کاربری');

        $this->assertStringContainsString('ایمیل: ثبت نشده', $this->lastSent());
        $this->assertStringNotContainsString('تأیید ایمیل', $this->lastSent());
        $this->assertStringNotContainsString('برای تکمیل پروفایل', $this->lastSent());
    }

    #[Test]
    public function the_name_can_be_edited_in_the_bot_through_the_same_core_and_survives_later_messages(): void
    {
        $this->fakeBot();
        $user = User::factory()->create(['telegram_id' => 4003, 'full_name' => 'نام تلگرام', 'joined_from' => 'bot']);

        $this->botCallback($user, 'profile:edit:name');
        $this->assertSame(ConversationState::PROFILE_AWAITING_NAME, app(ConversationState::class)->find(4003)->step);

        $this->botText($user, '  نام   انتخابی  من ');

        $user->refresh();
        $this->assertSame('نام انتخابی من', $user->full_name);
        $this->assertNotNull($user->full_name_edited_at);
        $this->assertSame(ConversationState::IDLE, app(ConversationState::class)->find(4003)->step);
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile.updated', 'target_id' => $user->id]);

        // وب‌هوک واقعی: نام نمایشی تلگرام عوض شده، ولی نام انتخابی بازنویسی نمی‌شود.
        $this->mainWebhook(4003, 'نام بعدی تلگرام');
        $this->assertSame('نام انتخابی من', $user->fresh()->full_name);

        // و همان نام در Website دیده می‌شود (یک Core).
        $web = $user->fresh();
        $this->actingAs($web)->get(route('website.identity.profile.show'))->assertSee('value="نام انتخابی من"', false);
    }

    #[Test]
    public function an_invalid_bot_input_keeps_the_step_open_and_changes_nothing(): void
    {
        $this->fakeBot();
        $user = User::factory()->create(['telegram_id' => 4004, 'full_name' => 'نام قبلی']);

        $this->botCallback($user, 'profile:edit:name');
        $this->botText($user, '12345');

        $this->assertStringContainsString('⚠️ نام واردشده معتبر نیست.', $this->sent[count($this->sent) - 1]['text']);
        $this->assertSame(ConversationState::PROFILE_AWAITING_NAME, app(ConversationState::class)->find(4004)->step);
        $this->assertSame('نام قبلی', $user->fresh()->full_name);

        // دوباره با مقدار درست
        $this->botText($user, 'نام درست');
        $this->assertSame('نام درست', $user->fresh()->full_name);
    }

    #[Test]
    public function the_phone_can_be_set_and_cleared_from_the_bot_with_core_validation_and_uniqueness(): void
    {
        $this->fakeBot();
        $user = User::factory()->create(['telegram_id' => 4005, 'phone' => null]);
        $this->web(['phone' => '09350001122']);

        $this->botCallback($user, 'profile:edit:phone');
        $this->botText($user, 'نه');
        $this->assertStringContainsString('معتبر نیست', collect($this->sent)->pluck('text')->implode("\n"));
        $this->assertSame(ConversationState::PROFILE_AWAITING_PHONE, app(ConversationState::class)->find(4005)->step);

        $this->botText($user, '09350001122');
        $this->assertStringContainsString('ثبت این شماره ممکن نیست', $this->lastSent());
        $this->assertNull($user->fresh()->phone);

        $this->botText($user, '+98 912 000 1122');
        $this->assertSame('09120001122', $user->fresh()->phone);
        $this->assertNull($user->fresh()->full_name_edited_at);

        $this->botCallback($user, 'profile:edit:phone');
        $this->botText($user, '-');
        $this->assertNull($user->fresh()->phone);
    }

    #[Test]
    public function pressing_a_menu_button_leaves_the_edit_step_and_never_saves_the_button_text_as_a_name(): void
    {
        $this->fakeBot();
        $user = User::factory()->create(['telegram_id' => 4006, 'full_name' => 'نام قبلی']);

        $this->botCallback($user, 'profile:edit:name');
        $this->botText($user, '💰 کیف پول و شارژ حساب');

        $this->assertSame('نام قبلی', $user->fresh()->full_name);
        $this->assertNotSame(ConversationState::PROFILE_AWAITING_NAME, app(ConversationState::class)->find(4006)->step);
    }

    #[Test]
    public function an_unknown_edit_field_from_a_crafted_callback_does_nothing(): void
    {
        $this->fakeBot();
        $user = User::factory()->create(['telegram_id' => 4007]);

        $this->botCallback($user, 'profile:edit:email');
        $this->botCallback($user, 'profile:edit:');

        $this->assertSame(ConversationState::IDLE, app(ConversationState::class)->find(4007)->step);
        $this->assertSame([], $this->sent);
    }

    #[Test]
    public function the_main_bot_still_follows_the_telegram_name_for_users_who_never_edited_it(): void
    {
        $this->fakeBot();

        $this->mainWebhook(4008, 'اولین نام');
        $user = User::query()->where('telegram_id', 4008)->firstOrFail();
        $this->assertSame('اولین نام', $user->full_name);

        $this->mainWebhook(4008, 'نام عوض‌شده');
        $this->assertSame('نام عوض‌شده', $user->fresh()->full_name);
        $this->assertNull($user->fresh()->full_name_edited_at);
    }

    #[Test]
    public function a_website_member_who_links_telegram_keeps_the_registered_name_when_the_bot_talks(): void
    {
        $this->fakeBot();
        $user = $this->web(['telegram_id' => 4009, 'full_name' => 'نام ثبت‌نام سایت']);

        $this->mainWebhook(4009, 'نام نمایشی تلگرام');

        $this->assertSame('نام ثبت‌نام سایت', $user->fresh()->full_name);
        $this->assertSame(1, User::query()->where('telegram_id', 4009)->count());
    }

    #[Test]
    public function the_reseller_bot_applies_the_same_name_rule(): void
    {
        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')->zeroOrMoreTimes()->andReturn(new Message(['message_id' => 1, 'date' => time(), 'chat' => ['id' => 1, 'type' => 'private'], 'text' => 'x']));
        $telegram->shouldReceive('getMe')->zeroOrMoreTimes()->andReturn(new \Telegram\Bot\Objects\User(['id' => 1, 'is_bot' => true, 'first_name' => 'Shop', 'username' => 'shop_bot']));
        $telegram->shouldReceive('answerCallbackQuery')->zeroOrMoreTimes();
        $factory = Mockery::mock(ResellerApiFactory::class);
        $factory->shouldReceive('make')->andReturn($telegram);
        $this->app->instance(ResellerApiFactory::class, $factory);

        $reseller = Reseller::factory()->create();
        $edited = User::factory()->create(['telegram_id' => 4010, 'full_name' => 'نام انتخابی', 'full_name_edited_at' => now()]);
        $following = User::factory()->create(['telegram_id' => 4011, 'full_name' => 'نام قدیم', 'joined_from' => 'reseller_bot']);

        foreach ([4010 => 'نام تلگرام A', 4011 => 'نام تلگرام B'] as $tid => $name) {
            $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $reseller->ensureWebhookSecret())
                ->postJson("/reseller-bot/webhook/{$reseller->webhook_slug}", [
                    'update_id' => random_int(1, PHP_INT_MAX),
                    'message' => [
                        'message_id' => 1, 'date' => time(), 'chat' => ['id' => $tid, 'type' => 'private'],
                        'from' => ['id' => $tid, 'is_bot' => false, 'first_name' => $name], 'text' => '/start',
                    ],
                ])->assertOk();
        }

        $this->assertSame('نام انتخابی', $edited->fresh()->full_name);
        $this->assertSame('نام تلگرام B', $following->fresh()->full_name);
    }
}
