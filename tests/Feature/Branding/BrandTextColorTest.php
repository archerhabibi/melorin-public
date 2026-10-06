<?php

namespace Tests\Feature\Branding;

use App\Support\Branding\BrandColor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B6.2 — رنگ «متن/لینک» برند: رنگ دکمه همان انتخاب نماینده می‌ماند، ولی متن و لینک روی سطح روشن/تیره
 * همیشه contrast کافی (≥ ۴.۵) دارند.
 */
class BrandTextColorTest extends TestCase
{
    private const SAMPLES = ['#2563eb', '#ffff00', '#000080', '#ffffff', '#000000', '#111111', '#ff0055', '#00aa00', '#777777', '#fafafa'];

    #[Test]
    public function text_on_light_and_on_dark_always_meet_the_minimum_contrast(): void
    {
        foreach (self::SAMPLES as $hex) {
            $this->assertGreaterThanOrEqual(
                BrandColor::TEXT_MIN_CONTRAST,
                BrandColor::contrast(BrandColor::textOnLight($hex), BrandColor::LIGHT_SURFACE),
                "متن روی سطح روشن برای {$hex} خوانا نیست",
            );
            $this->assertGreaterThanOrEqual(
                BrandColor::TEXT_MIN_CONTRAST,
                BrandColor::contrast(BrandColor::textOnDark($hex), BrandColor::DARK_SURFACE),
                "متن روی سطح تیره برای {$hex} خوانا نیست",
            );
        }
    }

    #[Test]
    public function a_readable_color_is_returned_untouched_and_only_the_failing_side_changes(): void
    {
        // آبی پیش‌فرض روی سطح روشن کافی است ولی روی سطح تیره باید روشن‌تر شود.
        $this->assertSame('#2563eb', BrandColor::textOnLight('#2563eb'));
        $this->assertNotSame('#2563eb', BrandColor::textOnDark('#2563eb'));

        // زرد روی سطح تیره کافی است ولی روی سطح روشن باید تیره‌تر شود.
        $this->assertSame('#ffff00', BrandColor::textOnDark('#ffff00'));
        $this->assertNotSame('#ffff00', BrandColor::textOnLight('#ffff00'));
    }

    #[Test]
    public function the_adjustment_moves_in_the_right_direction_and_stays_minimal(): void
    {
        // روی سطح روشن فقط تیره‌تر می‌شود (روشنایی کمتر)؛ روی سطح تیره فقط روشن‌تر.
        $this->assertLessThan(BrandColor::luminance('#ffff00'), BrandColor::luminance(BrandColor::textOnLight('#ffff00')));
        $this->assertGreaterThan(BrandColor::luminance('#000080'), BrandColor::luminance(BrandColor::textOnDark('#000080')));

        // «حداقلی»: یک گام کمتر نباید کافی باشد (یعنی نزدیک‌ترین رنگ خوانا انتخاب شده نه سیاه مطلق).
        $adjusted = BrandColor::textOnLight('#ffff00');
        $this->assertNotSame('#000000', $adjusted);
        $this->assertLessThan(BrandColor::TEXT_MIN_CONTRAST + 1.0, BrandColor::contrast($adjusted, BrandColor::LIGHT_SURFACE));
    }

    #[Test]
    public function extreme_colors_terminate_at_black_or_white(): void
    {
        $this->assertSame('#737373', BrandColor::textOnLight('#ffffff'));
        $this->assertSame('#ffffff', BrandColor::textOnDark('#ffffff'));
        $this->assertSame('#000000', BrandColor::textOnLight('#000000'));
    }

    #[Test]
    public function invalid_input_falls_back_to_the_default_color_instead_of_leaking(): void
    {
        $this->assertSame(BrandColor::textOnLight(BrandColor::DEFAULT), BrandColor::textOnLight('red;}a{'));
        $this->assertSame(BrandColor::textOnDark(BrandColor::DEFAULT), BrandColor::textOnDark(''));
    }

    #[Test]
    public function mix_is_linear_and_clamped(): void
    {
        $this->assertSame('#000000', BrandColor::mix('#000000', '#ffffff', 0));
        $this->assertSame('#ffffff', BrandColor::mix('#000000', '#ffffff', 1));
        $this->assertSame('#808080', BrandColor::mix('#000000', '#ffffff', 0.5));
        $this->assertSame('#ffffff', BrandColor::mix('#000000', '#ffffff', 7));
        $this->assertSame('#000000', BrandColor::mix('#000000', '#ffffff', -3));
    }

    #[Test]
    public function the_report_flags_exactly_the_sides_that_need_adjustment(): void
    {
        $blue = BrandColor::report('#2563eb');
        $this->assertFalse($blue['light_adjusted']);
        $this->assertTrue($blue['dark_adjusted']);

        $yellow = BrandColor::report('#ffff00');
        $this->assertTrue($yellow['light_adjusted']);
        $this->assertFalse($yellow['dark_adjusted']);
        $this->assertSame(BrandColor::textOnLight('#ffff00'), $yellow['light_text']);
        $this->assertGreaterThanOrEqual(3.0, $yellow['button']);
    }
}
