<?php

namespace App\Filament\Support;

use App\Support\Money;
use Closure;
use Filament\Forms\Components\TextInput;

/**
 * فیلد مبلغ در Filament (فاز ۵): کاربر به «واحد اصلی ارز» می‌نویسد (۱۰۰۰۰ تومان، 5.50 دلار)
 * و فرم فقط «Minor Unit به‌صورت int» برمی‌گرداند (getState / $data).
 *
 *   Hydrate  : Minor Unit از مدل/fillForm → رشته‌ی واحد اصلی برای نمایش (Money::toMajorString)
 *   Validate : Money::parse باید موفق شود (اعشارِ بیش از decimals ارز رد می‌شود، نه گرد)
 *   Dehydrate: ورودی → int Minor Unit (Money::parse)
 *
 * بنابراین مصرف‌کننده‌ها (Resource/Action) هرگز با واحد اصلی، ضرب/تقسیم یا float کار نمی‌کنند.
 * minValue()/maxValue() روی «واحد اصلی» (همان عددی که کاربر می‌بیند) اعمال می‌شوند.
 */
class MoneyInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->numeric()
            ->step(Money::inputStep())
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }

                if (Money::parse((string) $value) === null) {
                    $decimals = Money::decimals();

                    $fail($decimals === 0
                        ? 'مبلغ باید عدد صحیح باشد (اعشار مجاز نیست).'
                        : "مبلغ نامعتبر است (حداکثر {$decimals} رقم اعشار).");
                }
            })
            ->formatStateUsing(
                fn (mixed $state): ?string => ($state === null || $state === '')
                    ? null
                    : Money::toMajorString(Money::int($state))
            )
            ->dehydrateStateUsing(
                fn (mixed $state): ?int => ($state === null || $state === '')
                    ? null
                    : Money::parse((string) $state)
            );
    }
}
