<?php

namespace App\Services\Resellers\Branding;

use App\Models\ResellerWebsiteSetting;
use App\Support\Branding\BrandColor;

/**
 * «فروشگاه من چقدر برند خودم را دارد؟» — چک‌لیست خالص و فقط‌خواندنی (B5.7).
 *
 * هر مورد فقط وقتی انجام‌شده است که نماینده خودش مقدار داده باشد (مقدار پیش‌فرض/Fallback حساب نمی‌شود).
 * `indexingReady` پیش‌نیازِ معقول برای روشن‌کردن ایندکس است: نامِ اختصاصی + یک متن معرفی (درباره یا توضیح متا)؛
 * بدون آن فروشگاه با نام فنی «نمایندگی slug» در نتایج جست‌وجو می‌آمد. این فقط **راهنما** است، قفل نیست.
 */
final class BrandingReadiness
{
    /** @param  array<string, array{label: string, done: bool}>  $items */
    private function __construct(public readonly array $items) {}

    public static function of(?ResellerWebsiteSetting $setting): self
    {
        $filled = fn (?string $v): bool => $v !== null && trim($v) !== '';

        return new self([
            'name' => ['label' => 'نام نمایشی اختصاصی', 'done' => $filled($setting?->display_name)],
            'logo' => ['label' => 'لوگو', 'done' => $filled($setting?->logo_path)],
            'color' => [
                'label' => 'رنگ اصلی برند',
                'done' => $filled($setting?->brand_color) && BrandColor::normalize((string) $setting->brand_color) !== BrandColor::DEFAULT,
            ],
            'contact' => ['label' => 'راه ارتباطی (تلفن یا ایمیل)', 'done' => $filled($setting?->contact_phone) || $filled($setting?->contact_email)],
            'about' => ['label' => 'متن معرفی فروشگاه', 'done' => $filled($setting?->about_text)],
        ]);
    }

    public function doneCount(): int
    {
        return count(array_filter($this->items, fn (array $i): bool => $i['done']));
    }

    public function total(): int
    {
        return count($this->items);
    }

    /** درصد صحیح (۰..۱۰۰). */
    public function percent(): int
    {
        return intdiv($this->doneCount() * 100, max(1, $this->total()));
    }

    /** @return list<string> برچسب مواردِ انجام‌نشده */
    public function missing(): array
    {
        return array_values(array_map(
            fn (array $i): string => $i['label'],
            array_filter($this->items, fn (array $i): bool => ! $i['done']),
        ));
    }

    public function indexingReady(): bool
    {
        return $this->items['name']['done'] && $this->items['about']['done'];
    }
}
