<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductPendingResource\Pages;
use App\Models\Product;
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

    // Бейдж с количеством непросмотренных
    public static function getNavigationBadge(): ?string
    {
        return (string) ProductPending::where('reviewed', false)->count() ?: null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    // =========================================================================
    // Таблица
    // =========================================================================

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'new'            => 'info',
                        'icon_conflict'  => 'warning',
                        'grade_conflict' => 'warning',
                        'missing_icon'   => 'gray',
                        'missing_grade'  => 'gray',
                        default          => 'secondary',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'new'            => 'Новый',
                        'icon_conflict'  => 'Конфликт иконки',
                        'grade_conflict' => 'Конфликт грейда',
                        'missing_icon'   => 'Нет иконки',
                        'missing_grade'  => 'Нет грейда',
                        default          => $state,
                    }),

                Tables\Columns\TextColumn::make('icon')
                    ->label('Иконка')
                    ->default('—'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('grade')
                    ->label('Грейд')
                    ->default('—'),

                // Текущие данные в БД (для конфликтов)
                Tables\Columns\TextColumn::make('product.icon')
                    ->label('Иконка в БД')
                    ->default('—')
                    ->visible(fn ($livewire) => in_array(
                        $livewire->tableFilters['status']['value'] ?? '',
                        ['icon_conflict', 'grade_conflict']
                    )),

                Tables\Columns\TextColumn::make('product.grade')
                    ->label('Грейд в БД')
                    ->default('—'),

                // Полный текст объявления
                Tables\Columns\TextColumn::make('message.raw_text')
                    ->label('Текст объявления')
                    ->limit(80)
                    ->tooltip(fn ($record) => $record->message?->raw_text)
                    ->wrap(),

                // Ссылка на сообщение в TG
                Tables\Columns\TextColumn::make('message.tg_link')
                    ->label('Ссылка')
                    ->url(fn ($record) => $record->message?->tg_link)
                    ->openUrlInNewTab()
                    ->default('—'),

                Tables\Columns\IconColumn::make('reviewed')
                    ->label('Просмотрено')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Добавлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'new'            => 'Новый',
                        'icon_conflict'  => 'Конфликт иконки',
                        'grade_conflict' => 'Конфликт грейда',
                        'missing_icon'   => 'Нет иконки',
                        'missing_grade'  => 'Нет грейда',
                    ]),

                Tables\Filters\TernaryFilter::make('reviewed')
                    ->label('Просмотрено')
                    ->placeholder('Все')
                    ->trueLabel('Просмотрено')
                    ->falseLabel('Не просмотрено')
                    ->default(false),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                // Подтвердить — переносим в products
                Tables\Actions\Action::make('approve')
                    ->label('Подтвердить')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (ProductPending $record) => $record->status === 'new')
                    ->action(function (ProductPending $record) {
                        Product::create([
                            'icon'            => $record->icon,
                            'name'            => $record->name,
                            'normalized_name' => $record->normalized_name,
                            'grade'           => $record->grade,
                            'status'          => 'ok',
                            'is_verified'     => false,
                        ]);
                        $record->update(['reviewed' => true]);
                        Notification::make()->title('Товар добавлен в справочник')->success()->send();
                    }),

                // Назначить алиасом
                Tables\Actions\Action::make('make_alias')
                    ->label('Алиас')
                    ->icon('heroicon-o-link')
                    ->color('info')
                    ->visible(fn (ProductPending $record) => $record->status === 'new')
                    ->form([
                        Forms\Components\Select::make('parent_id')
                            ->label('Основной товар')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) =>
                                Product::where('name', 'like', "%{$search}%")
                                    ->whereNull('parent_id')
                                    ->limit(20)
                                    ->pluck('name', 'id')
                            )
                            ->required(),
                    ])
                    ->action(function (ProductPending $record, array $data) {
                        Product::create([
                            'parent_id'       => $data['parent_id'],
                            'icon'            => $record->icon,
                            'name'            => $record->name,
                            'normalized_name' => $record->normalized_name,
                            'grade'           => $record->grade,
                            'status'          => 'ok',
                            'is_verified'     => false,
                        ]);
                        $record->update(['reviewed' => true]);
                        Notification::make()->title('Алиас создан')->success()->send();
                    }),

                // Применить иконку из объявления
                Tables\Actions\Action::make('use_new_icon')
                    ->label('Применить иконку')
                    ->icon('heroicon-o-photo')
                    ->color('warning')
                    ->visible(fn (ProductPending $record) => $record->status === 'icon_conflict')
                    ->action(function (ProductPending $record) {
                        $record->product?->update(['icon' => $record->icon]);
                        $record->update(['reviewed' => true]);
                        Notification::make()->title('Иконка обновлена')->success()->send();
                    }),

                // Вручную ввести иконку (для missing_icon)
                Tables\Actions\Action::make('set_icon')
                    ->label('Задать иконку')
                    ->icon('heroicon-o-pencil')
                    ->color('warning')
                    ->visible(fn (ProductPending $record) => $record->status === 'missing_icon')
                    ->form([
                        Forms\Components\TextInput::make('icon')
                            ->label('Иконка (эмодзи)')
                            ->required(),
                    ])
                    ->action(function (ProductPending $record, array $data) {
                        $record->product?->update(['icon' => $data['icon']]);
                        $record->update(['reviewed' => true]);
                        Notification::make()->title('Иконка задана')->success()->send();
                    }),

                // Задать грейд (для missing_grade и grade_conflict)
                Tables\Actions\Action::make('set_grade')
                    ->label('Задать грейд')
                    ->icon('heroicon-o-pencil')
                    ->color('warning')
                    ->visible(fn (ProductPending $record) => in_array($record->status, ['missing_grade', 'grade_conflict']))
                    ->form([
                        Forms\Components\Select::make('grade')
                            ->label('Грейд')
                            ->options(['I' => 'I', 'II' => 'II', 'III' => 'III', 'III+' => 'III+', 'IV' => 'IV', 'V' => 'V'])
                            ->required(),
                    ])
                    ->action(function (ProductPending $record, array $data) {
                        $record->product?->update(['grade' => $data['grade']]);
                        $record->update(['reviewed' => true]);
                        Notification::make()->title('Грейд задан')->success()->send();
                    }),

                // Пропустить / удалить мусор
                Tables\Actions\Action::make('dismiss')
                    ->label('Удалить')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (ProductPending $record) {
                        $record->delete();
                        Notification::make()->title('Запись удалена')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('mark_reviewed')
                    ->label('Пометить просмотренными')
                    ->icon('heroicon-o-check')
                    ->action(fn ($records) => $records->each->update(['reviewed' => true])),

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
