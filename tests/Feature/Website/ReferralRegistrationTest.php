<?php

namespace Tests\Feature\Website;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پچ 3.2.13 (نفر 5) - بستن نکته‌ی باز پچ 3.2.12 (نفر 2):
 * «ثبت‌نام سایت هنوز ?ref= را نمی‌خواند».
 * مرجع: docs/VERIFICATION-MATRIX.md
 */
class ReferralRegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function visiting_register_with_a_valid_ref_sets_the_referrer_on_signup(): void
    {
        $referrer = User::factory()->create();

        $this->get(route('website.register').'?ref='.$referrer->id)->assertOk();

        $this->post(route('website.register.store'), [
            'full_name' => 'New Person',
            'email' => 'newperson@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        $newUser = User::query()->where('email', 'newperson@example.test')->firstOrFail();
        $this->assertEquals($referrer->id, $newUser->referrer_id);
    }

    #[Test]
    public function an_invalid_ref_is_silently_ignored(): void
    {
        $this->get(route('website.register').'?ref=999999')->assertOk();

        $this->post(route('website.register.store'), [
            'full_name' => 'Another Person',
            'email' => 'another@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        $newUser = User::query()->where('email', 'another@example.test')->firstOrFail();
        $this->assertNull($newUser->referrer_id);
    }

    #[Test]
    public function registering_without_a_ref_leaves_referrer_id_null(): void
    {
        $this->get(route('website.register'))->assertOk();

        $this->post(route('website.register.store'), [
            'full_name' => 'No Ref Person',
            'email' => 'noref@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        $newUser = User::query()->where('email', 'noref@example.test')->firstOrFail();
        $this->assertNull($newUser->referrer_id);
    }
}
