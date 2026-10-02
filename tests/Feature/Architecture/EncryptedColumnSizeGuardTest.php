<?php

namespace Tests\Feature\Architecture;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * هر ستونی که با cast «encrypted» ذخیره می‌شود باید text باشد، نه
 * varchar(255): payload رمزنگاری‌شده‌ی Laravel حتی برای ۴۰ کاراکتر
 * ۲۵۶ کاراکتر می‌شود. SQLite طول varchar را اعمال نمی‌کند و تست‌های
 * قبلی با توکن‌های کوتاه (مثل «x») این باگ را روی MySQL نمی‌دیدند.
 * این guard نوع ستون را مستقیم و برای «همه‌ی مدل‌ها» بررسی می‌کند، پس
 * مدل/ستون encryptedِ جدید هم خودکار پوشش داده می‌شود.
 */
class EncryptedColumnSizeGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_telegram_token_does_not_fit_in_255_when_encrypted(): void
    {
        $encrypted = encrypt('123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', false);

        $this->assertGreaterThan(255, strlen($encrypted));
    }

    public function test_every_encrypted_cast_column_in_every_model_is_a_text_column(): void
    {
        $checked = 0;

        foreach (File::allFiles(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $model = new $class();
            $columns = collect(Schema::getColumns($model->getTable()))->keyBy('name');

            foreach ($model->getCasts() as $attribute => $cast) {
                if ($cast !== 'encrypted' && ! str_starts_with((string) $cast, 'encrypted:')) {
                    continue;
                }

                $this->assertTrue($columns->has($attribute), "{$class}::{$attribute} has no column");
                $this->assertContains(
                    strtolower($columns[$attribute]['type_name']),
                    ['text', 'mediumtext', 'longtext'],
                    "{$class}::{$attribute} is cast encrypted but its column is not text"
                );
                $checked++;
            }
        }

        // Reseller (۲) + Account (۱) + ServerPanel (۱)؛ اگر صفر شد یعنی اسکن خراب شده
        $this->assertGreaterThanOrEqual(4, $checked);
    }
}
