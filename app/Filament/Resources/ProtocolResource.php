<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProtocolResource\Pages;
use App\Models\Protocol;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** مدیریت پروتکل‌ها (بند ۷ سند نیازمندی) */
class ProtocolResource extends Resource
{
    protected static ?string $model = Protocol::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'زیرساخت';

    protected static ?string $navigationLabel = 'پروتکل‌ها';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('نام پروتکل')->required()->maxLength(255),
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
                Tables\Columns\BadgeColumn::make('status')->label('وضعیت')
                    ->colors(['success' => 'active', 'danger' => 'inactive']),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProtocols::route('/'),
            'create' => Pages\CreateProtocol::route('/create'),
            'edit' => Pages\EditProtocol::route('/{record}/edit'),
        ];
    }
}
