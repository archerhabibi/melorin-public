<?php

namespace Tests\Feature\Money;

use App\Channels\ResellerBot\Support\Keyboards;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Money: تنها نقطه‌ی تبدیل/نمایش/محاسبه‌ی مبلغ.
 * پیش‌فرض تست‌ها تومان (IRT، decimals=0)؛ ارز دلاری با config() در همان تست تنظیم می‌شود.
 */
class MoneyTest extends TestCase
{
    protected function usd(string $position = 'before', string $label = '$'): void
    {
        config([
            'melorin.currency.code' => 'USD',
            'melorin.currency.label' => $label,
            'melorin.currency.decimals' => 2,
            'melorin.currency.symbol_position' => $position,
        ]);
    }

    // ── parse (IRT) ─────────────────────────────────────────────

    #[Test]
    public function parse_accepts_whole_numbers_in_any_digit_script_and_separators(): void
    {
        $this->assertSame(50000, Money::parse(50000));
        $this->assertSame(50000, Money::parse('50000'));
        $this->assertSame(50000, Money::parse('50,000'));
        $this->assertSame(50000, Money::parse('۵۰٬۰۰۰'));
        $this->assertSame(50000, Money::parse('٥٠٠٠٠'));
        $this->assertSame(50000, Money::parse(' 50 000 '));
        $this->assertSame(50000, Money::parse(50000.0));
        $this->assertSame(0, Money::parse('0'));
        $this->assertSame(-1500, Money::parse('-1,500'));
    }

    #[Test]
    public function parse_rejects_fractions_instead_of_rounding_them(): void
    {
        $this->assertNull(Money::parse('10.5'));
        $this->assertNull(Money::parse('10.01'));
        $this->assertNull(Money::parse(10.5));
        $this->assertNull(Money::parse('10٫5'));
    }

    #[Test]
    public function parse_accepts_trailing_zero_fractions_but_rejects_ambiguous_thousand_dots(): void
    {
        $this->assertSame(50, Money::parse('50.0'));
        $this->assertSame(50, Money::parse('50.00'));
        // «50.000» می‌تواند پنجاه‌هزار باشد؛ مبهم است و رد می‌شود
        $this->assertNull(Money::parse('50.000'));
    }

    #[Test]
    public function parse_rejects_garbage_and_overflow(): void
    {
        foreach (['', ' ', 'abc', '12abc', '1e5', '--5', '5.', '.', null, [], true] as $bad) {
            $this->assertNull(Money::parse($bad), 'باید رد شود: '.var_export($bad, true));
        }

        $this->assertNull(Money::parse('9999999999999999999'));
        $this->assertNull(Money::parse(NAN));
        $this->assertNull(Money::parse(INF));
    }

    #[Test]
    public function parse_loose_ignores_non_numeric_noise_but_still_rejects_fractions(): void
    {
        $this->assertSame(50000, Money::parseLoose('50,000 تومان'));
        $this->assertSame(50000, Money::parseLoose('۵۰۰۰۰'));
        $this->assertSame(50000, Money::parseLoose('مبلغ: 50000 ریال؟'));
        $this->assertNull(Money::parseLoose('تومان'));
        $this->assertNull(Money::parseLoose(''));
        $this->assertNull(Money::parseLoose('10.5 تومان'));
    }

    #[Test]
    public function parse_or_zero_and_to_minor(): void
    {
        $this->assertSame(0, Money::parseOrZero('abc'));
        $this->assertSame(0, Money::parseOrZero('10.5'));
        $this->assertSame(0, Money::parseOrZero(null));
        $this->assertSame(25000, Money::parseOrZero('25,000 تومان'));
        $this->assertSame(1200, Money::toMinor('1200'));

        $this->expectException(InvalidArgumentException::class);
        Money::toMinor('12.5');
    }

    #[Test]
    public function int_reads_stored_minor_units_without_scaling(): void
    {
        $this->usd(); // حتی با decimals=2، int() ضرب نمی‌کند

        $this->assertSame(1500, Money::int(1500));
        $this->assertSame(1500, Money::int('1500'));
        $this->assertSame(1500, Money::int('1500.00'));
        $this->assertSame(1500, Money::int(1500.0));
        $this->assertSame(0, Money::int('15.5'));
        $this->assertSame(0, Money::int('abc'));
        $this->assertSame(0, Money::int(null));
    }

    // ── نمایش (IRT) ─────────────────────────────────────────────

    #[Test]
    public function toman_formatting(): void
    {
        $this->assertSame('IRT', Money::code());
        $this->assertSame(0, Money::decimals());
        $this->assertSame(1, Money::factor());
        $this->assertSame('1', Money::inputStep());
        $this->assertSame('تومان', Money::label());

        $this->assertSame('0', Money::number(0));
        $this->assertSame('1,250,000', Money::number(1250000));
        $this->assertSame('-1,500', Money::number(-1500));
        $this->assertSame('1,250,000 تومان', Money::format(1250000));
        $this->assertSame('1250000', Money::toMajorString(1250000));
        $this->assertSame('-15', Money::toMajorString(-15));
    }

    // ── ارز دلاری (decimals=2) ──────────────────────────────────

