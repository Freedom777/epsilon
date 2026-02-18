<?php

namespace App\Filament\Resources\ProductPendingResource\Pages;

use App\Filament\Resources\ProductPendingResource;
use Filament\Resources\Pages\ListRecords;

class ListProductPendings extends ListRecords
{
    protected static string $resource = ProductPendingResource::class;
}
