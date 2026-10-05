<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\ProductResource\Pages;
use App\Filament\Support\MoneyInput;
use App\Models\Product;
use App\Models\Reseller;
use App\Services\Resellers\Products\BulkPricingResult;
use App\Services\Resellers\Products\MarkupMode;
use App\Services\Resellers\Products\ProductMargin;
use App\Services\Resellers\Products\ProductSaleState;
use App\Services\Resellers\Products\ResellerBulkPricing;
use App\Services\Resellers\Products\ResellerProductCatalog;
use App\Services\Resellers\ResellerPricingService;
use App\Support\JalaliDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * مدیریت محصولات نماینده (B5.3).
 *
 * طبق بند ۵ سند نیازمندی Reseller Platform: نماینده Catalog مستقل ندارد؛ فقط روی محصولِ مجاز می‌تواند قیمت فروش
 * (customers_price) تعیین و فروش را فعال/غیرفعال کند — نه محصول بسازد، نه ویرایش یا حذف کند. به همین دلیل
 * canCreate/canEdit/canDelete همه false‌اند و تنها راه تغییر، اکشن‌هایی‌اند که مستقیماً سرویس‌های Core را صدا
 * می‌زنند (ResellerPricingService برای تکی، ResellerBulkPricing برای گروهی) — نه فرم پیش‌فرض Filament.
 *
 * منطق و عددها (وضعیت فروش، سود، محدوده‌ی مجاز قیمت، خلاصه، سابقه) از ResellerProductCatalog/PriceBounds (Core)
 * می‌آیند؛ این‌جا فقط نمایش و فراخوانی است. ستون‌ها Subquery‌اند (بدون N+1).
 */
class ProductResource extends Resource
{
    use ResolvesCurrentReseller;

    protected static ?string $model = Product::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'فروشگاه من';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'محصولات';

    protected static ?string $modelLabel = 'محصول';

    protected static ?string $pluralModelLabel = 'محصولات';

    private const HISTORY_ACTION_LABELS = [
        'product.price_changed' => 'تغییر قیمت / فعال‌سازی',
        'product.disabled' => 'غیرفعال‌سازی',
    ];

    private static function catalog(): ResellerProductCatalog
    {
        return app(ResellerProductCatalog::class);
    }

