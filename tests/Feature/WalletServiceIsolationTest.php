<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\User;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * این تست مخصوص جلوگیری از بازگشت باگ قبلی WalletService::purchase() است:
 * نسخه‌ی قبلی، به‌جای قفل‌کردن ردیف کیف پولِ owner مشخص، اولین ردیف کل
 * جدول wallets را قفل/چک می‌کرد. با وجود بیش از یک کیف پول در دیتابیس،
 * این یعنی ممکن بود موجودی یک کاربر بر اساس کیف پول کاربر دیگری بررسی یا
 * حتی تغییر داده شود. این تست عمداً چند کاربر با کیف پول‌های متفاوت
 * می‌سازد تا این کلاس خطا دیگر رخ ندهد.
 */
class WalletServiceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = app(WalletService::class);
    }

    #[Test]
    public function purchases_only_ever_affect_the_correct_owners_wallet(): void
    {
        // عمداً چند کیف پول با ترتیب ایجاد متفاوت می‌سازیم؛ اگر کوئری قفل
        // به‌اشتباه به «اولین ردیف جدول» برگردد، این تست شکست می‌خورد.
        $rich = User::factory()->create();
        $this->wallet->charge($rich, 1_000_000);

        $poor = User::factory()->create();
        $this->wallet->charge($poor, 5_000);

        // کاربر poor نباید بتواند بیشتر از موجودی خودش خرج کند، حتی اگر
        // در جدول wallets، ردیف کاربر rich زودتر ایجاد شده و بیشتر باشد.
        $this->expectException(InsufficientBalanceException::class);
        $this->wallet->purchase($poor, 50_000);
    }

    #[Test]
    public function a_purchase_never_changes_another_owners_balance(): void
    {
        $userA = User::factory()->create();
        $this->wallet->charge($userA, 300_000);

        $userB = User::factory()->create();
        $this->wallet->charge($userB, 300_000);

        $this->wallet->purchase($userB, 100_000);

        // موجودی userA باید دست‌نخورده باقی بماند
        $this->assertEquals(300_000, $this->wallet->balance($userA));
        $this->assertEquals(200_000, $this->wallet->balance($userB));
    }
}
