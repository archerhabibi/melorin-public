<?php

namespace Tests\Feature\Website;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Receipt Upload Security.
 * مرجع: docs/history/PHASE-W6-PART3-RECEIPT-SECURITY.md
 *
 * توجه: Storage خصوصی (دیسک local) و سرو کنترل‌شده بخشی از مسیر شارژ
 * هستند؛ این تست‌ها فقط سخت‌سازیِ آپلود روی همان مسیر را می‌سنجند.
 */
class ReceiptSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function makePendingPayment(): array
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $method = PaymentMethod::factory()->create();

        $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 100000,
            'payment_method_id' => $method->id,
        ]);

        return [$user, Payment::query()->where('user_id', $user->id)->firstOrFail()];
    }

    #[Test]
    public function a_pdf_receipt_is_accepted(): void
    {
        [$user, $payment] = $this->makePendingPayment();

        $response = $this->actingAs($user)->post(route('website.wallet.receipt.store', $payment->id), [
            'depositor_name' => 'تست',
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 500, 'application/pdf'),
        ]);

        $response->assertSessionDoesntHaveErrors('receipt');
    }

    #[Test]
    public function a_file_larger_than_five_megabytes_is_rejected(): void
    {
        [$user, $payment] = $this->makePendingPayment();

        $response = $this->actingAs($user)->post(route('website.wallet.receipt.store', $payment->id), [
            'depositor_name' => 'تست',
            'receipt' => UploadedFile::fake()->create('big.jpg', 5121), // KB, just over 5MB
        ]);

        $response->assertSessionHasErrors('receipt');
    }

    #[Test]
    public function a_disguised_executable_is_rejected_by_real_content_validation(): void
    {
        [$user, $payment] = $this->makePendingPayment();

        // پسوند jpg ولی محتوای واقعی متنی/غیر تصویر - mimes: باید رد کند.
        // ⚠️ UploadedFile::fake() نوع فایل را از «نام» می‌گیرد نه محتوا، پس
        // برای تست اعتبارسنجی واقعیِ محتوا باید یک فایل واقعی روی دیسک ساخت.
        $tmp = tempnam(sys_get_temp_dir(), 'rcpt');
        file_put_contents($tmp, "<?php echo 'x'; ?>");
        $fake = new UploadedFile($tmp, 'not-an-image.jpg', 'image/jpeg', null, true);

        $response = $this->actingAs($user)->post(route('website.wallet.receipt.store', $payment->id), [
            'depositor_name' => 'تست',
            'receipt' => $fake,
        ]);

        $response->assertSessionHasErrors('receipt');
    }
}
