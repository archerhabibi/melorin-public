<?php

namespace App\Filament\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\ResellerResource;
use App\Models\Order;
use App\Models\Reseller;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

class ResellerOversight extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'نمایندگان';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.reseller-oversight';

    protected static ?string $title = 'نظارت بر نمایندگان';

    protected static ?string $navigationLabel = 'نظارت بر نمایندگان';

    public string $status = 'all';

    public string $search = '';

    public function getResellers(): Collection
    {
        return Reseller::query()
            ->with(['user:id,full_name,email', 'wallet:id,user_id,reseller_id,balance'])
            ->withCount('customers')
            ->withCount(['orders as completed_orders_count' => fn ($query) => $query->whereIn('status', [Order::STATUS_PAID, Order::STATUS_ACCOUNT_CREATED])])
            ->withSum(['orders as platform_revenue' => fn ($query) => $query->whereIn('status', [Order::STATUS_PAID, Order::STATUS_ACCOUNT_CREATED])], 'reseller_price')
            ->withSum(['orders as customer_revenue' => fn ($query) => $query->whereIn('status', [Order::STATUS_PAID, Order::STATUS_ACCOUNT_CREATED])], 'customers_price')
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($term) {
                    $query->where('slug', 'like', $term)
                        ->orWhereHas('user', fn ($query) => $query->where('full_name', 'like', $term)->orWhere('email', 'like', $term));
                });
            })
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    public function getSummary(): array
    {
        $query = Reseller::query()
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($term) {
                    $query->where('slug', 'like', $term)
                        ->orWhereHas('user', fn ($query) => $query->where('full_name', 'like', $term)->orWhere('email', 'like', $term));
                });
            });

        $ids = (clone $query)->pluck('id');
        $orders = Order::query()->whereIn('reseller_id', $ids)->whereIn('status', [Order::STATUS_PAID, Order::STATUS_ACCOUNT_CREATED]);

        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'customers' => Reseller::query()->whereIn('id', $ids)->withCount('customers')->get()->sum('customers_count'),
            'platform_revenue' => (int) (clone $orders)->sum('reseller_price'),
            'customer_revenue' => (int) (clone $orders)->sum('customers_price'),
            'failed_orders' => Order::query()->whereIn('reseller_id', $ids)->where('status', Order::STATUS_PROVISION_FAILED)->count(),
        ];
    }

    public function getRecentActivity(): Collection
    {
        return Order::query()
            ->with(['reseller.user:id,full_name', 'product:id,name'])
            ->whereNotNull('reseller_id')
            ->latest('updated_at')
            ->limit(20)
            ->get();
    }

    public static function resellerUrl(Reseller $reseller): string
    {
        return ResellerResource::getUrl('edit', ['record' => $reseller]);
    }

    public static function orderUrl(Order $order): string
    {
        return OrderResource::getUrl('view', ['record' => $order]);
    }

    public static function statusLabel(string $status): string
    {
        return $status === 'active' ? 'فعال' : 'غیرفعال';
    }

    public static function orderStatusLabel(string $status): string
    {
        return Order::statusLabels()[$status] ?? $status;
    }

    public static function money(int|float|null $value): string
    {
        return number_format((int) ($value ?? 0)).' ریال';
    }
}
