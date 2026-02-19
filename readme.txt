php artisan make:filament-user
php artisan migrate
php artisan db:seed


composer require filament/filament:"^3.0"
php artisan filament:install --panels  # ID панели: admin
php artisan make:filament-user
php artisan migrate
php artisan db:seed
php artisan telegram:fetch --login     # интерактивно, один раз
php artisan telegram:fetch --days=30   # первичная загрузка


Перезапустить MadelineProto, если изменился конфиг/сервис (TelegramFetcher.php)
rm -rf storage/madeline/  # или где лежит session_path
php artisan telegram:fetch --login

Перезапустить парсер локально, без скачивания с телеграм
php artisan telegram:fetch --parse-only

Перезапустить миграции и запустить сидер с удалением всех данных
php artisan migrate:fresh --seed
