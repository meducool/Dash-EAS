<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('kpi:snapshot')->dailyAt('00:05');
Schedule::command('supplier-prices:refresh')->dailyAt('06:00');
// Online shop: catalogue read + proposals after the supplier price refresh (publishes nothing).
Schedule::command('webstore:prepare')->dailyAt('06:30')->withoutOverlapping();