    #[Test]
    public function usd_parse_scales_to_cents_and_rejects_extra_precision(): void
    {
        $this->usd();

        $this->assertSame(1000, Money::parse('10'));
        $this->assertSame(550, Money::parse('5.5'));
        $this->assertSame(550, Money::parse('5.50'));
        $this->assertSame(550, Money::parse('5٫5'));
        $this->assertSame(125075, Money::parse('1,250.75'));
        $this->assertSame(300, Money::parse(3));
        $this->assertSame(550, Money::parse(5.5));
        $this->assertSame(5, Money::parse('0.05'));
        $this->assertSame(5, Money::parse('.05'));

        $this->assertNull(Money::parse('5.555'));
        $this->assertNull(Money::parse('5.001'));
        $this->assertSame(550, Money::parse('5.500')); // صفرِ اضافه مجاز است
    }

    #[Test]
    public function usd_formatting_and_form_helpers(): void
    {
        $this->usd();

        $this->assertSame(100, Money::factor());
        $this->assertSame('0.01', Money::inputStep());
        $this->assertSame('1,250.50', Money::number(125050));
        $this->assertSame('0.05', Money::number(5));
        $this->assertSame('-3.07', Money::number(-307));
        $this->assertSame('$1,250.50', Money::format(125050));
        $this->assertSame('1250.50', Money::toMajorString(125050));
        $this->assertSame('0.05', Money::toMajorString(5));
    }

    #[Test]
    public function alphabetic_label_gets_a_space_and_position_is_respected(): void
    {
        $this->usd('after', 'USD');
        $this->assertSame('10.00 USD', Money::format(1000));

        $this->usd('before', 'USD');
        $this->assertSame('USD 10.00', Money::format(1000));
    }

    // ── محاسبه ──────────────────────────────────────────────────

    #[Test]
    public function percent_of_is_integer_half_up(): void
    {
        $this->assertSame(25, Money::percentOf(1000, '2.5'));
        $this->assertSame(25, Money::percentOf(1000, '2.50'));
        $this->assertSame(3, Money::percentOf(101, '2.5'));  // 2.525 → 3
        $this->assertSame(2, Money::percentOf(99, '2.5'));   // 2.475 → 2
        $this->assertSame(5, Money::percentOf(100, 5));
        $this->assertSame(0, Money::percentOf(1, '0.01'));
        $this->assertSame(-25, Money::percentOf(-1000, '2.5'));
    }

    #[Test]
    public function basis_points_are_exact_and_reject_silent_truncation(): void
    {
        $this->assertSame(250, Money::basisPoints('2.5'));
        $this->assertSame(250, Money::basisPoints('2.50'));
        $this->assertSame(250, Money::basisPoints('2.5000'));
        $this->assertSame(1000, Money::basisPoints(10));
        $this->assertSame(1, Money::basisPoints('0.01'));

        foreach (['2.555', 'abc', '-1', '', '1,5'] as $bad) {
            try {
                Money::basisPoints($bad);
                $this->fail('باید Exception می‌داد: '.$bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── تنظیمات شارژ ────────────────────────────────────────────

    #[Test]
    public function topup_settings_are_read_in_major_units_and_returned_as_minor(): void
    {
        $this->assertSame(10000, Money::minTopup());
        $this->assertSame(10000, Money::minTopup('customer'));
        $this->assertSame(100000, Money::minTopup('supply'));
        $this->assertSame([200000, 500000, 1000000], Money::topupPresets('customer'));
        $this->assertSame([1000000, 5000000], Money::topupPresets('supply'));

        $this->usd();
        config([
            'melorin.currency.topup.min' => '5',
            'melorin.currency.topup.presets' => '10, 25 ,50,abc,0,-3',
        ]);

        $this->assertSame(500, Money::minTopup());
        $this->assertSame([1000, 2500, 5000], Money::topupPresets('customer'));
    }

    #[Test]
    public function an_invalid_min_topup_fails_loudly_instead_of_becoming_zero(): void
    {
        config(['melorin.currency.topup.min' => '10000.5']); // اعشار برای ارز بدون اعشار

        $this->expectException(InvalidArgumentException::class);
        Money::minTopup();
    }

    #[Test]
    public function reseller_topup_keyboard_follows_the_currency(): void
    {
        $this->usd();
        config(['melorin.currency.topup.presets' => '10,25']);

        $keyboard = json_decode(Keyboards::walletTopupAmounts(), true)['inline_keyboard'];
        $buttons = array_merge(...$keyboard);

        $this->assertSame('$10.00', $buttons[0]['text']);
        $this->assertSame('rwallet:amount:1000', $buttons[0]['callback_data']);
        $this->assertSame('$25.00', $buttons[1]['text']);
        $this->assertSame('rwallet:amount:2500', $buttons[1]['callback_data']);
        $this->assertSame('rwallet:amount:custom', $buttons[2]['callback_data']);
    }

    // ── درگاه ───────────────────────────────────────────────────

    #[Test]
    public function to_rial_is_explicit_and_only_for_iranian_currencies(): void
    {
        $this->assertSame(10000, Money::toRial(1000)); // IRT ×۱۰

        config(['melorin.currency.code' => 'IRR']);
        $this->assertSame(1000, Money::toRial(1000));  // IRR ×۱

        $this->usd();
        $this->expectException(RuntimeException::class);
        Money::toRial(1000);
    }
}
