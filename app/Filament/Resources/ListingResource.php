<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ListingResource\Pages;
use App\Models\Listing;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static \BackedEnum|string|null $navigationIcon  = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationLabel = 'Аномальные цены';
    protected static \UnitEnum|string|null $navigationGroup = 'Модерация';
    protected static ?int    $navigationSort  = 2;

    public static function getNavigationBadge(): ?string
    {
        return (string) Listing::where('status', 'suspicious')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->where('status', 'suspicious')
                ->with(['asset', 'item', 'tgUser', 'tgMessage'])
            )
            ->columns([
                // Название товара — из asset или item
                Tables\Columns\TextColumn::make('product_title')
                    ->label('Товар')
                    ->getStateUsing(fn (Listing $record): string =>
                        $record->asset?->title ?? $record->item?->title ?? '—'
                    )
                    ->limit(35),

                Tables\Columns\TextColumn::make('source_type')
                    ->label('Тип')
                    ->getStateUsing(fn (Listing $record): string =>
                        $record->asset_id ? 'Расходник' : ($record->item_id ? 'Экипировка' : '—')
                    )
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Расходник'  => 'success',
                        'Экипировка' => 'info',
                        default      => 'gray',
                    }),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->color(fn (string $state) => $state === 'sell' ? 'success' : 'info')
                    ->formatStateUsing(fn (string $state) => $state === 'sell' ? 'Продажа' : 'Покупка'),

                Tables\Columns\TextColumn::make('price')
                    ->label('Цена')
                    ->formatStateUsing(fn ($state, $record) =>
                        number_format($state, 0, '.', ' ') . ' ' .
                        ($record->currency === 'gold' ? '💰' : '🍪')
                    ),

                Tables\Columns\TextColumn::make('anomaly_reason')
                    ->label('Причина')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->anomaly_reason),

                Tables\Columns\TextColumn::make('tgUser.display_name')
                    ->label('Автор')
                    ->url(fn ($record) => $record->tgUser?->tg_link)
                    ->openUrlInNewTab()
                    ->default('—'),

                Tables\Columns\TextColumn::make('tgMessage.raw_text')
                    ->label('Текст объявления')
                    ->limit(80)
                    ->tooltip(fn ($record) => $record->tgMessage?->raw_text)
                    ->wrap(),

                Tables\Columns\TextColumn::make('tgMessage.tg_link')
                    ->label('Ссылка')
                    ->url(fn ($record) => $record->tgMessage?->tg_link)
                    ->openUrlInNewTab()
                    ->default('—'),

                Tables\Columns\TextColumn::make('posted_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Тип')
                    ->options(['sell' => 'Продажа', 'buy' => 'Покупка']),

                Tables\Filters\SelectFilter::make('currency')
                    ->label('Валюта')
                    ->options(['gold' => '💰 Золото', 'cookie' => '🍪 Печенье']),
            ])
            ->defaultSort('posted_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Норма')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->action(function (Listing $record) {
                        $record->update(['status' => 'ok', 'anomaly_reason' => null]);
                        Notification::make()->title('Цена подтверждена как норма')->success()->send();
                    }),

                Tables\Actions\Action::make('invalidate')
                    ->label('Ошибка')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Listing $record) {
                        $record->update(['status' => 'invalid']);
                        Notification::make()->title('Помечено как ошибка парсинга')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulk_approve')
                    ->label('Подтвердить как норму')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->action(fn ($records) => $records->each->update(['status' => 'ok', 'anomaly_reason' => null])),

                Tables\Actions\BulkAction::make('bulk_invalidate')
                    ->label('Пометить как ошибку')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->action(fn ($records) => $records->each->update(['status' => 'invalid'])),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListListings::route('/'),
        ];
    }
}
