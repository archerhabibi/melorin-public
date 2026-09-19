@php
    $summary = $this->getSummary();
    $money = fn ($v) => number_format((float) $v).' تومان';
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @php $s = $summary; @endphp

    {{-- کارت‌های خلاصه --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">درآمد کل</div>
            <div class="mt-1 text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $money($s['revenue']) }}</div>
            <div class="mt-1 text-xs text-gray-500">{{ number_format($s['orders']) }} سفارش موفق</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">میانگین هر سفارش</div>
            <div class="mt-1 text-2xl font-bold">{{ $money($s['average_order']) }}</div>
            <div class="mt-1 text-xs text-gray-500">حاشیه‌ی نمایندگان: {{ $money($s['reseller_margin']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">فروش مستقیم / نمایندگی</div>
            <div class="mt-1 text-lg font-bold">{{ $money($s['direct_revenue']) }}</div>
            <div class="mt-1 text-xs text-gray-500">نمایندگی: {{ $money($s['reseller_revenue']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">کاربران جدید</div>
            <div class="mt-1 text-2xl font-bold">{{ number_format($s['new_users']) }}</div>
            <div class="mt-1 text-xs text-gray-500">
                {{ number_format($s['new_accounts']) }} اکانت فروخته‌شده ·
                {{ number_format($s['test_accounts']) }} اکانت تست
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">پرداخت‌های تأییدشده</div>
            <div class="mt-1 text-2xl font-bold text-success-600">{{ $money($s['confirmed_payments']) }}</div>
            <div class="mt-1 text-xs {{ $s['pending_payments'] > 0 ? 'text-warning-600 font-semibold' : 'text-gray-500' }}">
                {{ number_format($s['pending_payments']) }} پرداخت در انتظار بررسی
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">سفارش‌های ناموفق</div>
            <div class="mt-1 text-2xl font-bold {{ $s['failed_orders'] > 0 ? 'text-danger-600' : '' }}">
                {{ number_format($s['failed_orders']) }}
            </div>
            <div class="mt-1 text-xs text-gray-500">نرخ شکست: {{ $s['failure_rate'] }}٪</div>
        </x-filament::section>
    </div>

    {{-- روند روزانه --}}
    @php $trend = $this->getDailyTrend(); @endphp
    <x-filament::section collapsible>
        <x-slot name="heading">📈 روند روزانه</x-slot>

        @if ($trend->isEmpty())
            <p class="text-sm text-gray-500">در این بازه سفارشی ثبت نشده است.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-gray-700">
                        <tr class="text-right">
                            <th class="p-2">تاریخ</th>
                            <th class="p-2">تعداد سفارش</th>
                            <th class="p-2">درآمد</th>
                            <th class="p-2">حاشیه‌ی نمایندگان</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($trend as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="p-2">{{ $row->day }}</td>
                                <td class="p-2">{{ number_format($row->orders) }}</td>
                                <td class="p-2 font-medium">{{ $money($row->revenue) }}</td>
                                <td class="p-2 text-gray-500">{{ $money($row->margin) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        {{-- محصولات --}}
        @php $byProduct = $this->getByProduct(); @endphp
        <x-filament::section collapsible>
            <x-slot name="heading">🏷️ فروش به تفکیک محصول</x-slot>
            @if ($byProduct->isEmpty())
                <p class="text-sm text-gray-500">داده‌ای وجود ندارد.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-gray-700">
                        <tr class="text-right">
                            <th class="p-2">محصول</th>
                            <th class="p-2">تعداد</th>
                            <th class="p-2">درآمد</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byProduct as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="p-2">{{ $row->name }}</td>
                                <td class="p-2">{{ number_format($row->orders) }}</td>
                                <td class="p-2 font-medium">{{ $money($row->revenue) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- سبدهای فروش --}}
        @php $byCategory = $this->getByCategory(); @endphp
        <x-filament::section collapsible>
            <x-slot name="heading">🗂️ فروش به تفکیک سبد فروش</x-slot>
            @if ($byCategory->isEmpty())
                <p class="text-sm text-gray-500">داده‌ای وجود ندارد.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-gray-700">
                        <tr class="text-right">
                            <th class="p-2">سبد فروش</th>
                            <th class="p-2">تعداد</th>
                            <th class="p-2">درآمد</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byCategory as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="p-2">{{ $row->name }}</td>
                                <td class="p-2">{{ number_format($row->orders) }}</td>
                                <td class="p-2 font-medium">{{ $money($row->revenue) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- سرورها --}}
        @php $byServer = $this->getByServer(); @endphp
        <x-filament::section collapsible>
            <x-slot name="heading">🖥️ عملکرد سرورها و پنل‌ها</x-slot>
            @if ($byServer->isEmpty())
                <p class="text-sm text-gray-500">در این بازه اکانتی ساخته نشده است.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-gray-700">
                        <tr class="text-right">
                            <th class="p-2">سرور</th>
                            <th class="p-2">نوع</th>
                            <th class="p-2">اکانت ساخته‌شده</th>
                            <th class="p-2">فعال</th>
                            <th class="p-2">تست</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byServer as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="p-2">{{ $row->name }}</td>
                                <td class="p-2 text-gray-500">{{ $row->panel_type }}</td>
                                <td class="p-2 font-medium">{{ number_format($row->accounts) }}</td>
                                <td class="p-2">{{ number_format($row->active_accounts) }}</td>
                                <td class="p-2 text-gray-500">{{ number_format($row->test_accounts) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- روش‌های پرداخت --}}
        @php $byMethod = $this->getByPaymentMethod(); @endphp
        <x-filament::section collapsible>
            <x-slot name="heading">💳 پرداخت‌ها به تفکیک روش</x-slot>
            @if ($byMethod->isEmpty())
                <p class="text-sm text-gray-500">داده‌ای وجود ندارد.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-gray-700">
                        <tr class="text-right">
                            <th class="p-2">روش</th>
                            <th class="p-2">تأیید</th>
                            <th class="p-2">رد</th>
                            <th class="p-2">مبلغ تأییدشده</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byMethod as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="p-2">{{ $row->name }}</td>
                                <td class="p-2 text-success-600">{{ number_format($row->confirmed) }}</td>
                                <td class="p-2 text-danger-600">{{ number_format($row->rejected) }}</td>
                                <td class="p-2 font-medium">{{ $money($row->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>

    {{-- نمایندگان --}}
    @php $byReseller = $this->getByReseller(); @endphp
    <x-filament::section collapsible>
        <x-slot name="heading">🏬 عملکرد نمایندگان</x-slot>
        @if ($byReseller->isEmpty())
            <p class="text-sm text-gray-500">در این بازه فروش نمایندگی ثبت نشده است.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-gray-700">
                        <tr class="text-right">
                            <th class="p-2">نماینده</th>
                            <th class="p-2">آدرس پنل</th>
                            <th class="p-2">تعداد فروش</th>
                            <th class="p-2">درآمد پلتفرم</th>
                            <th class="p-2">پرداختی مشتریان</th>
                            <th class="p-2">سود نماینده</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byReseller as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="p-2">{{ $row->name }}</td>
                                <td class="p-2 text-gray-500">{{ $row->slug }}</td>
                                <td class="p-2">{{ number_format($row->orders) }}</td>
                                <td class="p-2 font-medium text-primary-600">{{ $money($row->platform_revenue) }}</td>
                                <td class="p-2 text-gray-500">{{ $money($row->customer_paid) }}</td>
                                <td class="p-2 text-gray-500">{{ $money($row->reseller_profit) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        {{-- مشتریان برتر --}}
        @php $topCustomers = $this->getTopCustomers(); @endphp
        <x-filament::section collapsible>
            <x-slot name="heading">⭐ مشتریان برتر (۱۰ نفر)</x-slot>
            @if ($topCustomers->isEmpty())
                <p class="text-sm text-gray-500">داده‌ای وجود ندارد.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-gray-700">
                        <tr class="text-right">
                            <th class="p-2">کاربر</th>
                            <th class="p-2">شناسه تلگرام</th>
                            <th class="p-2">سفارش</th>
                            <th class="p-2">مجموع خرید</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topCustomers as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="p-2">{{ $row->name }}</td>
                                <td class="p-2 text-gray-500">{{ $row->telegram_id }}</td>
                                <td class="p-2">{{ number_format($row->orders) }}</td>
                                <td class="p-2 font-medium">{{ $money($row->spent) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- در آستانه انقضا --}}
        @php $expiring = $this->getExpiringSoon(); @endphp
        <x-filament::section collapsible>
            <x-slot name="heading">⏳ اکانت‌های در آستانه‌ی انقضا (۷ روز آینده)</x-slot>
            <p class="mb-2 text-xs text-gray-500">این بخش مستقل از بازه‌ی انتخابی است — فرصت تمدید.</p>
            @if ($expiring->isEmpty())
                <p class="text-sm text-gray-500">هیچ اکانتی در ۷ روز آینده منقضی نمی‌شود.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-gray-700">
                        <tr class="text-right">
                            <th class="p-2">کاربر</th>
                            <th class="p-2">شناسه تلگرام</th>
                            <th class="p-2">نام کاربری</th>
                            <th class="p-2">انقضا</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($expiring as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="p-2">{{ $row->name }}</td>
                                <td class="p-2 text-gray-500">{{ $row->telegram_id }}</td>
                                <td class="p-2 font-mono text-xs">{{ $row->username }}</td>
                                <td class="p-2 text-warning-600">{{ \Illuminate\Support\Carbon::parse($row->expires_at)->format('Y-m-d') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
