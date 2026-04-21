<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriceReferenceResource\Pages;
use App\Models\Asset;
use App\Models\Item;
use App\Models\Listing;
use App\Models\PriceReference;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PriceReferenceResource extends Resource
{
    protected static ?string $model = PriceReference::class;

    protected static \BackedEnum|string|null $navigationIcon  = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Справочник цен';
    protected static \UnitEnum|string|null $navigationGroup = 'Справочники';
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Section::make('Товар')
                ->schema([
                    Forms\Components\Placeholder::make('product_display')
                        ->label('Товар')
                        ->content(fn (?PriceReference $record) => $record?->productTitle() ?? '—'),
                ])
                ->visibleOn('edit'),

            Forms\Components\Section::make('📈 Последние покупки')
                ->schema([
                    Forms\Components\Placeholder::make('recent_buy')
                        ->label('')
                        ->content(fn (?PriceReference $record) => static::renderRecentListings($record, 'buy')),
                ])
                ->collapsible()
                ->visibleOn('edit'),

            Forms\Components\Section::make('📉 Последние продажи')
                ->schema([
                    Forms\Components\Placeholder::make('recent_sell')
                        ->label('')
                        ->content(fn (?PriceReference $record) => static::renderRecentListings($record, 'sell')),
                ])
                ->collapsible()
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

    private static function renderRecentListings(?PriceReference $record, string $type): HtmlString
    {
        if (!$record) {
            return new HtmlString('<span style="color:#888">—</span>');
        }

        $limit  = (int) config('parser.price_ref.recent_listings', 10);
        $column = $record->item_id ? 'item_id' : 'asset_id';
        $id     = $record->item_id ?? $record->asset_id;
        $order  = $type === 'buy' ? 'desc' : 'asc';

        // Берём с запасом, потом оставляем уникальные по цена+игрок
        $listings = Listing::with(['tgUser', 'tgMessage'])
            ->where($column, $id)
            ->where('type', $type)
            ->where('currency', 'gold')
            ->where('status', '!=', 'invalid')
            ->whereNotNull('price')
            ->orderBy('price', $order)
            ->orderByDesc('posted_at')
            ->limit($limit * 3)
            ->get();

        // Дедупликация: уникальная пара цена + игрок
        $seen    = [];
        $unique  = $listings->filter(function (Listing $listing) use (&$seen) {
            $key = $listing->price . '|' . ($listing->tg_user_id ?? 0);
            if (in_array($key, $seen)) {
                return false;
            }
            $seen[] = $key;
            return true;
        })->take($limit);

        if ($unique->isEmpty()) {
            return new HtmlString('<span style="color:#888">Нет данных</span>');
        }

        $rows = $unique->map(function (Listing $listing) {
            $price   = number_format($listing->price, 0, '.', ' ');
            $user    = e($listing->tgUser?->display_name ?? $listing->tgUser?->username ?? '—');
            $date    = $listing->posted_at?->format('d.m.Y H:i') ?? '';
            $tgLink  = $listing->tgMessage?->tg_link;

            $dateHtml = $tgLink
                ? "<a href=\"{$tgLink}\" target=\"_blank\" style=\"color:#888;text-decoration:none\">{$date}</a>"
                : "<span>{$date}</span>";

            return "<tr>
                <td style='padding:3px 10px;font-weight:bold;color:#f0c040'>{$price} 💰</td>
                <td style='padding:3px 10px;color:#7ec8e3'>{$user}</td>
                <td style='padding:3px 10px;font-size:0.85em'>{$dateHtml}</td>
            </tr>";
        })->implode('');

        return new HtmlString("
            <table style='border-collapse:collapse;width:100%'>
                <thead>
                    <tr style='border-bottom:1px solid #333'>
                        <th style='padding:3px 10px;text-align:left;color:#aaa;font-size:0.85em'>Цена</th>
                        <th style='padding:3px 10px;text-align:left;color:#aaa;font-size:0.85em'>Игрок</th>
                        <th style='padding:3px 10px;text-align:left;color:#aaa;font-size:0.85em'>Дата</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
        ");
    }
}
