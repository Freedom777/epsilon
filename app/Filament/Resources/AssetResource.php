<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AssetResource\Pages;
use App\Models\Asset;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static ?string $navigationIcon  = 'heroicon-o-beaker';
    protected static ?string $navigationLabel = 'Расходники';
    protected static ?string $navigationGroup = 'Справочники';
    protected static ?int    $navigationSort  = 10;

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

                Tables\Columns\IconColumn::make('is_event')
                    ->label('Ивент')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('gray'),

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
                    ->options(fn () =>
                        Asset::whereNotNull('type')
                            ->distinct()
                            ->pluck('type', 'type')
                    ),

                Tables\Filters\SelectFilter::make('grade')
                    ->label('Грейд')
                    ->options(['I' => 'I', 'II' => 'II', 'III' => 'III', 'III+' => 'III+', 'IV' => 'IV']),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'ok'      => 'OK',
                        'empty'   => 'Пусто',
                        'error'   => 'Ошибка',
                        'process' => 'В обработке',
                    ]),

                Tables\Filters\TernaryFilter::make('is_event')
                    ->label('Ивент')
                    ->placeholder('Все')
                    ->trueLabel('Только ивентовые')
                    ->falseLabel('Без ивентовых'),

                Tables\Filters\Filter::make('no_type')
                    ->label('Без типа')
                    ->query(fn ($query) => $query->whereNull('type')),
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

                Forms\Components\TextInput::make('type')
                    ->label('Тип')
                    ->maxLength(50),

                Forms\Components\TextInput::make('subtype')
                    ->label('Подтип')
                    ->maxLength(50),

                Forms\Components\Select::make('grade')
                    ->label('Грейд')
                    ->options(['I' => 'I', 'II' => 'II', 'III' => 'III', 'III+' => 'III+', 'IV' => 'IV'])
                    ->placeholder('Без грейда'),

                Forms\Components\Toggle::make('is_event')
                    ->label('Ивентовый предмет')
                    ->default(false),

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
                    ->rows(4),

                Forms\Components\Textarea::make('drop_monster')
                    ->label('Дроп с монстров')
                    ->rows(4),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssets::route('/'),
            'edit'  => Pages\EditAsset::route('/{record}/edit'),
        ];
    }
}
