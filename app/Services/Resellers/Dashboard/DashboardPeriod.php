<?php

namespace App\Services\Resellers\Dashboard;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * بازه‌ی زمانی داشبورد نماینده (B5.1).
 *
 * همه‌ی بازه‌ها «نیمه‌باز»اند: [شروع، پایان) — یعنی created_at >= شروع و < پایان.
 * مرز روز/ماه طبق منطقه‌ی زمانی برنامه (APP_TIMEZONE) است، همان که created_at با آن ذخیره می‌شود.
 *
 * هر بازه یک «بازه‌ی قبلی هم‌طول» دارد تا KPIها با دوره‌ی قبل مقایسه شوند:
 *  - امروز ⇒ دیروز · ۷/۳۰ روز اخیر ⇒ همان‌قدر روز قبل‌تر
 *  - این ماه (تا امروز) ⇒ همان تعداد روزِ ماه قبل (سقف: پایان ماه قبل)
 *  - ماه قبل ⇒ ماهِ قبل‌تر
 *
 * مقدار نامعتبر هیچ‌وقت خطا نمی‌دهد: به پیش‌فرض (۳۰ روز اخیر) برمی‌گردد.
 */
enum DashboardPeriod: string
{
    case Today = 'today';
    case Last7Days = '7d';
    case Last30Days = '30d';
    case ThisMonth = 'month';
    case LastMonth = 'last_month';

    public const DEFAULT = self::Last30Days;

    /** @return array<string, string> */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::DEFAULT) : self::DEFAULT;
    }

    public function label(): string
    {
        return match ($this) {
            self::Today => 'امروز',
            self::Last7Days => '۷ روز اخیر',
            self::Last30Days => '۳۰ روز اخیر',
            self::ThisMonth => 'این ماه',
            self::LastMonth => 'ماه گذشته',
        };
    }

    /** برچسب بازه‌ی مقایسه، برای متن توضیح KPI */
    public function comparisonLabel(): string
    {
        return match ($this) {
            self::Today => 'نسبت به دیروز',
            self::Last7Days => 'نسبت به ۷ روز قبل‌تر',
            self::Last30Days => 'نسبت به ۳۰ روز قبل‌تر',
            self::ThisMonth => 'نسبت به همین روزهای ماه قبل',
            self::LastMonth => 'نسبت به ماه قبل‌تر',
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [شروع، پایانِ انحصاری]
     */
    public function range(CarbonInterface $now): array
    {
        $today = CarbonImmutable::instance($now)->startOfDay();
        $tomorrow = $today->addDay();

        return match ($this) {
            self::Today => [$today, $tomorrow],
            self::Last7Days => [$tomorrow->subDays(7), $tomorrow],
            self::Last30Days => [$tomorrow->subDays(30), $tomorrow],
            self::ThisMonth => [$today->startOfMonth(), $tomorrow],
            self::LastMonth => [$today->startOfMonth()->subMonth(), $today->startOfMonth()],
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function previousRange(CarbonInterface $now): array
    {
        [$start, $end] = $this->range($now);

        return match ($this) {
            self::Today => [$start->subDay(), $start],
            self::Last7Days => [$start->subDays(7), $start],
            self::Last30Days => [$start->subDays(30), $start],
            self::ThisMonth => $this->previousMonthToDate($start, $end),
            self::LastMonth => [$start->subMonth(), $start],
        };
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function previousMonthToDate(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $previousStart = $start->subMonth();

        // «همان تعداد روز» از ماه قبل؛ ماه قبل کوتاه‌تر باشد (مثلاً ۳۱ ام) به پایان آن ماه سقف می‌خورد.
        $elapsedDays = (int) $end->subDay()->day;
        $previousEnd = $previousStart->addDays($elapsedDays);

        return [$previousStart, $previousEnd->greaterThan($start) ? $start : $previousEnd];
    }
}
