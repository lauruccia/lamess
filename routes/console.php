<?php

use App\Domain\Plan\PlanVersionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('lamess:backup')->dailyAt('02:15')->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::command('lamess:canoni')->dailyAt('03:30')->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::command('lamess:fatture')->hourly()->withoutOverlapping(120);
// Invio in blocco degli XML (modalità «in blocco»): il 1° del mese; se l'email è spenta non fa nulla e le fatture restano in coda.
Schedule::command('lamess:fatture --tutte')->monthlyOn(1, '08:30')->timezone('Europe/Rome')->withoutOverlapping(120)->when(fn () => \App\Domain\Billing\InvoiceService::batchMode());
Schedule::call(fn () => app(PlanVersionService::class)->promoteDue())->name('lamess:piano-in-vigore')->dailyAt('00:10')->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::command('lamess:abbonamenti')->dailyAt('04:00')->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::command('lamess:abbonamenti-riepilogo')->dailyAt('08:15')->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::command('lamess:pagamenti')->dailyAt('05:00')->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::call(fn () => Cache::forever('lamess.scheduler.heartbeat', now()->toIso8601String()))->name('lamess:heartbeat')->everyMinute();
Schedule::command('lamess:ricalcolo-notturno')->dailyAt('02:45')->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::command('lamess:chiusura-mese')->hourly()->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::command('lamess:bonus-struttura')->hourly()->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::command('lamess:estratti')->dailyAt('06:00')->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::command('lamess:kyc-promemoria')->dailyAt('08:00')->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::command('lamess:sanzioni')->dailyAt('00:20')->timezone('Europe/Rome')->withoutOverlapping(120);
Schedule::command('lamess:successioni')->dailyAt('00:30')->timezone('Europe/Rome')->withoutOverlapping(120);
// Coda delle email: parte solo se QUEUE_CONNECTION non è «sync» (nel .env). Un giro al minuto, si ferma quando la coda è vuota.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->name('lamess:coda')->everyMinute()->withoutOverlapping(5)->when(fn () => config('queue.default') !== 'sync');
