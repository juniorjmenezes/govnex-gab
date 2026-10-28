<?php

use App\Enums\OfficeModule;
use App\Enums\ReminderStatus;
use App\Jobs\FetchRssSource;
use App\Jobs\ProcessAppointmentReminder;
use App\Models\AppointmentReminder;
use App\Models\RssSource;
use App\Services\Demands\DemandNotificationService;
use App\Services\Modules\OfficeModuleManager;
use App\Services\WhatsApp\WhatsAppDeadlineDigestService;
use App\Services\WhatsApp\WhatsAppSensitiveDataPurger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $officeIds = app(OfficeModuleManager::class)->activeOfficeIds(OfficeModule::Schedule);
    if ($officeIds === []) {
        return;
    }

    AppointmentReminder::withoutGlobalScopes()
        ->whereIn('gabinete_id', $officeIds)
        ->where('ativo', true)
        ->where('status', ReminderStatus::Pending)
        ->where('agendado_para', '<=', now())
        ->orderBy('id')
        ->limit(500)
        ->pluck('id')
        ->each(fn (int $id) => ProcessAppointmentReminder::dispatch($id));
})->name('dispatch-appointment-reminders')->everyMinute()->withoutOverlapping(5);

Schedule::call(fn () => app(DemandNotificationService::class)->dispatchAttention())
    ->name('dispatch-demand-attention-notifications')
    ->hourly()
    ->withoutOverlapping(60);

Schedule::call(fn () => app(WhatsAppDeadlineDigestService::class)->dispatchDue())
    ->name('dispatch-whatsapp-deadline-digests')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::call(fn () => app(WhatsAppSensitiveDataPurger::class)->purge())
    ->name('purge-whatsapp-sensitive-data')
    ->dailyAt('03:35')
    ->withoutOverlapping(30);

// Fora da janela do 1º turno até alguns dias depois do último turno o comando não faz
// nada (ver TallyElections) — por isso pode rodar o ano inteiro.
Schedule::command('politics:tally-elections')
    ->hourly()
    ->withoutOverlapping(60);

Schedule::call(function (): void {
    RssSource::query()
        ->where('ativo', true)
        ->orderBy('id')
        ->pluck('id')
        ->each(fn (int $id) => FetchRssSource::dispatch($id)->onQueue('rss'));
})->name('fetch-rss-sources')->everyFifteenMinutes()->withoutOverlapping(15);
