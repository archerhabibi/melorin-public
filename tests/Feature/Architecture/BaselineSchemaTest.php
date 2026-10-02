<?php

namespace Tests\Feature\Architecture;

use App\Support\CurrencyLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Baseline اسکیما (ادغام ۸۲ Migration): شکل نهایی نباید ستون پولیِ اعشاری، ستون قدیمی یا FK گمشده داشته باشد.
 * روی هر دو درایور (sqlite و MariaDB در CI) اجرا می‌شود.
 */
class BaselineSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private const MONEY_COLUMNS = [
        'wallets' => ['balance'],
        'wallet_transactions' => ['amount', 'balance_after'],
        'payments' => ['amount'],
        'products' => ['main_price', 'reseller_price'],
        'orders' => ['main_price', 'reseller_price', 'customers_price'],
        'reseller_product_prices' => ['customers_price'],
        'resellers' => ['debt_limit'],
        'affiliate_settings' => ['customer_bonus_amount', 'referrer_bonus_amount'],
        'commissions' => ['amount', 'base_amount'],
    ];

    #[Test]
    public function every_money_column_is_an_integer(): void
    {
        foreach (self::MONEY_COLUMNS as $table => $columns) {
            $byName = collect(Schema::getColumns($table))->keyBy('name');

            foreach ($columns as $column) {
                $this->assertTrue($byName->has($column), "{$table}.{$column} وجود ندارد");
                $this->assertStringContainsString('int', strtolower($byName[$column]['type_name']), "{$table}.{$column} باید integer باشد");
            }
        }
    }

    #[Test]
    public function percentages_and_traffic_stay_decimal(): void
    {
        foreach ([['affiliate_settings', 'commission_percent'], ['commissions', 'commission_rate'], ['accounts', 'traffic_gb']] as [$table, $column]) {
            $type = collect(Schema::getColumns($table))->firstWhere('name', $column)['type_name'];
            // sqlite نوع decimal را با affinity «numeric» گزارش می‌دهد
            $this->assertMatchesRegularExpression('/decimal|numeric/', strtolower($type), "{$table}.{$column}");
        }
    }

    #[Test]
    public function the_baseline_locks_the_currency(): void
    {
        $this->assertSame(CurrencyLock::signature(), CurrencyLock::stored());
    }

    #[Test]
    public function no_legacy_column_survives_in_the_baseline(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'reseller_id'));

        foreach (['owner_type', 'owner_id', 'customer_account_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('wallets', $column), "wallets.{$column}");
        }

        foreach (['base', 'core', 'sold'] as $prefix) {
            $column = $prefix.'_price';

            $this->assertFalse(Schema::hasColumn('orders', $column), "orders.{$column}");
        }
    }

    #[Test]
    public function foreign_keys_and_their_delete_rules_are_present(): void
    {
        $expected = [
            ['wallets', 'user_id', 'users', 'cascade'],
            ['wallets', 'reseller_id', 'resellers', 'set null'],
            ['wallet_transactions', 'wallet_id', 'wallets', 'cascade'],
            ['orders', 'product_id', 'products', 'restrict'],
            ['orders', 'customer_account_id', 'customer_accounts', 'set null'],
            ['guest_checkouts', 'product_id', 'products', 'restrict'],
            ['customer_accounts', 'user_id', 'users', 'cascade'],
            ['users', 'referrer_id', 'users', 'set null'],
            ['provisioning_attempts', 'order_id', 'orders', 'cascade'],
        ];

        foreach ($expected as [$table, $column, $foreignTable, $onDelete]) {
            $match = collect(Schema::getForeignKeys($table))->first(
                fn ($fk) => $fk['columns'] === [$column] && $fk['foreign_table'] === $foreignTable
            );

            $this->assertNotNull($match, "FK {$table}.{$column} → {$foreignTable} گم شده");
            $this->assertSame($onDelete, strtolower($match['on_delete']), "{$table}.{$column} on_delete");
        }
    }

    #[Test]
    public function the_baseline_is_marked_irreversible(): void
    {
        $migration = require database_path('migrations/2026_10_03_000001_create_baseline_schema.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IRREVERSIBLE');
        $migration->down();
    }
}
