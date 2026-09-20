<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\CustomerResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * فقط مشاهده — نماینده نباید بتواند اطلاعات حساسِ کاربر (وضعیت
 * کلی/telegram_id) را از این پنل تغییر دهد؛ آن مدیریت مختص پنل ادمین
 * اصلی است (بند ۱۹ سند Spec: «Reseller Bot: ❌ Other Reseller access»،
 * و به‌طریق‌اولی هیچ دسترسی نوشتنی به هویت مرکزی کاربر).
 */
class CustomerResource extends Resource
{
    use ResolvesCurrentReseller;

    protected static ?string $model = User::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'مشتریان و فروش';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'مشتریان';

    protected static ?string $modelLabel = 'مشتری';

    protected static ?string $pluralModelLabel = 'مشتریان';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(3)->schema([
                Placeholder::make('full_name')
                    ->label('نام')
                    ->content(fn (User $record) => $record->full_name),
                Placeholder::make('telegram_id')
                    ->label('شناسه تلگرام')
                    ->content(fn (User $record) => $record->telegram_id),
                Placeholder::make('balance')
                    ->label('موجودی کیف پول')
                    // کیف‌پول مشتری به CustomerAccountِ همین نماینده تعلق
                    // دارد، نه به خودِ User (بند ۱۷). خواندن مستقیم
                    // $record->wallet همیشه صفر نشان می‌داد.
                    ->content(fn (User $record) => number_format(
                        app(\App\Services\Core\WalletService::class)->balanceIn(
                            $record,
                            \App\Services\Core\Store\StoreContext::reseller(static::currentReseller()),
                        )
                    ).' تومان'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('نام')->searchable(),
                Tables\Columns\TextColumn::make('telegram_id')->label('شناسه تلگرام')->searchable(),
                // Wallet مشتری در Context همین نماینده است، نه Main؛ رابطه‌ی
                // User::wallet() فقط Main را برمی‌گرداند، پس از WalletService می‌خوانیم.
                Tables\Columns\TextColumn::make('wallet_balance')
                    ->label('موجودی کیف پول')
                    ->getStateUsing(fn (User $record) => number_format(
                        app(\App\Services\Core\WalletService::class)->balanceIn(
                            $record,
                            \App\Services\Core\Store\StoreContext::reseller(static::currentReseller()),
                        )
                    ).' تومان'),
                Tables\Columns\TextColumn::make('orders_count')->label('تعداد سفارش‌ها')->counts('orders'),
                Tables\Columns\TextColumn::make('created_at')->label('عضویت از')->dateTime('Y-m-d'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** طبق «اصل طلایی امنیت»: تنها Scope واقعی همین‌جاست — بدون آن، این Resource همه‌ی کاربران سیستم (از جمله مشتریان نمایندگان دیگر و مشتریان مستقیم پنل اصلی) را نشان می‌داد */
    public static function getEloquentQuery(): Builder
    {
        // Scope واقعی: فقط Userهایی که عضو «همین» فروشگاه‌اند (CustomerAccount).
        // هر User می‌تواند هم‌زمان مشتری چند نماینده باشد (Rule 12)، پس
        // users.reseller_id تک‌مقداری دیگر مبنای فیلتر نیست.
        $resellerId = static::currentReseller()->id;

        return parent::getEloquentQuery()->whereHas('customerAccounts', function (Builder $q) use ($resellerId) {
            $q->where('store_type', 'reseller')->where('reseller_id', $resellerId);
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'view' => Pages\ViewCustomer::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
