<?php

namespace Tests\Feature\Architecture;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * فاز ۱۵ — Rule 12 («یک User می‌تواند Customer چند Reseller باشد») و ساختار نهایی Wallet.
 * نگهبان‌های دائمی: هر بازگشتِ وابستگی به users.reseller_id یا ستون‌های legacy Wallet قرمز می‌شود.
 */
class MembershipGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function no_code_reads_or_writes_the_single_valued_users_reseller_id(): void
    {
        $patterns = [
            '/User::query\(\)\s*->\s*(where|whereNull|whereNotNull)\(\s*\'reseller_id\'/',
            '/\$user->reseller(_id)?\b(?!\s*\()/',
            '/hasMany\(\s*User::class\s*,\s*\'reseller_id\'\s*\)/',
            '/->update\(\s*\[\s*\'reseller_id\'/',
        ];

        $offenders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $code)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, "وابستگی به users.reseller_id (تک‌مقداری) باقی مانده:\n".implode("\n", $offenders));
    }

    #[Test]
    public function the_user_model_no_longer_exposes_a_single_reseller(): void
    {
        $this->assertFalse(method_exists(User::class, 'reseller'));
        $this->assertNotContains('reseller_id', (new User)->getFillable());
    }

    #[Test]
    public function the_legacy_wallet_owner_columns_are_gone(): void
    {
        foreach (['owner_type', 'owner_id', 'customer_account_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('wallets', $column), "ستون legacy {$column} باید حذف شده باشد");
        }

        foreach (['user_id', 'store_type', 'reseller_id', 'scope_key', 'balance'] as $column) {
            $this->assertTrue(Schema::hasColumn('wallets', $column), "ستون {$column} باید وجود داشته باشد");
        }
    }
}
