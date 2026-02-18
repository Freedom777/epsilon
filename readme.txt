php artisan make:filament-user
php artisan migrate
php artisan db:seed


composer require filament/filament:"^3.0"
composer require doctrine/dbal
php artisan filament:install --panels  # ID панели: admin
php artisan make:filament-user
php artisan migrate
php artisan db:seed
php artisan telegram:fetch --login     # интерактивно, один раз
php artisan telegram:fetch --days=30   # первичная загрузка