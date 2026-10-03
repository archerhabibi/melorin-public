<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BroadcastResource\Pages;
use App\Models\Broadcast;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** سوابق پیام‌های همگانی — نتیجه‌ی هر کمپین و وضعیت گیرنده‌ها (P2 #21) */
class BroadcastResource extends Resource
{
    protected static ?string $model = Broadcast::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'پیام‌رسانی';

    protected static ?string $navigationLabel = 'سوابق پیام همگانی';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'پیام همگانی';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('message')->label('متن')->limit(50)->wrap(),
                Tables\Columns\TextColumn::make('reseller.slug')->label('نماینده')->placeholder('ربات اصلی'),
                Tables\Columns\TextColumn::make('total_recipients')->label('گیرندگان')->numeric(),
                Tables\Columns\TextColumn::make('sent_count')->label('ارسال‌شده')->numeric()->color('success'),
                Tables\Columns\TextColumn::make('failed_count')->label('ناموفق')->numeric()->color('danger'),
                Tables\Columns\TextColumn::make('progress')
                    ->label('پیشرفت')
                    ->getStateUsing(fn (Broadcast $record) => $record->progressPercent().'٪'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->colors([
                        'gray' => 'queued', 'warning' => 'sending',
                        'success' => 'completed', 'danger' => 'failed',
                    ]),
                Tables\Columns\TextColumn::make('created_at')->label('زمان')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('وضعیت')->options([
                    'queued' => 'در صف', 'sending' => 'در حال ارسال',
                    'completed' => 'تمام‌شده', 'failed' => 'ناموفق',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBroadcasts::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
