<?php

Schedule::command('telegram:fetch')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->then(fn() => Artisan::call('telegram:parse'))
    ->then(fn() => Artisan::call('market:generate'));
