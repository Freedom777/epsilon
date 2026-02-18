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
     *   - users.csv
     *   - products_final.csv
     *   - products_aliases.csv
     *   - prices_clean.csv
     */

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Oleg Liubchenko',
            'email' => 'oleg.liubchenko.php@gmail.com',
        ]);

        $this->call([
            TgUsersSeeder::class,       // 706 пользователей
            ProductsSeeder::class,      // 498 товаров + 16 алиасов зелий
            InitialPricesSeeder::class, // базовая линия цен для детектора аномалий
        ]);
    }
}