    /**
     * طبق درخواست صریح: وقتی مدیر Core یک سبد فروش را برای همه‌ی نمایندگان غیرفعال می‌کند، آن سبد و محصولاتش
     * اصلاً در فروشگاه نماینده دیده نشوند (نه فقط غیرقابل‌فعال‌سازی). همین Query هم برای جدول و هم برای صفحه‌ی
     * جزئیات است؛ پس محصولِ بیرون از دسترس با URL دست‌ساز 404 می‌دهد.
     */
    public static function getEloquentQuery(): Builder
    {
        return static::catalog()->query(static::currentReseller());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        $reseller = static::currentReseller();
        $catalog = static::catalog();

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('نام محصول')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Product $record) => static::specLabel($record)),
                // عددی که واقعاً از کیف‌پول نماینده در Main کسر می‌شود: reseller_price (بند ۵) — نه main_price.
                Tables\Columns\TextColumn::make('supply_price')
                    ->label('هزینه‌ی تأمین من (reseller_price)')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => Money::format((int) $state)),
                Tables\Columns\TextColumn::make('my_price')
                    ->label('قیمت فروش به مشتری (customers_price)')
                    ->sortable()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state === null ? null : Money::format((int) $state)),
                Tables\Columns\TextColumn::make('profit')
                    ->label('سود هر فروش')
                    ->sortable()
                    ->placeholder('—')
                    ->formatStateUsing(function ($state, Product $record) use ($catalog) {
                        $margin = $catalog->marginOf($record);

                        return $margin === null ? null : Money::format($margin->profit).($margin->percent !== null ? ' ('.number_format($margin->percent).'٪)' : '');
                    })
                    ->color(fn ($state) => $state === null ? null : ((int) $state === 0 ? 'warning' : 'success')),
                Tables\Columns\TextColumn::make('state')
                    ->label('وضعیت فروش')
                    ->badge()
                    ->getStateUsing(fn (Product $record) => $catalog->stateOf($reseller, $record)->value)
                    ->formatStateUsing(fn (string $state) => ProductSaleState::from($state)->label())
                    ->color(fn (string $state) => ProductSaleState::from($state)->color())
                    ->tooltip(fn (string $state) => ProductSaleState::from($state)->hint()),
                Tables\Columns\TextColumn::make('sales_count')
                    ->label('فروش')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format((int) $state)),
                Tables\Columns\TextColumn::make('last_sold_at')
                    ->label('آخرین فروش')
                    ->sortable()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state ? JalaliDate::format(CarbonImmutable::parse($state)) : null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // طبق درخواست صریح: محصولات هر سبد فروش زیرمجموعه‌ی همان سبد نمایش داده شوند.
            ->defaultGroup(
                Tables\Grouping\Group::make('category.name')
                    ->label('سبد فروش')
                    ->collapsible()
            )
            ->filters([
                Tables\Filters\SelectFilter::make('state')
                    ->label('وضعیت فروش')
                    ->options(ProductSaleState::labels())
                    ->query(function (Builder $query, array $data) use ($reseller, $catalog) {
                        $state = ProductSaleState::fromInput($data['value'] ?? null);

                        return $state === null ? $query : $catalog->applyState($query, $reseller, $state);
                    }),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('سبد فروش')
                    ->relationship('category', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('جزئیات'),

                Tables\Actions\Action::make('set_price')
                    ->label('تنظیم قیمت')
                    ->icon('heroicon-o-currency-dollar')
                    ->form(fn (Product $record) => static::priceForm($record))
                    ->fillForm(fn (Product $record) => static::priceFormDefaults($record))
                    ->action(fn (Product $record, array $data) => static::applyPrice($record, $data)),

                // طبق درخواست صریح: «در کنار هر محصول دکمه‌ای برای فعال کردن آن محصول برای ربات خودِ همان نماینده».
                // فقط وقتی دیده می‌شود که قیمت ثبت است ولی فروش خاموش؛ بدون قیمت، فعال‌سازی بی‌معناست و کاربر
                // باید از «تنظیم قیمت» شروع کند (که خودش به‌صورت ضمنی فعال هم می‌کند).
                Tables\Actions\Action::make('enable')
                    ->label('فعال کردن')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Product $record) => static::canEnable($record))
                    ->action(fn (Product $record) => static::applyEnable($record)),

                Tables\Actions\Action::make('disable')
                    ->label('غیرفعال کردن')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (Product $record) => static::canDisable($record))
                    ->requiresConfirmation()
                    ->action(fn (Product $record) => static::applyDisable($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulk_markup')
                        ->label('قیمت‌گذاری گروهی')
                        ->icon('heroicon-o-calculator')
                        ->form(static::markupForm())
                        ->modalDescription('قیمت فروش هر محصول انتخاب‌شده از روی قیمت تأمین آن محاسبه و محصول فعال می‌شود. محصولی که قیمت محاسبه‌شده‌اش از قوانین مجاز بیرون باشد تغییر نمی‌کند و دلیلش گزارش می‌شود.')
                        ->action(function (Collection $records, array $data) use ($reseller): void {
                            $mode = MarkupMode::from($data['mode']);

                            try {
                                $result = app(ResellerBulkPricing::class)->markup(
                                    $reseller,
                                    $records->modelKeys(),
                                    $mode,
                                    $mode === MarkupMode::Percent ? (int) $data['percent'] : (int) $data['amount'],
                                    max(1, (int) ($data['round_to'] ?? 1)),
                                );
                            } catch (InvalidArgumentException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();

                                return;
                            }

                            static::notifyBulk($result, 'قیمت‌گذاری شد و فعال شد');
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('bulk_enable')
                        ->label('فعال کردن')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('فقط محصولاتی فعال می‌شوند که قیمت ثبت‌شده‌ی مجاز دارند.')
                        ->action(fn (Collection $records) => static::notifyBulk(
                            app(ResellerBulkPricing::class)->enable($reseller, $records->modelKeys()),
                            'فعال شد'
                        ))
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('bulk_disable')
                        ->label('غیرفعال کردن')
                        ->icon('heroicon-o-x-circle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => static::notifyBulk(
                            app(ResellerBulkPricing::class)->disable($reseller, $records->modelKeys()),
                            'غیرفعال شد'
                        ))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('محصولی برای فروش در دسترس نیست')
            ->emptyStateDescription('مدیر اصلی هنوز سبدی را برای نمایندگان باز نکرده است یا فیلتر انتخابی نتیجه‌ای ندارد.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $reseller = static::currentReseller();
        $record = $infolist->getRecord();
        $profile = $record instanceof Product ? static::catalog()->profile($reseller, $record) : null;

        abort_if($profile === null, 404);

        $min = $profile->bounds->min();
        $max = $profile->bounds->max();
        $range = $profile->bounds->isEmpty()
            ? 'با قوانین فعلی هیچ قیمتی مجاز نیست؛ با پشتیبانی تماس بگیرید.'
            : 'از '.Money::format($min).($max !== null ? ' تا '.Money::format($max) : ' (بدون سقف)');

        $history = $profile->history->map(fn (array $h) => [
            'date' => JalaliDate::format($h['at']),
            'action' => self::HISTORY_ACTION_LABELS[$h['action']] ?? $h['action'],
            'change' => match (true) {
                $h['action'] === 'product.disabled' => 'فروش خاموش شد',
                $h['from'] === null => 'قیمت اولیه: '.Money::format((int) $h['to']),
                default => Money::format($h['from']).' ← '.Money::format((int) $h['to']),
            },
        ])->all();

        return $infolist->schema([
            Section::make('وضعیت فروش')->schema([
                Grid::make(2)->schema([
                    TextEntry::make('p_state')->label('وضعیت')->badge()
                        ->state($profile->state->label())->color($profile->state->color()),
                    TextEntry::make('p_hint')->label('توضیح')->state($profile->state->hint()),
                ]),
            ]),
            Section::make('قیمت‌ها')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('p_supply')->label('هزینه‌ی تأمین من (reseller_price)')->state(Money::format($profile->supplyPrice)),
                    TextEntry::make('p_price')->label('قیمت فروش من (customers_price)')
                        ->state($profile->customersPrice === null ? '—' : Money::format($profile->customersPrice)),
                    TextEntry::make('p_profit')->label('سود هر فروش')
                        ->state($profile->margin === null ? '—' : Money::format($profile->margin->profit).($profile->margin->percent !== null ? ' ('.number_format($profile->margin->percent).'٪)' : '')),
                    TextEntry::make('p_range')->label('بازه‌ی مجاز قیمت فروش')->state($range)->columnSpanFull(),
                ]),
            ]),
            Section::make('عملکرد فروش (همین فروشگاه)')->schema([
                Grid::make(4)->schema([
                    TextEntry::make('p_sales')->label('تعداد فروش')->state(number_format($profile->salesCount)),
                    TextEntry::make('p_revenue')->label('مجموع فروش')->state(Money::format($profile->salesRevenue)),
                    TextEntry::make('p_sales_profit')->label('سود حاصل')->state(Money::format($profile->salesProfit)),
                    TextEntry::make('p_last')->label('آخرین فروش')->state($profile->lastSoldAt ? JalaliDate::format($profile->lastSoldAt) : '—'),
                ]),
            ]),
            Section::make('سابقه‌ی تغییر قیمت')->schema([
                RepeatableEntry::make('p_history')->label('')->state($history)->grid(2)->schema([
                    TextEntry::make('date')->label('تاریخ'),
                    TextEntry::make('action')->label('رویداد'),
                    TextEntry::make('change')->label('تغییر'),
                ])->hidden($history === []),
                TextEntry::make('p_no_history')->label('')->state('هنوز تغییری ثبت نشده است.')->visible($history === []),
            ]),
        ]);
    }

    // ------------------------------------------------------------------
    //  اکشن‌های مشترک جدول و صفحه‌ی جزئیات (یک رفتار، یک متن)
    // ------------------------------------------------------------------

    /** @return array<int, Component> */
    public static function priceForm(Product $record): array
    {
        $bounds = app(ResellerPricingService::class)->priceBounds(static::currentReseller(), $record);
        $max = $bounds->max();

        return [
            MoneyInput::make('customers_price')
                ->label('قیمت فروش به مشتری — customers_price ('.Money::label().')')
                ->required()
                ->minValue(0)
                ->live(onBlur: true)
                ->helperText($bounds->isEmpty()
                    ? 'با قوانین فعلی هیچ قیمتی مجاز نیست؛ با پشتیبانی تماس بگیرید.'
                    : 'بازه‌ی مجاز: از '.Money::format($bounds->min()).($max !== null ? ' تا '.Money::format($max) : ' (بدون سقف)')),
            Forms\Components\Placeholder::make('profit_preview')
                ->label('سود هر فروش با این قیمت')
                ->content(function (Forms\Get $get) use ($record): string {
                    $raw = $get('customers_price');
                    $price = ($raw === null || $raw === '') ? null : Money::parse((string) $raw);

                    if ($price === null) {
                        return '—';
                    }

                    $margin = ProductMargin::of($price, $record->resellerPrice());

                    return Money::format($margin->profit).($margin->percent !== null ? ' ('.number_format($margin->percent).'٪)' : '');
                }),
        ];
    }

    /** @return array{customers_price: int} */
    public static function priceFormDefaults(Product $record): array
    {
        return ['customers_price' => (int) (static::row($record)->getAttribute('my_price') ?? $record->resellerPrice())];
    }

    public static function applyPrice(Product $record, array $data): void
    {
        $reseller = static::currentReseller();

        try {
            app(ResellerPricingService::class)->setCustomersPrice($reseller, $record, (int) $data['customers_price']);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        static::notifyResult($reseller, $record, 'قیمت ثبت و محصول فعال شد.');
    }

    public static function applyEnable(Product $record): void
    {
        $reseller = static::currentReseller();

        try {
            app(ResellerPricingService::class)->enable($reseller, $record);
        } catch (InvalidArgumentException $e) {
            // قیمتِ ذخیره‌شده ممکن است با قوانین فعلی دیگر مجاز نباشد — کاربر باید قیمت جدید بدهد.
            Notification::make()
                ->title($e->getMessage())
                ->body('لطفاً از «تنظیم قیمت» یک قیمت جدید وارد کنید.')
                ->danger()
                ->send();

            return;
        }

        static::notifyResult($reseller, $record, 'محصول در فروشگاه شما فعال شد.');
    }

    public static function applyDisable(Product $record): void
    {
        app(ResellerPricingService::class)->disable(static::currentReseller(), $record);

        Notification::make()->title('محصول برای فروشگاه شما غیرفعال شد.')->success()->send();
    }

    public static function canEnable(Product $record): bool
    {
        $row = static::row($record);

        return $row->getAttribute('my_price') !== null && ! $row->getAttribute('my_enabled');
    }

    public static function canDisable(Product $record): bool
    {
        return (bool) static::row($record)->getAttribute('my_enabled');
    }

    /**
     * رکوردی که ستون‌های محاسبه‌شده‌ی کاتالوگ (my_price/my_enabled) را دارد. جدول و صفحه‌ی جزئیات همیشه همین را
     * می‌دهند؛ اگر رکورد خام (بدون این ستون‌ها) رسید، یک‌بار از همان Query دوباره خوانده می‌شود تا تصمیم دکمه‌ها
     * هرگز با «نبودنِ ستون» اشتباه نشود.
     */
    private static function row(Product $record): Product
    {
        if (array_key_exists('my_enabled', $record->getAttributes())) {
            return $record;
        }

        return static::getEloquentQuery()->whereKey($record->getKey())->first() ?? $record;
    }

    /**
     * پس از ثبت قیمت/فعال‌سازی: اگر محصول واقعاً فروش می‌رود پیام موفقیت، وگرنه هشدار با علت. (پیش از B5.3
     * همیشه «فعال شد» می‌گفت، حتی وقتی سبد محصول خاموش بود و فروش نمی‌رفت.)
     */
    private static function notifyResult(Reseller $reseller, Product $record, string $successTitle): void
    {
        $state = static::catalog()->stateFor($reseller, $record);

        if ($state === null || $state->isSelling()) {
            Notification::make()->title($successTitle)->success()->send();

            return;
        }

        Notification::make()
            ->title('انجام شد، ولی این محصول هنوز فروش نمی‌رود: '.$state->label())
            ->body($state->hint())
            ->warning()
            ->send();
    }

    /** @return array<int, Component> */
    private static function markupForm(): array
    {
        return [
            Forms\Components\Select::make('mode')
                ->label('شیوه‌ی محاسبه‌ی سود')
                ->options(collect(MarkupMode::cases())->mapWithKeys(fn (MarkupMode $m) => [$m->value => $m->label()])->all())
                ->default(MarkupMode::Percent->value)
                ->required()
                ->live()
                ->native(false),
            Forms\Components\TextInput::make('percent')
                ->label('درصد سود')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(MarkupMode::MAX_PERCENT)
                ->required(fn (Forms\Get $get) => $get('mode') === MarkupMode::Percent->value)
                ->visible(fn (Forms\Get $get) => $get('mode') === MarkupMode::Percent->value),
            MoneyInput::make('amount')
                ->label('مبلغ سود ('.Money::label().')')
                ->minValue(0)
                ->required(fn (Forms\Get $get) => $get('mode') === MarkupMode::Fixed->value)
                ->visible(fn (Forms\Get $get) => $get('mode') === MarkupMode::Fixed->value),
            MoneyInput::make('round_to')
                ->label('گردکردن رو‌به‌بالا به مضرب (اختیاری)')
                ->helperText('مثلاً ۱۰۰۰ ⇒ قیمت‌ها به نزدیک‌ترین هزار بالاتر گرد می‌شوند. خالی = بدون گرد.')
                ->minValue(1),
        ];
    }

    private static function notifyBulk(BulkPricingResult $result, string $verb): void
    {
        if ($result->skippedCount() === 0) {
            Notification::make()->title(number_format($result->appliedCount()).' محصول '.$verb.'.')->success()->send();

            return;
        }

        // دلیل‌های تکراری یک‌جا شمرده می‌شوند تا پیام با تعداد محصولات طولانی نشود
        $reasons = collect($result->skipped)->countBy()->map(fn (int $n, string $why) => number_format($n).' محصول: '.$why)->values()->implode("\n");

        $notification = Notification::make()
            ->title(number_format($result->appliedCount()).' محصول '.$verb.'، '.number_format($result->skippedCount()).' محصول انجام نشد')
            ->body($reasons);

        ($result->appliedCount() > 0 ? $notification->warning() : $notification->danger())->send();
    }

    private static function specLabel(Product $record): string
    {
        $traffic = $record->traffic_gb === null
            ? 'حجم نامحدود'
            : rtrim(rtrim(number_format((float) $record->traffic_gb, 2), '0'), '.').' گیگابایت';

        return $traffic.' · '.number_format((int) $record->duration_days).' روزه';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'view' => Pages\ViewProduct::route('/{record}'),
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
