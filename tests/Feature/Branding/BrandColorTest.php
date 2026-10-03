<?php

namespace Tests\Feature\Branding;

use App\Support\Branding\BrandColor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B1.4 — contrast خودکار برای رنگ Brand نماینده.
 */
class BrandColorTest extends TestCase
{
    #[Test]
    public function dark_and_saturated_brands_get_white_text_and_light_brands_get_dark_text(): void
    {
        $this->assertSame('#ffffff', BrandColor::onColor('#2563eb'));
        $this->assertSame('#ffffff', BrandColor::onColor('#000000'));
        $this->assertSame(BrandColor::DARK_TEXT, BrandColor::onColor('#ffff00'));
        $this->assertSame(BrandColor::DARK_TEXT, BrandColor::onColor('#ffffff'));
    }

    #[Test]
    public function the_chosen_text_color_always_meets_a_minimum_contrast(): void
    {
        foreach (['#2563eb', '#ffff00', '#ff0055', '#00aa00', '#777777', '#fafafa', '#111111'] as $brand) {
            $on = BrandColor::onColor($brand);

            $this->assertGreaterThanOrEqual(3.0, BrandColor::contrast($brand, $on), "contrast too low for {$brand}");
        }
    }

    #[Test]
    public function a_light_brand_is_darkened_until_white_text_is_readable_and_a_good_brand_is_untouched(): void
    {
        $this->assertSame('#2563eb', BrandColor::readableWithWhite('#2563eb'));

        $adjusted = BrandColor::readableWithWhite('#ffff00');
        $this->assertNotSame('#ffff00', $adjusted);
        $this->assertGreaterThanOrEqual(3.0, BrandColor::contrast($adjusted, '#ffffff'));
    }

    #[Test]
    public function invalid_input_falls_back_to_the_default_brand(): void
    {
        $this->assertSame(BrandColor::DEFAULT, BrandColor::normalize('red;}a{'));
        $this->assertSame('#ffffff', BrandColor::onColor('not-a-color'));
    }
}
