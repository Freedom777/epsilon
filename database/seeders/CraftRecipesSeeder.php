<?php

namespace Database\Seeders;

use App\Models\CraftRecipe;
use App\Models\CraftRecipeItemComponent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CraftRecipesSeeder extends BaseSeeder
{
    private string $recipesFile    = 'database/seeders/data/craft_recipes.csv';
    private string $componentsFile = 'database/seeders/data/craft_recipe_item_components.csv';

    public function run(): void
    {
        $this->command->info('Импорт рецептов крафта...');

        $recipes    = $this->readCsv($this->recipesFile);
        $components = $this->readCsv($this->componentsFile);

        // Группируем компоненты по craft_recipe_id из CSV
        $componentsByRecipe = [];
        foreach ($components as $comp) {
            $componentsByRecipe[(int) $comp['craft_recipe_id']][] = $comp;
        }

        $imported = 0;

        DB::transaction(function () use ($recipes, $componentsByRecipe, &$imported) {
            foreach ($recipes as $row) {
                $csvId   = (int) $row['id'];
                $itemId  = !empty($row['item_id']) ? (int) $row['item_id'] : null;
                $assetId = !empty($row['asset_id']) ? (int) $row['asset_id'] : null;
                $npcId   = !empty($row['npc_id']) ? (int) $row['npc_id'] : null;

                if (!$itemId && !$assetId) {
                    $this->command->warn("  Skipped #{$csvId}: no item_id or asset_id");
                    continue;
                }

                $recipe = CraftRecipe::create([
                    'item_id'      => $itemId,
                    'asset_id'     => $assetId,
                    'npc_id'       => $npcId,
                    'craft_level'  => trim($row['craft_level']) ?: null,
                    'energy_cost'  => !empty($row['energy_cost']) ? (int) $row['energy_cost'] : null,
                ]);

                foreach ($componentsByRecipe[$csvId] ?? [] as $comp) {
                    $compAssetId = !empty($comp['asset_id']) ? (int) $comp['asset_id'] : null;

                    CraftRecipeItemComponent::create([
                        'craft_recipe_id' => $recipe->id,
                        'asset_id'        => $compAssetId,
                        'quantity'         => (int) $comp['quantity'],
                    ]);
                }

                $imported++;
            }
        });

        $this->command->info("  Импортировано рецептов: {$imported}, компонентов: " . array_sum(array_map('count', $componentsByRecipe)));
    }
}
