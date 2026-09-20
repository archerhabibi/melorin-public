<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\Core\WalletService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** مدیریت کاربران (بند ۱۴ سند نیازمندی) */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'کاربران';

    protected static ?string $navigationLabel = 'کاربران';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('full_name')->label('نام')->maxLength(255),
            Forms\Components\TextInput::make('telegram_id')->label('شناسه تلگرام')->disabled(),
            Forms\Components\TextInput::make('phone')->label('شماره موبایل'),
            Forms\Components\Select::make('status')
                ->label('وضعیت')
                ->options(['active' => 'فعال', 'disabled' => 'غیرفعال', 'blocked' => 'مسدود'])
                ->required()
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('full_name')->label('نام')->searchable(),
                Tables\Columns\TextColumn::make('telegram_id')->label('شناسه تلگرام')->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('نقش')
                    ->badge()
                    ->getStateUsing(fn (User $record) => match (true) {
                        $record->resellerAccount !== null => 'reseller',
                        $record->isBotAdmin() => 'admin',
                        default => 'customer',
                    })
                    ->colors(['success' => 'reseller', 'warning' => 'admin', 'gray' => 'customer'])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'reseller' => '🏬 نماینده', 'admin' => '🔑 ادمین ربات', default => 'مشتری',
                    }),
                Tables\Columns\TextColumn::make('wallet.balance')->label('موجودی Main')
                    ->money('IRT', divideBy: 1)
                    ->default(0),
                Tables\Columns\TextColumn::make('orders_count')->counts('orders')->label('تعداد سفارش'),
                Tables\Columns\TextColumn::make('accounts_count')->counts('accounts')->label('تعداد اکانت'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->colors(['success' => 'active', 'warning' => 'disabled', 'danger' => 'blocked'])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'active' => 'فعال', 'disabled' => 'غیرفعال', 'blocked' => 'مسدود', default => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ عضویت')->date('Y-m-d'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(['active' => 'فعال', 'disabled' => 'غیرفعال', 'blocked' => 'مسدود']),

                Tables\Filters\SelectFilter::make('role')
                    ->label('نقش')
                    ->options(['reseller' => '🏬 نماینده', 'admin' => '🔑 ادمین ربات', 'customer' => 'مشتری'])
                    ->query(function (Builder $query, array $data) {
                        $adminIds = array_map('intval', config('telegram.admin_ids', []));

                        if ($data['value'] === 'reseller') {
                            $query->whereHas('resellerAccount');
                        } elseif ($data['value'] === 'admin') {
                            $query->whereIn('telegram_id', $adminIds)->whereDoesntHave('resellerAccount');
                        } elseif ($data['value'] === 'customer') {
                            $query->whereNotIn('telegram_id', $adminIds)->whereDoesntHave('resellerAccount');
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('adjustBalance')
                    ->label('💰 تنظیم موجودی')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('مبلغ (مثبت برای افزایش، منفی برای کاهش)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('description')
                            ->label('توضیح (برای گردش حساب)')
                            ->required(),
                    ])
                    ->action(function (User $record, array $data) {
                        try {
                            app(WalletService::class)->adminAdjust(
                                $record,
                                (float) $data['amount'],
                                description: $data['description']
                            );

                            Notification::make()->title('موجودی با موفقیت به‌روزرسانی شد.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا: '.$e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // کاربران فقط از طریق ربات/سایت ساخته می‌شوند، نه دستی از پنل.
        return false;
    }
}
