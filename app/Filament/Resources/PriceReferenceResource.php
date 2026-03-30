<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriceReferenceResource\Pages;
use App\Models\Asset;
use App\Models\Item;
use App\Models\PriceReference;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PriceReferenceResource extends Resource
{
    protected static ?string $model = PriceReference::class;

    protected static ?string $navigationIcon  = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Справочник цен';
    protected static ?string $navigationGroup = 'Справочники';
    protected static ?int    $navigationSort  = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['item', 'asset']))
            ->columns([
                Tables\Columns\TextColumn::make('product_name')
                    ->label('Товар')
                    ->getStateUsing(fn (PriceReference $r) => $r->productTitle())
                    ->searchable(query: function ($query, string $search) {
                        $query->where(function ($q) use ($search) {
                            $q->whereHas('item', fn ($q) => $q->where('title', 'like', "%{$search}%"))
                                ->orWhereHas('asset', fn ($q) => $q->where('title', 'like', "%{$search}%"));
                        });
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('product_type')
                    ->label('Тип')
                    ->getStateUsing(fn (PriceReference $r) => $r->productType())
                    ->badge()
                    ->color('gray')
                    ->width('80px'),

                Tables\Columns\TextColumn::make('source_type')
                    ->label('📦')
                    ->getStateUsing(fn (PriceReference $r) => $r->isItem() ? '🗡️' : '🧪')
                    ->tooltip(fn (PriceReference $r) => $r->isItem() ? 'Экипировка' : 'Расходник')
                    ->width('40px'),

                Tables\Columns\TextColumn::make('buy_avg')
                    ->label('📈 Покупка (avg)')
                    ->numeric()
                    ->suffix(' 💰')
                    ->color('success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sell_avg')
                    ->label('📉 Продажа (avg)')
                    ->numeric()
                    ->suffix(' 💰')
                    ->color('warning')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sample_count')
                    ->label('📊')
                    ->tooltip('Количество листингов при расчёте')
                    ->sortable()
                    ->width('50px'),

                Tables\Columns\IconColumn::make('is_manual')
                    ->label('✏️')
                    ->boolean()
                    ->tooltip(fn (PriceReference $r) => $r->is_manual ? 'Установлено вручную' : 'Авто-расчёт')
                    ->width('40px'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m H:i')
                    ->sortable()
                    ->width('90px'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_manual')
                    ->label('Источник')
                    ->trueLabel('Ручные')
                    ->falseLabel('Авто'),

                Tables\Filters\SelectFilter::make('source')
                    ->label('Тип товара')
                    ->options([
                        'item'  => 'Экипировка',
                        'asset' => 'Расходник',
                    ])
                    ->query(fn ($query, $data) => match ($data['value'] ?? null) {
                        'item'  => $query->whereNotNull('item_id'),
                        'asset' => $query->whereNotNull('asset_id'),
                        default => $query,
                    }),

                Tables\Filters\SelectFilter::make('product_type')
                    ->label('Категория')
                    ->options(fn () => static::getProductTypeOptions())
                    ->query(function ($query, $data) {
                        $type = $data['value'] ?? null;
                        if (!$type) return $query;

                        return $query->where(function ($q) use ($type) {
                            $q->whereHas('item', fn ($q) => $q->where('type', $type))
                                ->orWhereHas('asset', fn ($q) => $q->where('type', $type));
                        });
                    }),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['is_manual'] = true;
                        return $data;
                    }),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Товар')
                ->schema([
                    Forms\Components\Placeholder::make('product_display')
                        ->label('Товар')
                        ->content(fn (?PriceReference $record) => $record?->productTitle() ?? '—'),
                ])
                ->visibleOn('edit'),

            Forms\Components\Section::make('Цены покупки (💰)')
                ->description('Максимальная цена, по которой игроки покупают')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('buy_min')
                        ->label('Мин.')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\TextInput::make('buy_avg')
                        ->label('Сред.')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\TextInput::make('buy_max')
                        ->label('Макс.')
                        ->numeric()
                        ->minValue(0),
                ]),

            Forms\Components\Section::make('Цены продажи (💰)')
                ->description('Минимальная цена, по которой игроки продают')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('sell_min')
                        ->label('Мин.')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\TextInput::make('sell_avg')
                        ->label('Сред.')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\TextInput::make('sell_max')
                        ->label('Макс.')
                        ->numeric()
                        ->minValue(0),
                ]),

            Forms\Components\Textarea::make('admin_note')
                ->label('Комментарий')
                ->placeholder('Заметки по ценообразованию...')
                ->rows(2),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceReferences::route('/'),
            'edit'  => Pages\EditPriceReference::route('/{record}/edit'),
        ];
    }

    private static function getProductTypeOptions(): array
    {
        $itemTypes  = Item::where('status', 'ok')->distinct()->pluck('type')->filter()->toArray();
        $assetTypes = Asset::where('status', 'ok')->distinct()->pluck('type')->filter()->toArray();

        $types = array_unique(array_merge($itemTypes, $assetTypes));
        sort($types);

        return array_combine($types, $types);
    }
}
