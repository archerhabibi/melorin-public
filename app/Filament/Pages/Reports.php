<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * گزارشات و آمار جامع (بند ۱۷ سند نیازمندی).
 *
 * تا پیش از این، تنها آمار موجود دو ویجت ثابت روی داشبورد بود: خلاصه‌ی
 * فروش و نمودار ۱۴ روز اخیر. هیچ راهی برای پاسخ به سؤالات واقعیِ
 * مدیریتی وجود نداشت — «ماه گذشته کدام سرور بیشترین فروش را داشت؟»،
 * «سود خالص ما از هر نماینده چقدر بوده؟»، «کدام محصول اصلاً فروش
 * ندارد؟»، «نرخ شکست ساخت اکانت روی کدام پنل بالاست؟».
 *
 * این صفحه همه را با یک بازه‌ی زمانی قابل‌انتخاب پوشش می‌دهد. تمام
 * محاسبات با aggregate در دیتابیس انجام می‌شود (نه واکشی رکوردها و
 * جمع‌زدن در PHP) تا با رشد تعداد سفارش‌ها کند نشود.
 */
class Reports extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'گزارشات';

    protected static ?string $navigationLabel = 'گزارشات و آمار';

    protected static ?string $title = 'گزارشات و آمار';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.reports';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'range' => 'this_month',
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(3)->schema([
                    Select::make('range')
                        ->label('بازه‌ی زمانی')
                        ->options([
                            'today' => 'امروز',
                            'last_7' => '۷ روز اخیر',
                            'last_30' => '۳۰ روز اخیر',
                            'this_month' => 'ماه جاری',
                            'last_month' => 'ماه گذشته',
                            'this_year' => 'سال جاری',
                            'custom' => 'بازه‌ی دلخواه',
                        ])
                        ->default('this_month')
                        ->live()
                        ->afterStateUpdated(fn () => $this->applyPreset()),

                    DatePicker::make('from')
                        ->label('از تاریخ')
                        ->live()
                        ->disabled(fn () => ($this->data['range'] ?? null) !== 'custom'),

                    DatePicker::make('to')
                        ->label('تا تاریخ')
                        ->live()
                        ->disabled(fn () => ($this->data['range'] ?? null) !== 'custom'),
                ]),
            ])
            ->statePath('data');
    }

    /** با تغییر بازه‌ی از پیش تعریف‌شده، تاریخ‌ها خودکار پر می‌شوند */
    public function applyPreset(): void
    {
        [$from, $to] = match ($this->data['range'] ?? 'this_month') {
            'today' => [today(), today()],
            'last_7' => [today()->subDays(6), today()],
            'last_30' => [today()->subDays(29), today()],
            'this_month' => [now()->startOfMonth(), today()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [now()->startOfYear(), today()],
            default => [
                Carbon::parse($this->data['from'] ?? now()->startOfMonth()),
                Carbon::parse($this->data['to'] ?? today()),
            ],
        };

        $this->data['from'] = $from->toDateString();
        $this->data['to'] = $to->toDateString();
    }

    protected function from(): Carbon
    {
        return Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
    }

    protected function to(): Carbon
    {
        return Carbon::parse($this->data['to'] ?? today())->endOfDay();
    }

    /** سفارش‌های موفقِ داخل بازه — مبنای تقریباً همه‌ی گزارش‌های مالی */
    protected function paidOrders(): Builder
    {
        return Order::query()
            ->whereIn('status', ['paid', 'account_created'])
            ->whereBetween('created_at', [$this->from(), $this->to()]);
    }

    /**
     * کارت‌های خلاصه‌ی بالای صفحه. «سود خالص» تفاوت بین چیزی است که
     * مشتری پرداخت کرده (main_price یا customers_price، هرکدام که پر
     * است) و قیمتِ پایه‌ی همان سفارش (main_price یا reseller_price) —
     * که برای فروش نمایندگی یعنی سود نماینده، و برای فروش مستقیم صفر
     * است چون هردو عدد یکی‌اند (بند ۳۱ سند).
     */
    public function getSummary(): array
    {
        $orders = $this->paidOrders();

        $revenue = (float) (clone $orders)->sum('main_price') + (float) (clone $orders)->sum('customers_price');
        $baseCost = (float) (clone $orders)->sum('main_price') + (float) (clone $orders)->sum('reseller_price');
        $count = (clone $orders)->count();

        $resellerRevenue = (float) (clone $orders)->whereNotNull('reseller_id')->sum('customers_price');
        $directRevenue = $revenue - $resellerRevenue;

        $newUsers = User::query()->whereBetween('created_at', [$this->from(), $this->to()])->count();

        $newAccounts = Account::query()
            ->whereBetween('created_at', [$this->from(), $this->to()])
            ->where('is_test', false)
            ->count();

        $testAccounts = Account::query()
            ->whereBetween('created_at', [$this->from(), $this->to()])
            ->where('is_test', true)
            ->count();

        $failedOrders = Order::query()
            ->where('status', 'failed')
            ->whereBetween('created_at', [$this->from(), $this->to()])
            ->count();

        $confirmedPayments = Payment::query()
            ->where('status', 'confirmed')
            ->whereBetween('created_at', [$this->from(), $this->to()])
            ->sum('amount');

        $pendingPayments = Payment::query()->where('status', 'pending')->whereBetween('created_at', [$from, $to])->count();

        return [
            'revenue' => (float) $revenue,
            'base_cost' => (float) $baseCost,
            'gross_margin' => (float) $revenue - (float) $baseCost,
            'orders' => $count,
            'average_order' => $count > 0 ? (float) $revenue / $count : 0.0,
            'direct_revenue' => (float) $directRevenue,
            'reseller_revenue' => (float) $resellerRevenue,
            'new_users' => $newUsers,
            'new_accounts' => $newAccounts,
            'test_accounts' => $testAccounts,
            'failed_orders' => $failedOrders,
            'failure_rate' => ($count + $failedOrders) > 0
                ? round($failedOrders / ($count + $failedOrders) * 100, 1)
                : 0.0,
            'confirmed_payments' => (float) $confirmedPayments,
            'pending_payments' => $pendingPayments,
        ];
    }

    /** روند روزانه‌ی فروش و تعداد سفارش در بازه — برای نمودار و جدول روند */
    public function getDailyTrend(): Collection
    {
        return $this->paidOrders()
            ->selectRaw('DATE(created_at) as day,
                SUM(COALESCE(main_price, customers_price, 0)) as revenue,
                SUM(COALESCE(customers_price, 0) - COALESCE(reseller_price, 0)) as margin,
                COUNT(*) as orders')
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    /** فروش به تفکیک محصول — شامل محصولاتی که اصلاً فروش نداشته‌اند نیست */
    public function getByProduct(): Collection
    {
        return $this->paidOrders()
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->selectRaw('products.name as name, COUNT(*) as orders,
                SUM(COALESCE(orders.main_price, orders.customers_price, 0)) as revenue,
                SUM(COALESCE(orders.customers_price, 0) - COALESCE(orders.reseller_price, 0)) as margin')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('revenue')
            ->get();
    }

    /** فروش به تفکیک سبد فروش */
    public function getByCategory(): Collection
    {
        return $this->paidOrders()
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw('categories.name as name, COUNT(*) as orders, SUM(COALESCE(orders.main_price, orders.customers_price, 0)) as revenue')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('revenue')
            ->get();
    }

    /**
     * عملکرد هر سرور/پنل. برخلاف بقیه‌ی گزارش‌ها که بر مبنای Order
     * هستند، این یکی از Account شروع می‌کند — چون سرور در لحظه‌ی ساخت
     * اکانت انتخاب می‌شود، نه در لحظه‌ی ثبت سفارش.
     */
    public function getByServer(): Collection
    {
        return Account::query()
            ->join('server_panels', 'server_panels.id', '=', 'accounts.server_panel_id')
            ->whereBetween('accounts.created_at', [$this->from(), $this->to()])
            ->selectRaw('server_panels.name as name, server_panels.panel_type as panel_type,
                COUNT(*) as accounts,
                SUM(CASE WHEN accounts.is_test = 1 THEN 1 ELSE 0 END) as test_accounts,
                SUM(CASE WHEN accounts.status = "active" THEN 1 ELSE 0 END) as active_accounts')
            ->groupBy('server_panels.id', 'server_panels.name', 'server_panels.panel_type')
            ->orderByDesc('accounts')
            ->get();
    }

    /**
     * عملکرد نمایندگان. «سود پلتفرم» اینجا یعنی مبلغی که ما از نماینده
     * گرفته‌ایم (reseller_price)، نه مبلغی که مشتریِ نماینده پرداخت
     * کرده (customers_price) — آن تفاوت، سودِ خودِ نماینده است و درآمد
     * ما نیست. چون این کوئری با whereNotNull('reseller_id') محدود شده،
     * همه‌ی سفارش‌ها در Context نماینده‌اند، پس نیازی به COALESCE نیست.
     */
    public function getByReseller(): Collection
    {
        return $this->paidOrders()
            ->whereNotNull('orders.reseller_id')
            ->join('resellers', 'resellers.id', '=', 'orders.reseller_id')
            ->join('users', 'users.id', '=', 'resellers.user_id')
            ->selectRaw('users.full_name as name, resellers.slug as slug,
                COUNT(*) as orders,
                SUM(orders.reseller_price) as platform_revenue,
                SUM(orders.customers_price) as customer_paid,
                SUM(orders.customers_price - orders.reseller_price) as reseller_profit')
            ->groupBy('resellers.id', 'users.full_name', 'resellers.slug')
            ->orderByDesc('platform_revenue')
            ->get();
    }

    /** پرداخت‌ها به تفکیک روش — برای فهمیدن اینکه کدام درگاه واقعاً استفاده می‌شود */
    public function getByPaymentMethod(): Collection
    {
        return Payment::query()
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->whereBetween('payments.created_at', [$this->from(), $this->to()])
            ->selectRaw('payment_methods.name as name,
                COUNT(*) as total,
                SUM(CASE WHEN payments.status = "confirmed" THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN payments.status = "rejected" THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN payments.status = "confirmed" THEN payments.amount ELSE 0 END) as amount')
            ->groupBy('payment_methods.id', 'payment_methods.name')
            ->orderByDesc('amount')
            ->get();
    }

    /** پرفروش‌ترین مشتریان — برای شناسایی کاربران ارزشمند */
    public function getTopCustomers(): Collection
    {
        return $this->paidOrders()
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->selectRaw('users.full_name as name, users.telegram_id as telegram_id, COUNT(*) as orders, SUM(COALESCE(orders.main_price, orders.customers_price, 0)) as spent')
            ->groupBy('users.id', 'users.full_name', 'users.telegram_id')
            ->orderByDesc('spent')
            ->limit(10)
            ->get();
    }

    /**
     * اکانت‌هایی که در ۷ روز آینده منقضی می‌شوند — تنها گزارشِ این صفحه
     * که به بازه‌ی انتخابی وابسته نیست، چون ماهیتاً آینده‌نگر است و
     * کاربرد عملیاتی دارد (فرصت تمدید).
     */
    public function getExpiringSoon(): Collection
    {
        return Account::query()
            ->join('users', 'users.id', '=', 'accounts.user_id')
            ->where('accounts.status', 'active')
            ->where('accounts.is_test', false)
            ->whereBetween('accounts.expires_at', [now(), now()->addDays(7)])
            ->selectRaw('users.full_name as name, users.telegram_id as telegram_id, accounts.panel_username as username, accounts.expires_at as expires_at')
            ->orderBy('accounts.expires_at')
            ->limit(25)
            ->get();
    }
}
