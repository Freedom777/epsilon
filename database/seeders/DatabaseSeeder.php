<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Порядок важен: сначала пользователи и товары, потом цены
     * (listings ссылаются на products и tg_users).
     *
     * CSV-файлы должны лежать в database/seeders/data/:
     * assets.csv
     * cities.csv
     * items.csv
     * locations.csv
     * mob_drop_assets.csv
     * mob_drop_items.csv
     * mobs.csv
     * users.csv
 */

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Oleg Liubchenko',
            'email' => 'oleg.liubchenko.php@gmail.com',
        ]);

        $this->call([
            CitiesSeeder::class,        // города
            LocationsSeeder::class,     // локации
            AssetsSeeder::class,
            ItemsSeeder::class,
            MobsSeeder::class,
            TgUsersSeeder::class,       // 706 пользователей
            // InitialPricesSeeder::class, // базовая линия цен для детектора аномалий
            LinkTablesSeeder::class,    // таблицы связей (дроп предметов и ресурсов с мобов)
        ]);
    }
}
