<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductsSeeder extends Seeder
{
    /**
     * Путь к CSV-файлам относительно корня проекта.
     */
    private string $productsFile = 'database/seeders/data/products_final.csv';
    private string $aliasesFile  = 'database/seeders/data/products_aliases.csv';

    public function run(): void
    {
        $this->command->info('Импорт основных товаров...');
        $this->importProducts();

        $this->command->info('Импорт алиасов...');
        $this->importAliases();

        $this->command->info('Готово!');
    }

    // -------------------------------------------------------------------------
    // Шаг 1: основные товары
    // -------------------------------------------------------------------------

    private function importProducts(): void
    {
        $rows = $this->readCsv($this->productsFile);
        $imported = 0;
        $updated  = 0;

        foreach ($rows as $row) {
            $name  = trim($row['name']);
            $icon  = trim($row['icon'])   ?: null;
            $grade = trim($row['grades']) ?: null;
            $norm  = trim($row['normalized_name']) ?: $this->normalize($name);

            if (empty($name)) {
                continue;
            }

            // Ищем существующий товар по normalized_name + grade
            $existing = DB::table('products')
                ->where('normalized_name', $norm)
                ->where('grade', $grade)
                ->first();

            if ($existing) {
                // Обновляем только пустые поля icon и grade (не перезаписываем!)
                $updates = [];
                if (empty($existing->icon) && $icon) {
                    $updates['icon'] = $icon;
                }
                if (empty($existing->grade) && $grade) {
                    $updates['grade'] = $grade;
                }
                if (!empty($updates)) {
                    $updates['updated_at'] = now();
                    DB::table('products')->where('id', $existing->id)->update($updates);
                    $updated++;
                }
                continue;
            }

            DB::table('products')->insert([
                'parent_id'       => null,
                'icon'            => $icon,
                'name'            => $name,
                'normalized_name' => $norm,
                'grade'           => $grade,
                'type'            => null,    // заполнится вручную позже
                'status'          => 'ok',
                'is_verified'     => false,   // по умолчанию false, до проверки по игровой БД
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            $imported++;
        }

        $this->command->info("  Добавлено: {$imported}, обновлено: {$updated}");
    }

    // -------------------------------------------------------------------------
    // Шаг 2: алиасы (сокращённые названия → parent_id на основной товар)
    // -------------------------------------------------------------------------

    private function importAliases(): void
    {
        $rows = $this->readCsv($this->aliasesFile);
        $imported = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            $name     = trim($row['name']);
            $icon     = trim($row['icon'])     ?: null;
            $grade    = trim($row['grades'])   ?: null;
            $aliasOf  = trim($row['alias_of']) ?: null;
            $norm     = trim($row['normalized_name']) ?: $this->normalize($name);

            if (empty($name) || empty($aliasOf)) {
                $skipped++;
                continue;
            }

            // Находим родительский товар по alias_of + grade
            $parent = DB::table('products')
                ->where('name', $aliasOf)
                ->where('grade', $grade)
                ->first();

            if (!$parent) {
                $this->command->warn("  Родитель не найден: \"{$aliasOf}\" [{$grade}] для алиаса \"{$name}\"");
                $skipped++;
                continue;
            }

            // Не создаём дубли
            $exists = DB::table('products')
                ->where('normalized_name', $norm)
                ->where('grade', $grade)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            DB::table('products')->insert([
                'parent_id'       => $parent->id,
                'icon'            => $icon,
                'name'            => $name,
                'normalized_name' => $norm,
                'grade'           => $grade,
                'type'            => $parent->type,  // наследуем тип от родителя
                'status'          => 'ok',
                'is_verified'     => false,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            $imported++;
        }

        $this->command->info("  Добавлено: {$imported}, пропущено: {$skipped}");
    }

    // -------------------------------------------------------------------------
    // Хелперы
    // -------------------------------------------------------------------------

    private function readCsv(string $relativePath): array
    {
        $path = base_path($relativePath);

        if (!file_exists($path)) {
            $this->command->error("Файл не найден: {$path}");
            return [];
        }

        $rows = [];
        $handle = fopen($path, 'r');
        $headers = null;

        while (($line = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map('trim', $line);
                continue;
            }
            if (count($line) === count($headers)) {
                $rows[] = array_combine($headers, $line);
            }
        }

        fclose($handle);
        return $rows;
    }

    private function normalize(string $name): string
    {
        // Убираем эмодзи
        $name = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27FF}\x{2300}-\x{23FF}\x{FE00}-\x{FEFF}]+/u', '', $name);
        // Убираем лишние пробелы и приводим к нижнему регистру
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }
}
