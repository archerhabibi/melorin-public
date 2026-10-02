<?php

namespace Tests\Feature\Website;

use App\Models\Category;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rate Limiting.
 * مرجع: docs/history/PHASE-W6-PART2-RATE-LIMITING.md
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function password_reset_requests_are_limited_to_three_per_hour_per_email(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->post(route('website.password.email'), ['email' => 'victim@example.test']);
        }

        $response = $this->post(route('website.password.email'), ['email' => 'victim@example.test']);

        $response->assertSessionHasErrors('email');
    }

    #[Test]
    public function password_reset_limit_is_per_email_and_does_not_block_other_emails(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->post(route('website.password.email'), ['email' => 'victim@example.test']);
        }

        $response = $this->post(route('website.password.email'), ['email' => 'someone-else@example.test']);

        $response->assertSessionDoesntHaveErrors('email');
    }

    #[Test]
    public function receipt_upload_is_limited_to_five_per_minute(): void
    {
        Storage::fake('local');

        $category = Category::factory()->create(['status' => 'active']);
        $product = Product::factory()->create(['category_id' => $category->id, 'main_price' => 100000, 'status' => 'active']);
        $user = User::factory()->create();
        $method = PaymentMethod::factory()->create();

        $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 100000,
            'payment_method_id' => $method->id,
        ]);
        $payment = Payment::query()->where('user_id', $user->id)->firstOrFail();

        $lastResponse = null;
        for ($i = 1; $i <= 6; $i++) {
            $lastResponse = $this->actingAs($user)->post(route('website.wallet.receipt.store', $payment->id), [
                'depositor_name' => 'test',
                'receipt' => UploadedFile::fake()->image('r.jpg'),
            ]);
        }

        $lastResponse->assertStatus(429);
    }
}
