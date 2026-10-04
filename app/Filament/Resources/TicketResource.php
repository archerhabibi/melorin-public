<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TicketResource\Pages;
use App\Filament\Resources\TicketResource\RelationManagers\MessagesRelationManager;
use App\Models\Ticket;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * قبل از این نسخه، تیکت واقعاً در دیتابیس ساخته می‌شد ولی هیچ Resource ی
 * برای دیدن/پاسخ به آن وجود نداشت — تیکت به یک «جعبه‌ی سیاه» می‌رفت.
 * این Resource همراه با MessagesRelationManager (که پاسخ‌دادن را انجام
 * می‌دهد) آن حلقه را می‌بندد. طبق تصمیم معماری پروژه (بند ۲۲)، مدیریت
 * واقعی همیشه از طریق پنل وب انجام می‌شود؛ ربات فقط برای ثبت اولیه‌ی
 * تیکت و اطلاع‌رسانی استفاده می‌شود.
 */
class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationGroup = 'پشتیبانی';

    protected static ?string $navigationLabel = 'تیکت‌ها';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'تیکت';

    protected static ?string $pluralModelLabel = 'تیکت‌ها';

    public static function getNavigationBadge(): ?string
    {
        $openCount = static::getModel()::query()->where('status', '!=', 'closed')->count();

        return $openCount > 0 ? (string) $openCount : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        // فقط نمایش خلاصه‌ی تیکت در صفحه‌ی View؛ خودِ گفتگو (و پاسخ‌دادن)
        // در MessagesRelationManager زیر همین صفحه انجام می‌شود.
        return $form->schema([
            Forms\Components\Grid::make(4)->schema([
                Forms\Components\Placeholder::make('user')
                    ->label('کاربر')
                    ->content(fn (Ticket $record) => $record->user->full_name.' ('.($record->user->telegram_id ? 'شناسه: '.$record->user->telegram_id : (string) $record->user->email).')'),

                Forms\Components\Placeholder::make('type')
                    ->label('نوع')
                    ->content(fn (Ticket $record) => $record->type === 'reseller_request' ? '🤖 درخواست نمایندگی' : '🎧 پشتیبانی'),

                Forms\Components\Placeholder::make('subject')
                    ->label('موضوع')
                    ->content(fn (Ticket $record) => $record->subject),

                Forms\Components\Placeholder::make('status')
                    ->label('وضعیت')
                    ->content(fn (Ticket $record) => match ($record->status) {
                        'open' => 'در انتظار پاسخ',
                        'answered' => 'پاسخ داده‌شده',
                        'closed' => 'بسته‌شده',
                        default => $record->status,
                    }),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.full_name')->label('کاربر')->searchable(),
                Tables\Columns\TextColumn::make('user.telegram_id')->label('شناسه تلگرام')->searchable(),
                Tables\Columns\TextColumn::make('reseller_id')
                    ->label('فروشگاه')
                    ->formatStateUsing(fn ($state) => $state ? 'نماینده #'.$state : 'اصلی')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('subject')->label('موضوع')->searchable()->limit(40),
                Tables\Columns\BadgeColumn::make('type')
                    ->label('نوع')
                    ->colors(['info' => 'support', 'warning' => 'reseller_request'])
                    ->formatStateUsing(fn ($state) => $state === 'reseller_request' ? '🤖 درخواست نمایندگی' : '🎧 پشتیبانی'),
                Tables\Columns\BadgeColumn::make('priority')
                    ->label('اولویت')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->colors([
                        'gray' => 'low',
                        'info' => 'normal',
                        'danger' => 'high',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'low' => 'کم',
                        'normal' => 'عادی',
                        'high' => 'بالا',
                        default => $state,
                    }),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->colors([
                        'warning' => 'open',
                        'success' => 'answered',
                        'gray' => 'closed',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'open' => 'در انتظار پاسخ',
                        'answered' => 'پاسخ داده‌شده',
                        'closed' => 'بسته‌شده',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('messages_count')
                    ->label('تعداد پیام‌ها')
                    ->counts('messages'),
                Tables\Columns\TextColumn::make('updated_at')->label('آخرین فعالیت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع')
                    ->options([
                        'support' => '🎧 پشتیبانی',
                        'reseller_request' => '🤖 درخواست نمایندگی',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options([
                        'open' => 'در انتظار پاسخ',
                        'answered' => 'پاسخ داده‌شده',
                        'closed' => 'بسته‌شده',
                    ]),
                Tables\Filters\SelectFilter::make('priority')
                    ->label('اولویت')
                    ->options([
                        'low' => 'کم',
                        'normal' => 'عادی',
                        'high' => 'بالا',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('مشاهده و پاسخ'),

                Tables\Actions\Action::make('close')
                    ->label('بستن تیکت')
                    ->icon('heroicon-o-check-circle')
                    ->color('gray')
                    ->visible(fn (Ticket $record) => $record->isOpen())
                    ->requiresConfirmation()
                    ->action(function (Ticket $record) {
                        $record->update(['status' => 'closed']);

                        Notification::make()->title('تیکت بسته شد.')->success()->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
        ];
    }

    // ---- جستجوی سراسری (B1.3) ----
    protected static ?string $recordTitleAttribute = 'subject';

    public static function getGloballySearchableAttributes(): array
    {
        return ['subject', 'user.full_name'];
    }

    public static function getGlobalSearchEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('user');
    }

    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        return array_filter(['کاربر' => $record->user?->full_name]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
            'view' => Pages\ViewTicket::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        // تیکت‌ها فقط از طریق ربات (MiscHandler::supportSubmit) ساخته
        // می‌شوند، نه دستی از پنل — طبق همان اصل بند ۳۴ که در
        // PaymentResource هم رعایت شده.
        return false;
    }
}
