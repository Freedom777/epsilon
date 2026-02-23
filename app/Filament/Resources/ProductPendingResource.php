<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductPendingResource\Pages;
use App\Models\Asset;
use App\Models\Item;
use App\Models\ProductPending;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductPendingResource extends Resource
{
    protected static ?string $model = ProductPending::class;

    protected static ?string $navigationIcon  = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Очередь товаров';
    protected static ?string $navigationGroup = 'Модерация';
    protected static ?int    $navigationSort  = 1;

    public static function getNavigationBadge(): ?string
    {
        return (string) ProductPending::where('status', 'pending')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['approvedBy']))
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'merged'   => 'info',
                        default    => 'secondary',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending'  => '⏳',
                        'approved' => '✅',
                        'rejected' => '❌',
                        'merged'   => '🔗',
                        default    => $state,
                    })
                    ->tooltip(fn (string $state) => match ($state) {
                        'pending'  => 'Ожидает',
                        'approved' => 'Подтверждено',
                        'rejected' => 'Отклонено',
                        'merged'   => 'Объединено',
                        default    => $state,
                    })
                    ->width('60px'),

                Tables\Columns\TextColumn::make('raw_title')
                    ->label('Название из чата')
                    ->searchable()
                    ->wrap()
                    ->description(fn (ProductPending $record): string =>
                        $record->normalized_title !== $record->raw_title
                            ? $record->normalized_title
                            : ''
                    ),

                Tables\Columns\TextColumn::make('source_type')
                    ->label('Тип')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'item'  => 'info',
                        'asset' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'item'  => '🗡️',
                        'asset' => '🧪',
                        default => '?',
                    })
                    ->tooltip(fn (?string $state) => match ($state) {
                        'item'  => 'Экипировка',
                        'asset' => 'Расходник',
                        default => 'Не определено',
                    })
                    ->width('60px'),

                Tables\Columns\TextColumn::make('suggested_title')
                    ->label('Предполагаемый матч')
                    ->getStateUsing(function (ProductPending $record): string {
                        if (!$record->suggested_id || !$record->source_type) {
                            return '—';
                        }
                        if ($record->source_type === 'item') {
                            return Item::find($record->suggested_id)?->title ?? '—';
                        }
                        return Asset::find($record->suggested_id)?->title ?? '—';
                    })
                    ->wrap()
                    ->color(fn (string $state) => $state === '—' ? 'gray' : 'success')
                    ->description(fn (ProductPending $record): string =>
                        $record->match_score
                            ? number_format($record->match_score, 1) . '%'
                            : ''
                    ),

                Tables\Columns\TextColumn::make('occurrences')
                    ->label('×')
                    ->tooltip('Сколько раз встретилось')
                    ->sortable()
                    ->alignCenter()
                    ->width('50px'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m H:i')
                    ->sortable()
                    ->width('100px'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'pending'  => 'Ожидает',
                        'approved' => 'Подтверждено',
                        'rejected' => 'Отклонено',
                        'merged'   => 'Объединено',
                    ])
                    ->default('pending'),

                Tables\Filters\SelectFilter::make('source_type')
                    ->label('Тип')
                    ->options([
                        'item'  => 'Экипировка',
                        'asset' => 'Расходник',
                    ]),
            ])
            ->defaultSort('occurrences', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    // Подтвердить предложенный матч
                    Tables\Actions\Action::make('approve')
                        ->label('Подтвердить')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->visible(fn (ProductPending $record) => $record->status === 'pending' && $record->suggested_id)
                        ->action(function (ProductPending $record) {
                            $record->approve(auth()->id());
                            Notification::make()->title('Запись подтверждена')->success()->send();
                        }),

                    // Привязать к расходнику
                    Tables\Actions\Action::make('link_asset')
                        ->label('Привязать к расходнику')
                        ->icon('heroicon-o-beaker')
                        ->color('info')
                        ->visible(fn (ProductPending $record) => $record->status === 'pending')
                        ->form([
                            Forms\Components\Select::make('asset_id')
                                ->label('Расходник')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) =>
                                    Asset::where('title', 'like', "%{$search}%")
                                        ->where('status', 'ok')
                                        ->limit(20)
                                        ->pluck('title', 'id')
                                )
                                ->required(),
                        ])
                        ->action(function (ProductPending $record, array $data) {
                            $record->update([
                                'source_type'  => 'asset',
                                'suggested_id' => $data['asset_id'],
                            ]);
                            $record->approve(auth()->id());
                            Notification::make()->title('Привязано к расходнику')->success()->send();
                        }),

                    // Привязать к экипировке
                    Tables\Actions\Action::make('link_item')
                        ->label('Привязать к экипировке')
                        ->icon('heroicon-o-shield-check')
                        ->color('info')
                        ->visible(fn (ProductPending $record) => $record->status === 'pending')
                        ->form([
                            Forms\Components\Select::make('item_id')
                                ->label('Экипировка')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) =>
                                    Item::where('title', 'like', "%{$search}%")
                                        ->where('status', 'ok')
                                        ->limit(20)
                                        ->pluck('title', 'id')
                                )
                                ->required(),
                        ])
                        ->action(function (ProductPending $record, array $data) {
                            $record->update([
                                'source_type'  => 'item',
                                'suggested_id' => $data['item_id'],
                            ]);
                            $record->approve(auth()->id());
                            Notification::make()->title('Привязано к экипировке')->success()->send();
                        }),

                    // Отклонить
                    Tables\Actions\Action::make('reject')
                        ->label('Отклонить')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('admin_comment')
                                ->label('Комментарий')
                                ->placeholder('Причина отклонения...'),
                        ])
                        ->action(function (ProductPending $record, array $data) {
                            $record->reject(auth()->id(), $data['admin_comment'] ?? null);
                            Notification::make()->title('Запись отклонена')->danger()->send();
                        }),

                    // Удалить
                    Tables\Actions\Action::make('delete')
                        ->label('Удалить')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (ProductPending $record) {
                            $record->delete();
                            Notification::make()->title('Запись удалена')->success()->send();
                        }),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Действия'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulk_link_asset')
                    ->label('→ Расходник')
                    ->icon('heroicon-o-beaker')
                    ->color('info')
                    ->form([
                        Forms\Components\Select::make('asset_id')
                            ->label('Расходник')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) =>
                                Asset::where('title', 'like', "%{$search}%")
                                    ->where('status', 'ok')
                                    ->limit(20)
                                    ->pluck('title', 'id')
                            )
                            ->required(),
                    ])
                    ->action(function ($records, array $data) {
                        $records->each(function ($record) use ($data) {
                            $record->update([
                                'source_type'  => 'asset',
                                'suggested_id' => $data['asset_id'],
                            ]);
                            $record->approve(auth()->id());
                        });
                        Notification::make()
                            ->title("Привязано к расходнику ({$records->count()} шт)")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\BulkAction::make('bulk_link_item')
                    ->label('→ Экипировка')
                    ->icon('heroicon-o-shield-check')
                    ->color('info')
                    ->form([
                        Forms\Components\Select::make('item_id')
                            ->label('Экипировка')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) =>
                                Item::where('title', 'like', "%{$search}%")
                                    ->where('status', 'ok')
                                    ->limit(20)
                                    ->pluck('title', 'id')
                            )
                            ->required(),
                    ])
                    ->action(function ($records, array $data) {
                        $records->each(function ($record) use ($data) {
                            $record->update([
                                'source_type'  => 'item',
                                'suggested_id' => $data['item_id'],
                            ]);
                            $record->approve(auth()->id());
                        });
                        Notification::make()
                            ->title("Привязано к экипировке ({$records->count()} шт)")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\BulkAction::make('bulk_reject')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn ($records) => $records->each->reject(auth()->id())),

                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductPendings::route('/'),
        ];
    }
}
