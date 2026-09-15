<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * مشاهده‌ی سوابق عملیات حساس (بند ۳۱ سند نیازمندی / P2 #26).
 * فقط خواندنی — لاگ‌ها هرگز نباید ویرایش یا حذف شوند.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'گزارشات';

    protected static ?string $navigationLabel = 'سوابق عملیات';

    protected static ?string $modelLabel = 'سابقه';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('زمان')->dateTime('Y-m-d H:i:s')->sortable(),
                Tables\Columns\BadgeColumn::make('actor_type')
                    ->label('عامل')
                    ->colors(['primary' => 'admin', 'warning' => 'reseller', 'gray' => 'system'])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'admin' => 'ادمین', 'reseller' => 'نماینده', default => 'سیستم',
                    }),
                Tables\Columns\TextColumn::make('actor_id')->label('شناسه عامل'),
                Tables\Columns\TextColumn::make('action')->label('عملیات')->searchable()->badge(),
                Tables\Columns\TextColumn::make('target_type')
                    ->label('موضوع')
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '—'),
                Tables\Columns\TextColumn::make('target_id')->label('شناسه موضوع'),
                Tables\Columns\TextColumn::make('ip_address')->label('IP')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('actor_type')->label('عامل')->options([
                    'admin' => 'ادمین', 'reseller' => 'نماینده', 'system' => 'سیستم',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
