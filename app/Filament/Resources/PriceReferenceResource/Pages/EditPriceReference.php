<?php

namespace App\Filament\Resources\PriceReferenceResource\Pages;

use App\Filament\Resources\PriceReferenceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPriceReference extends EditRecord
{
    protected static string $resource = PriceReferenceResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['is_manual'] = true;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
