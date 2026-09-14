<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** مدیریت محصولات و تعرفه‌ها (بند ۸ سند نیازمندی) */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'فروشگاه';

    protected static ?string $navigationLabel = 'محصولات و تعرفه‌ها';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('category_id')
                ->label('دسته‌بندی')
                ->relationship('category', 'name')
                ->required()
                ->searchable()
                ->preload(),

            Forms\Components\TextInput::make('name')->label('نام محصول')->required()->maxLength(255),

            Forms\Components\Grid::make(3)->schema([
                Forms\Components\TextInput::make('price')
                    ->label('قیمت پایه (تومان)')
                    ->numeric()
                    ->required()
                    ->suffix('تومان'),

                Forms\Components\TextInput::make('traffic_gb')
                    ->label('حجم (گیگابایت)')
                    ->numeric()
                    ->helperText('خالی = نامحدود'),

                Forms\Components\TextInput::make('duration_days')
                    ->label('مدت اعتبار (روز)')
                    ->numeric()
                    ->required(),
            ]),

            Forms\Components\TextInput::make('reseller_price')
                ->label('قیمت نمایندگان (تومان)')
                ->helperText('قیمتی که ما این محصول را به نماینده می‌فروشیم — نه قیمت فروش نماینده (که همیشه دست خودِ نماینده است و اینجا تعیین نمی‌شود). معمولاً باید پایین‌تر از «قیمت پایه» باشد. خالی = همان «قیمت پایه» برای نماینده هم اعمال می‌شود.')
                ->numeric()
                ->suffix('تومان'),

            Forms\Components\Select::make('protocol_id')
                ->label('پروتکل')
                ->relationship('protocol', 'name')
                ->searchable()
                ->preload(),

            Forms\Components\TextInput::make('sale_limit')
                ->label('محدودیت فروش (تعداد)')
                ->numeric()
                ->helperText('خالی = بدون محدودیت'),

            Forms\Components\Toggle::make('status')
                ->label('فعال')
                ->formatStateUsing(fn ($state) => $state === 'active')
                ->dehydrateStateUsing(fn ($state) => $state ? 'active' : 'inactive')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('نام')->searchable(),
                Tables\Columns\TextColumn::make('category.name')->label('دسته‌بندی'),
                Tables\Columns\TextColumn::make('price')->label('قیمت')->money('IRT', divideBy: 1)->sortable(),
                Tables\Columns\TextColumn::make('reseller_price')->label('قیمت نمایندگان')->money('IRT', divideBy: 1)->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('duration_days')->label('مدت (روز)'),
                Tables\Columns\TextColumn::make('traffic_gb')->label('حجم (گیگ)')->placeholder('نامحدود'),
                Tables\Columns\BadgeColumn::make('status')->label('وضعیت')
                    ->colors(['success' => 'active', 'danger' => 'inactive']),
            ])
            // طبق درخواست صریح: محصولات هر سبد فروش زیرمجموعه‌ی همان
            // سبد نمایش داده شوند و سبدها از هم تفکیک شده باشند — تا
            // بازبینی راحت‌تر شود. defaultGroup یعنی همین از ابتدا،
            // بدون نیاز به فعال‌کردن دستی، فعال است؛ کاربر هنوز می‌تواند
            // از منوی «گروه‌بندی» آن را خاموش/عوض کند.
            ->defaultGroup(
                Tables\Grouping\Group::make('category.name')
                    ->label('سبد فروش')
                    ->collapsible()
            )
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('دسته‌بندی')
                    ->relationship('category', 'name'),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
