@php
    use App\Support\Money;
    $s = $statement;
    $row = fn (string $label, string $value, string $key, bool $strong = false) => compact('label', 'value', 'key', 'strong');
    $lines = [
        $row('فروش قطعی ('.number_format($s->orders).' سفارش)', Money::format($s->revenue), 'revenue'),
        $row('هزینه‌ی تأمین از Melorin', Money::format($s->supplyCost), 'supply'),
        $row('سود ناخالص'.($s->grossMarginPercent() !== null ? ' ('.number_format($s->grossMarginPercent()).'٪)' : ''), Money::format($s->grossProfit()), 'gross', true),
        $row('کمیسیون داده‌شده به معرف‌ها', Money::format($s->commissionsGiven), 'commissions'),
        $row('پاداش معرفی داده‌شده', Money::format($s->bonusesGiven), 'bonuses'),
        $row('سود خالص تخمینی'.($s->netMarginPercent() !== null ? ' ('.number_format($s->netMarginPercent()).'٪)' : ''), Money::format($s->netProfit()), 'net', true),
    ];
@endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="'صورت‌حساب — '.$s->period->label()" description="بازه را از فیلتر جدول تغییر دهید" icon="heroicon-o-document-chart-bar">
        @if ($s->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400" data-statement="empty">در این بازه فروش یا حرکت مالی‌ای ثبت نشده است.</p>
        @else
            <div class="grid gap-6 md:grid-cols-2" data-statement="full">
                <table class="w-full text-start text-sm">
                    <tbody>
                        @foreach ($lines as $line)
                            <tr class="border-t border-gray-200 first:border-0 dark:border-white/10" data-line="{{ $line['key'] }}">
                                <td class="py-2 {{ $line['strong'] ? 'font-semibold' : '' }}">{{ $line['label'] }}</td>
                                <td class="py-2 text-end {{ $line['strong'] ? 'font-semibold' : '' }}">{{ $line['value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <table class="w-full text-start text-sm">
                    <tbody>
                        <tr data-line="customer-charges">
                            <td class="py-2">شارژ کیف‌پول مشتریان (دریافتی شما)</td>
                            <td class="py-2 text-end">{{ Money::format($s->customerCharges) }}</td>
                        </tr>
                        <tr class="border-t border-gray-200 dark:border-white/10" data-line="owner-charges">
                            <td class="py-2">شارژ اعتبار شما (پرداختی به Melorin)</td>
                            <td class="py-2 text-end">{{ Money::format($s->ownerCharges) }}</td>
                        </tr>
                        <tr class="border-t border-gray-200 dark:border-white/10" data-line="liability">
                            <td class="py-2">موجودی فعلی کیف‌پول مشتریان (تعهد فروشگاه)</td>
                            <td class="py-2 text-end">{{ Money::format($s->customerWalletBalances) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                سود خالص تخمینی: هدیه‌های کیف‌پولی (کمیسیون و پاداش) از سود ناخالص کم شده‌اند؛ هزینه‌ی واقعیِ آن‌ها وقتی است که مشتری خرجشان کند. این صفحه فقط گزارش است و برداشت یا تسویه‌ی پولی ندارد.
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
