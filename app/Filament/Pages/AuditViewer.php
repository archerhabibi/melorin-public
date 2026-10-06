<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

class AuditViewer extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'گزارشات';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.audit-viewer';

    protected static ?string $title = 'نمایش Audit';

    protected static ?string $navigationLabel = 'نمایش Audit';

    public string $search = '';

    public string $actorType = 'all';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        $this->dateFrom = now()->subDays(30)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    public function getLogs(): Collection
    {
        return AuditLog::query()
            ->when($this->actorType !== 'all', fn ($query) => $query->where('actor_type', $this->actorType))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($term) {
                    $query->where('action', 'like', $term)
                        ->orWhere('target_type', 'like', $term)
                        ->orWhere('target_id', 'like', $term)
                        ->orWhere('actor_id', 'like', $term);
                });
            })
            ->when($this->dateFrom !== '', fn ($query) => $query->where('created_at', '>=', $this->dateFrom.' 00:00:00'))
            ->when($this->dateTo !== '', fn ($query) => $query->where('created_at', '<=', $this->dateTo.' 23:59:59'))
            ->latest('created_at')
            ->limit(200)
            ->get();
    }

    public function getSummary(): array
    {
        $logs = $this->getLogs();

        return [
            'total' => $logs->count(),
            'admin' => $logs->where('actor_type', 'admin')->count(),
            'reseller' => $logs->where('actor_type', 'reseller')->count(),
            'system' => $logs->where('actor_type', 'system')->count(),
        ];
    }

    public static function actorLabel(string $type): string
    {
        return match ($type) {
            'admin' => 'ادمین',
            'reseller' => 'نماینده',
            'customer' => 'کاربر',
            default => 'سیستم',
        };
    }

    public static function targetLabel(?string $type): string
    {
        return $type ? class_basename($type) : '—';
    }

    public static function jsonValue(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '—';
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
