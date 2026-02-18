<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon  = 'heroicon-o-squares-2x2';
    protected static ?string $navigationLabel = 'Справочник товаров';
    protected static ?string $navigationGroup = 'Справочники';
    protected static ?int    $navigationSort  = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('icon')
                    ->label('🖼')
                    ->default('—'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('grade')
                    ->label('Грейд')
                    ->badge()
                    ->default('—'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'weapon'     => 'Оружие',
                        'armor'      => 'Броня',
                        'jewelry'    => 'Украшения',
                        'scroll'     => 'Свитки',
                        'recipe'     => 'Рецепты',
                        'consumable' => 'Расходники',
                        'resource'   => 'Ресурсы',
                        'talent'     => 'Таланты',
                        'appearance' => 'Внешний вид',
                        'chest'      => 'Сундуки',
                        'other'      => 'Прочее',
                        default      => '—',
                    })
                    ->default('—'),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Алиас для')
                    ->default('—')
                    ->limit(30),

                Tables\Columns\IconColumn::make('is_verified')
                    ->label('Верифицирован')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => $state === 'ok' ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('listings_count')
                    ->label('Объявлений')
                    ->counts('listings')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('grade')
                    ->label('Грейд')
                    ->options(['I' => 'I', 'II' => 'II', 'III' => 'III', 'III+' => 'III+', 'IV' => 'IV', 'V' => 'V']),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        'weapon'     => 'Оружие',
                        'armor'      => 'Броня',
                        'jewelry'    => 'Украшения',
                        'scroll'     => 'Свитки',
                        'recipe'     => 'Рецепты',
                        'consumable' => 'Расходники',
                        'resource'   => 'Ресурсы',
                        'talent'     => 'Таланты',
                        'appearance' => 'Внешний вид',
                        'chest'      => 'Сундуки',
                        'other'      => 'Прочее',
                    ]),

                Tables\Filters\TernaryFilter::make('is_verified')
                    ->label('Верификация')
                    ->placeholder('Все')
                    ->trueLabel('Верифицированные')
                    ->falseLabel('Не верифицированные'),

                Tables\Filters\Filter::make('aliases_only')
                    ->label('Только алиасы')
                    ->query(fn ($query) => $query->whereNotNull('parent_id')),

                Tables\Filters\Filter::make('no_icon')
                    ->label('Без иконки')
                    ->query(fn ($query) => $query->whereNull('icon')),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\Action::make('verify')
                    ->label('Верифицировать')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Product $record) => !$record->is_verified)
                    ->action(function (Product $record) {
                        $record->update(['is_verified' => true]);
                        Notification::make()->title('Товар верифицирован')->success()->send();
                    }),

                Tables\Actions\Action::make('unverify')
                    ->label('Снять верификацию')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (Product $record) => $record->is_verified)
                    ->action(function (Product $record) {
                        $record->update(['is_verified' => false]);
                        Notification::make()->title('Верификация снята')->success()->send();
                    }),

                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulk_verify')
                    ->label('Верифицировать')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->action(fn ($records) => $records->each->update(['is_verified' => true])),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Основное')->schema([
                Forms\Components\TextInput::make('icon')
                    ->label('Иконка (эмодзи)')
                    ->maxLength(50),

                Forms\Components\TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(500),

                Forms\Components\Select::make('grade')
                    ->label('Грейд')
                    ->options(['I' => 'I', 'II' => 'II', 'III' => 'III', 'III+' => 'III+', 'IV' => 'IV', 'V' => 'V'])
                    ->placeholder('Без грейда'),

                Forms\Components\Select::make('type')
                    ->label('Тип')
                    ->options([
                        'weapon'     => 'Оружие',
                        'armor'      => 'Броня',
                        'jewelry'    => 'Украшения',
                        'scroll'     => 'Свитки',
                        'recipe'     => 'Рецепты',
                        'consumable' => 'Расходники',
                        'resource'   => 'Ресурсы',
                        'talent'     => 'Таланты',
                        'appearance' => 'Внешний вид',
                        'chest'      => 'Сундуки',
                        'other'      => 'Прочее',
                    ])
                    ->placeholder('Не задан'),

                Forms\Components\Select::make('parent_id')
                    ->label('Алиас для (основной товар)')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) =>
                        Product::where('name', 'like', "%{$search}%")
                            ->whereNull('parent_id')
                            ->limit(20)
                            ->pluck('name', 'id')
                    )
                    ->placeholder('Не алиас'),

                Forms\Components\Toggle::make('is_verified')
                    ->label('Верифицирован по игровой БД')
                    ->default(false),

                Forms\Components\Select::make('status')
                    ->label('Статус')
                    ->options(['ok' => 'OK', 'needs_merge' => 'Требует объединения'])
                    ->default('ok')
                    ->required(),
            ])->columns(2),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'edit'  => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
