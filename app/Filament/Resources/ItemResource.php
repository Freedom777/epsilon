<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ItemResource\Pages;
use App\Models\Item;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static ?string $navigationIcon  = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Экипировка';
    protected static ?string $navigationGroup = 'Справочники';
    protected static ?int    $navigationSort  = 11;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('normalized_title')
                    ->label('Нормализовано')
                    ->limit(40)
                    ->color('gray'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->color('info')
                    ->default('—'),

                Tables\Columns\TextColumn::make('subtype')
                    ->label('Подтип')
                    ->default('—'),

                Tables\Columns\TextColumn::make('grade')
                    ->label('Грейд')
                    ->badge()
                    ->default('—'),

                Tables\Columns\TextColumn::make('rarity')
                    ->label('Редкость')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'легендарная' => 'warning',
                        'эпическая'   => 'purple',
                        'редкая'      => 'info',
                        'необычная'   => 'success',
                        'обычная'     => 'gray',
                        default       => 'secondary',
                    })
                    ->default('—'),

                Tables\Columns\IconColumn::make('personal')
                    ->label('Персональный')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('price')
                    ->label('Цена продажи')
                    ->default('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'ok'    => 'success',
                        'empty' => 'gray',
                        'error' => 'danger',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('listings_count')
                    ->label('Объявлений')
                    ->counts('listings')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        'аксессуар' => 'Аксессуар',
                        'доспех'    => 'Доспех',
                        'инструмент'=> 'Инструмент',
                        'колье'     => 'Колье',
                        'кольцо'    => 'Кольцо',
                        'оружие'    => 'Оружие',
                        'перчатки'  => 'Перчатки',
                        'реликвия'  => 'Реликвия',
                        'сапоги'    => 'Сапоги',
                        'талисман'  => 'Талисман',
                        'шлем'      => 'Шлем',
                        'щит'       => 'Щит',
                    ]),

                Tables\Filters\SelectFilter::make('grade')
                    ->label('Грейд')
                    ->options(['I' => 'I', 'II' => 'II', 'III' => 'III', 'III+' => 'III+', 'IV' => 'IV']),

                Tables\Filters\SelectFilter::make('rarity')
                    ->label('Редкость')
                    ->options([
                        'обычная'     => 'Обычная',
                        'необычная'   => 'Необычная',
                        'редкая'      => 'Редкая',
                        'эпическая'   => 'Эпическая',
                        'легендарная' => 'Легендарная',
                    ]),

                Tables\Filters\TernaryFilter::make('personal')
                    ->label('Персональный')
                    ->placeholder('Все')
                    ->trueLabel('Только персональные')
                    ->falseLabel('Без персональных'),
            ])
            ->defaultSort('title')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Основное')->schema([
                Forms\Components\TextInput::make('title')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('normalized_title')
                    ->label('Нормализованное название')
                    ->maxLength(255),

                Forms\Components\Select::make('type')
                    ->label('Тип')
                    ->options([
                        'аксессуар' => 'Аксессуар',
                        'доспех'    => 'Доспех',
                        'инструмент'=> 'Инструмент',
                        'колье'     => 'Колье',
                        'кольцо'    => 'Кольцо',
                        'оружие'    => 'Оружие',
                        'перчатки'  => 'Перчатки',
                        'реликвия'  => 'Реликвия',
                        'сапоги'    => 'Сапоги',
                        'талисман'  => 'Талисман',
                        'шлем'      => 'Шлем',
                        'щит'       => 'Щит',
                    ]),

                Forms\Components\Select::make('subtype')
                    ->label('Подтип оружия')
                    ->options([
                        'кинжал двуручный'  => 'Кинжал двуручный',
                        'кирка'             => 'Кирка',
                        'лук'               => 'Лук',
                        'меч двуручный'     => 'Меч двуручный',
                        'молот двуручный'   => 'Молот двуручный',
                        'мотыга'            => 'Мотыга',
                        'одноручный кинжал' => 'Одноручный кинжал',
                        'одноручный меч'    => 'Одноручный меч',
                        'одноручный молот'  => 'Одноручный молот',
                        'одноручный топор'  => 'Одноручный топор',
                        'охотничий лук'     => 'Охотничий лук',
                        'парные кастеты'    => 'Парные кастеты',
                        'посох'             => 'Посох',
                        'топор двуручный'   => 'Топор двуручный',
                        'удочка'            => 'Удочка',
                    ])
                    ->placeholder('Не применимо'),

                Forms\Components\Select::make('grade')
                    ->label('Грейд')
                    ->options(['I' => 'I', 'II' => 'II', 'III' => 'III', 'III+' => 'III+', 'IV' => 'IV'])
                    ->placeholder('Без грейда'),

                Forms\Components\Select::make('rarity')
                    ->label('Редкость')
                    ->options([
                        'обычная'     => 'Обычная',
                        'необычная'   => 'Необычная',
                        'редкая'      => 'Редкая',
                        'эпическая'   => 'Эпическая',
                        'легендарная' => 'Легендарная',
                    ]),

                Forms\Components\Toggle::make('personal')
                    ->label('Персональный предмет')
                    ->default(false),

                Forms\Components\TextInput::make('price')
                    ->label('Цена продажи')
                    ->numeric(),

                Forms\Components\Select::make('status')
                    ->label('Статус')
                    ->options([
                        'ok'      => 'OK',
                        'empty'   => 'Пусто',
                        'error'   => 'Ошибка',
                        'process' => 'В обработке',
                    ])
                    ->default('ok')
                    ->required(),
            ])->columns(2),

            Forms\Components\Section::make('Описание')->schema([
                Forms\Components\Textarea::make('description')
                    ->label('Описание')
                    ->rows(3),

                Forms\Components\Textarea::make('extra')
                    ->label('Бонусы и требования')
                    ->rows(5),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListItems::route('/'),
            'edit'  => Pages\EditItem::route('/{record}/edit'),
        ];
    }
}
