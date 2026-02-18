<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TgUsersSeeder extends Seeder
{
    private string $usersFile = 'database/seeders/data/users.csv';

    public function run(): void
    {
        $this->command->info('Импорт пользователей...');

        $rows = $this->readCsv($this->usersFile);
        $imported = 0;
        $updated  = 0;

        foreach ($rows as $row) {
            $tgId       = (int) trim($row['tg_id']);
            $displayName = trim($row['display_name']) ?: null;
            $username   = trim($row['username'])    ?: null;
            $firstName  = trim($row['first_name'])  ?: null;
            $lastName   = trim($row['last_name'])   ?: null;

            if (!$tgId) {
                continue;
            }

            $existing = DB::table('tg_users')->where('tg_id', $tgId)->first();

            if ($existing) {
                // Обновляем только пустые поля
                $updates = [];
                if (empty($existing->display_name) && $displayName) {
                    $updates['display_name'] = $displayName;
                }
                if (empty($existing->username) && $username) {
                    $updates['username'] = $username;
                }
                if (empty($existing->first_name) && $firstName) {
                    $updates['first_name'] = $firstName;
                }
                if (empty($existing->last_name) && $lastName) {
                    $updates['last_name'] = $lastName;
                }
                if (!empty($updates)) {
                    $updates['updated_at'] = now();
                    DB::table('tg_users')->where('id', $existing->id)->update($updates);
                    $updated++;
                }
                continue;
            }

            DB::table('tg_users')->insert([
                'tg_id'        => $tgId,
                'username'     => $username,
                'display_name' => $displayName,
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            $imported++;
        }

        $this->command->info("  Добавлено: {$imported}, обновлено: {$updated}");
    }

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
}
