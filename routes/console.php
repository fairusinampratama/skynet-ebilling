<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Scheduled Commands
Schedule::command('billing:generate')
    ->monthlyOn(1, '00:00')
    ->description('Generate monthly invoices for active customers');

Schedule::command('billing:isolate')
    ->dailyAt('02:00')
    ->description('Isolate customers with 7+ day overdue invoices');

Schedule::command('queue:work --queue=network-enforcement --max-time=3600 --stop-when-empty')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->description('Process network enforcement queue');

Schedule::command('network:monitor')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->description('Monitor router connection and health stats');

Schedule::command('network:scan')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->description('Sync customer mapping and health from routers');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
