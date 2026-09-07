<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recordatorio de membresía por vencer (hora Colombia): 8:00 a.m. y 4:00 p.m.
// del mismo día en que membresia_vence_at cae. withoutOverlapping evita que
// se solape con una corrida anterior si el envío de correos tarda.
Schedule::command('membresia:recordar-vencimiento')
    ->dailyAt('08:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

Schedule::command('membresia:recordar-vencimiento')
    ->dailyAt('16:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();
