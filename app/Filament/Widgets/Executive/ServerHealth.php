<?php

namespace App\Filament\Widgets\Executive;

use App\Filament\Resources\ServerPanelResource;
use App\Models\ServerPanel;
use App\Services\Admin\Dashboard\ExecutiveDashboardService;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** B7.1 — سلامت و ظرفیت سرورهای فعال؛ مشکل‌دارها اول (از کار افتاده ← کند ← پرمصرف‌ترین). */
class ServerHealth extends BaseWidget
{
    protected static ?string $heading = 'سلامت و ظرفیت سرورها';

    protected static ?int $sort = 40;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->query(app(ExecutiveDashboardService::class)->serverHealthQuery())
            ->paginated(false)
            ->emptyStateHeading('سرور فعالی ثبت نشده')
            ->headerActions([
                Tables\Actions\Action::make('all')
                    ->label('همه‌ی سرورها')
                    ->url(ServerPanelResource::getUrl('index'))
                    ->link(),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('سرور'),
                Tables\Columns\TextColumn::make('health_status')->label('سلامت')->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'healthy' => 'سالم',
                        'degraded' => 'کند',
                        'down' => 'از کار افتاده',
                        default => 'نامشخص',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'down' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('usage')->label('ظرفیت')
                    ->getStateUsing(fn (ServerPanel $record) => $record->capacity === null
                        ? number_format((int) $record->active_accounts_count).' / نامحدود'
                        : number_format((int) $record->active_accounts_count).' / '.number_format((int) $record->capacity))
                    ->badge()
                    ->color(fn (ServerPanel $record): string => $record->capacity !== null && (int) $record->active_accounts_count >= (int) $record->capacity
                        ? 'danger'
                        : 'gray'),
            ]);
    }
}
