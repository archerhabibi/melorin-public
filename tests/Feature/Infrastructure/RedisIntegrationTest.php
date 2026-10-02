<?php

namespace Tests\Feature\Infrastructure;

use App\Support\CurrencyLock;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Redis واقعی برای Cache / RateLimiter / Queue (پیش‌فرض تست‌ها array/sync است و این‌ها را نمی‌سنجد).
 *
 * اختیاری و قفل‌دار، مثل مسیر MariaDB: فقط وقتی اجرا می‌شود که MELORIN_TEST_REDIS=1 باشد (فقط در shell/CI)
 * و میزبان محلی باشد. همیشه روی دیتابیس شماره‌ی ۱۵ Redis کار می‌کند و فقط همان را flush می‌کند.
 * بدون flag، همه‌ی تست‌های این کلاس skip می‌شوند و suite پیش‌فرض دست‌نخورده می‌ماند.
 */
class RedisIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const DB_INDEX = 15;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('MELORIN_TEST_REDIS') !== '1') {
            $this->markTestSkipped('Redis واقعی فقط با MELORIN_TEST_REDIS=1 تست می‌شود (docs/operations/DATABASE-TESTING.md).');
        }

        $host = (string) config('database.redis.default.host');
        $this->assertContains($host, ['127.0.0.1', 'localhost', '::1'], 'تست Redis فقط روی میزبان محلی مجاز است.');

        config([
            'database.redis.default.database' => self::DB_INDEX,
            'database.redis.cache.database' => self::DB_INDEX,
            'database.redis.options.prefix' => 'melorin_ci_test_',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'default',
        ]);

        Redis::purge();
        Cache::purge('redis');

        $this->assertSame(self::DB_INDEX, (int) config('database.redis.default.database'));
        Redis::connection('default')->flushdb();
    }

    protected function tearDown(): void
    {
        if (getenv('MELORIN_TEST_REDIS') === '1') {
            Redis::connection('default')->flushdb();
        }

        parent::tearDown();
    }

    #[Test]
    public function the_redis_cache_store_round_trips_and_expires_atomically(): void
    {
        $store = Cache::store('redis');

        $store->put('probe', ['a' => 1], 60);
        $this->assertSame(['a' => 1], $store->get('probe'));

        $this->assertTrue($store->add('once', 'x', 60));
        $this->assertFalse($store->add('once', 'y', 60), 'add() باید اتمیک باشد');
        $this->assertSame('x', $store->get('once'));
    }

    #[Test]
    public function cache_locks_exclude_each_other_on_redis(): void
    {
        $first = Cache::store('redis')->lock('melorin-probe-lock', 10);
        $second = Cache::store('redis')->lock('melorin-probe-lock', 10);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get(), 'قفل دوم نباید هم‌زمان گرفته شود');

        $first->release();
        $this->assertTrue($second->get());
        $second->release();
    }

    #[Test]
    public function the_rate_limiter_counts_and_clears_on_redis(): void
    {
        $limiter = new RateLimiter(Cache::store('redis'));
        $key = 'login:probe@example.com|127.0.0.1';

        for ($i = 0; $i < 5; $i++) {
            $limiter->hit($key, 60);
        }

        $this->assertTrue($limiter->tooManyAttempts($key, 5));
        $this->assertGreaterThan(0, $limiter->availableIn($key));

        $limiter->clear($key);
        $this->assertFalse($limiter->tooManyAttempts($key, 5));
    }

    #[Test]
    public function the_currency_lock_verification_cache_works_on_redis(): void
    {
        CurrencyLock::record();
        CurrencyLock::verify();

        $this->assertTrue(Cache::store('redis')->get('melorin.currency_lock.verified:'.CurrencyLock::signature()));
    }

    #[Test]
    public function a_job_travels_through_the_redis_queue_and_a_worker_runs_it(): void
    {
        $this->assertSame(0, Queue::connection('redis')->size());

        RedisProbeJob::dispatch('payload-1');

        $this->assertSame(1, Queue::connection('redis')->size());

        Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--tries' => 1]);

        $this->assertSame(0, Queue::connection('redis')->size());
        $this->assertSame('payload-1', Cache::store('redis')->get('probe-job-result'));
    }
}

class RedisProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $value) {}

    public function handle(): void
    {
        Cache::store('redis')->put('probe-job-result', $this->value, 60);
    }
}
