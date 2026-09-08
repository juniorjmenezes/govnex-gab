<?php

use App\Http\Controllers\Admin\EntidadeController as AdminEntidadeController;
use App\Http\Controllers\Admin\GlobalPoliticalDataSyncController;
use App\Http\Controllers\Admin\GovnexApiIntegrationController;
use App\Http\Controllers\Admin\OfficeController;
use App\Http\Controllers\Admin\OfficePoliticalDataSyncController;
use App\Http\Controllers\Admin\PartyColorController;
use App\Http\Controllers\Admin\PollCurationController;
use App\Http\Controllers\Admin\RootUserController;
use App\Http\Controllers\Admin\RssSourceController;
use App\Http\Controllers\Admin\SystemCheckController;
use App\Http\Controllers\Admin\WhatsAppController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EntidadeController;
use App\Http\Controllers\EntidadeDirectoryController;
use App\Http\Controllers\EntidadeInvitationAcceptController;
use App\Http\Controllers\EntidadeInvitationController;
use App\Http\Controllers\GabineteTransferController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('convites/entidade/{credential}', [EntidadeInvitationAcceptController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('entidade-invitations.show');
Route::post('convites/entidade/{credential}', [EntidadeInvitationAcceptController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('entidade-invitations.accept');

Route::middleware(['auth', 'verified', 'user.active'])->group(function () {
    Route::get('dashboard', DashboardController::class)
        ->middleware(['gabinete.active', 'tenant.legacy'])
        ->name('dashboard');
    Route::get('entidades', EntidadeDirectoryController::class)->name('entidades.index');

    Route::middleware('root')->prefix('admin')->name('admin.')->group(function () {
        Route::get('entidades/nova', [AdminEntidadeController::class, 'create'])->name('entities.create');
        Route::post('entidades', [AdminEntidadeController::class, 'store'])->name('entities.store');
        Route::get('gabinetes', [OfficeController::class, 'index'])->name('offices.index');
        Route::get('gabinetes/novo', [OfficeController::class, 'create'])->name('offices.create');
        Route::get('gabinetes/{office}/editar', [OfficeController::class, 'edit'])->name('offices.edit');
        Route::post('gabinetes', [OfficeController::class, 'store'])->name('offices.store');
        Route::put('gabinetes/{office}', [OfficeController::class, 'update'])->name('offices.update');
        Route::patch('gabinetes/{office}/modulos', [OfficeController::class, 'updateModules'])
            ->name('offices.modules.update');
        Route::patch('gabinetes/{office}/status', [OfficeController::class, 'updateStatus'])
            ->name('offices.status');
        Route::get('gabinetes/{office}/sincronizacoes-tse', [OfficePoliticalDataSyncController::class, 'show'])
            ->name('offices.political-sync.show');
        Route::post('gabinetes/{office}/sincronizacoes-tse', [OfficePoliticalDataSyncController::class, 'store'])
            ->name('offices.political-sync.store');
        Route::post(
            'gabinetes/{office}/sincronizacoes-tse/{sync}/reiniciar',
            [OfficePoliticalDataSyncController::class, 'restart'],
        )->name('offices.political-sync.restart');
        Route::post(
            'gabinetes/{office}/sincronizacoes-tse/{sync}/cancelar',
            [OfficePoliticalDataSyncController::class, 'cancel'],
        )->name('offices.political-sync.cancel');
        Route::post('sincronizacoes-tse-globais/upload', [GlobalPoliticalDataSyncController::class, 'upload'])
            ->name('global-political-sync.upload');
        Route::post(
            'sincronizacoes-tse-globais/eleitorado/govnex-api',
            [GlobalPoliticalDataSyncController::class, 'syncElectorateFromGovnexApi'],
        )->name('global-political-sync.electorate-govnex-api');
        Route::post('sincronizacoes-tse-globais/fallback-automatico', [GlobalPoliticalDataSyncController::class, 'fallback'])
            ->name('global-political-sync.fallback');
        Route::post(
            'sincronizacoes-tse-globais/{sync}/cancelar',
            [GlobalPoliticalDataSyncController::class, 'cancel'],
        )->name('global-political-sync.cancel');
        Route::get('pesquisas-eleitorais', [PollCurationController::class, 'index'])->name('polls.index');
        Route::get('pesquisas-eleitorais/nova', [PollCurationController::class, 'create'])->name('polls.create');
        Route::get('pesquisas-eleitorais/candidatos', [PollCurationController::class, 'candidates'])
            ->name('polls.candidates');
        Route::post('pesquisas-eleitorais', [PollCurationController::class, 'store'])->name('polls.store');
        Route::post('pesquisas-eleitorais/{pesquisa}/resultados', [PollCurationController::class, 'updateResultados'])
            ->name('polls.results.update');
        Route::delete('pesquisas-eleitorais/{pesquisa}', [PollCurationController::class, 'destroy'])
            ->name('polls.destroy');
        Route::get('cores-partidos', [PartyColorController::class, 'index'])->name('party-colors.index');
        Route::post('cores-partidos', [PartyColorController::class, 'store'])->name('party-colors.store');
        Route::patch('cores-partidos/{partidoCor}', [PartyColorController::class, 'update'])
            ->name('party-colors.update');
        Route::delete('cores-partidos/{partidoCor}', [PartyColorController::class, 'destroy'])
            ->name('party-colors.destroy');
        Route::get('fontes-rss', [RssSourceController::class, 'index'])->name('rss-sources.index');
        Route::post('fontes-rss', [RssSourceController::class, 'store'])->name('rss-sources.store');
        Route::patch('fontes-rss/{fonteRss}', [RssSourceController::class, 'update'])
            ->name('rss-sources.update');
        Route::delete('fontes-rss/{fonteRss}', [RssSourceController::class, 'destroy'])
            ->name('rss-sources.destroy');
        Route::post('fontes-rss/{fonteRss}/coletar', [RssSourceController::class, 'collect'])
            ->middleware('throttle:10,1')
            ->name('rss-sources.collect');
        Route::get('whatsapp', [WhatsAppController::class, 'index'])->name('whatsapp.index');
        Route::post('whatsapp/entidades/{entidade}/contas/consultar', [WhatsAppController::class, 'consultAccounts'])
            ->middleware('throttle:12,1')
            ->name('whatsapp.connections.accounts');
        Route::post('whatsapp/entidades/{entidade}/conexao', [WhatsAppController::class, 'assignConnection'])
            ->middleware('throttle:10,1')
            ->name('whatsapp.connections.store');
        Route::delete('whatsapp/entidades/{entidade}/conexao', [WhatsAppController::class, 'deactivateConnection'])
            ->middleware('throttle:10,1')
            ->name('whatsapp.connections.destroy');
        Route::put('whatsapp/gabinetes/{office}', [WhatsAppController::class, 'updateConfiguration'])
            ->name('whatsapp.configuration.update');
        Route::patch('whatsapp/contatos/{contact}/piloto', [WhatsAppController::class, 'updatePilot'])
            ->name('whatsapp.contacts.pilot');
        Route::post('whatsapp/templates', [WhatsAppController::class, 'createTemplate'])
            ->middleware('throttle:10,1')
            ->name('whatsapp.templates.store');
        Route::patch('whatsapp/templates/{template}', [WhatsAppController::class, 'updateTemplate'])
            ->middleware('throttle:10,1')
            ->name('whatsapp.templates.update');
        Route::post('whatsapp/templates/{template}/nova-versao', [WhatsAppController::class, 'createTemplateVersion'])
            ->middleware('throttle:6,1')
            ->name('whatsapp.templates.version.store');
        Route::post('whatsapp/templates/{template}/submeter', [WhatsAppController::class, 'submitTemplate'])
            ->middleware('throttle:6,1')
            ->name('whatsapp.templates.submit');
        Route::post('whatsapp/templates/sincronizar', [WhatsAppController::class, 'syncTemplates'])
            ->middleware('throttle:12,1')
            ->name('whatsapp.templates.sync');
        Route::patch('whatsapp/templates/{template}/ativacao', [WhatsAppController::class, 'updateTemplateStatus'])
            ->name('whatsapp.templates.status');

        Route::get('usuarios', [RootUserController::class, 'index'])->name('users.index');
        Route::post('usuarios', [RootUserController::class, 'store'])->name('users.store');
        Route::patch('usuarios/{rootUsuario}', [RootUserController::class, 'update'])->name('users.update');
        Route::post('usuarios/{rootUsuario}/redefinir-senha', [RootUserController::class, 'resetPassword'])
            ->name('users.reset-password');
        Route::delete('usuarios/{rootUsuario}', [RootUserController::class, 'destroy'])->name('users.destroy');

        Route::get('integracoes/govnex-api', [GovnexApiIntegrationController::class, 'edit'])
            ->name('integrations.govnex-api.edit');
        Route::put('integracoes/govnex-api', [GovnexApiIntegrationController::class, 'update'])
            ->name('integrations.govnex-api.update');
        Route::post('integracoes/govnex-api/testar', [GovnexApiIntegrationController::class, 'test'])
            ->middleware('throttle:10,1')
            ->name('integrations.govnex-api.test');

        Route::get('sistema', [SystemCheckController::class, 'index'])->name('system.index');
        Route::post('sistema/teste-upload', [SystemCheckController::class, 'testUpload'])->name('system.test-upload');
    });
});

Route::prefix('entidades/{entidade}')
    ->name('entidades.')
    ->middleware(['auth', 'verified', 'user.active', 'entidade.context'])
    ->group(function (): void {
        Route::get('/', [EntidadeController::class, 'show'])->name('show');
        Route::patch('/', [EntidadeController::class, 'update'])->name('update');
        Route::post('identidade', [EntidadeController::class, 'updateIdentity'])->name('identity.update');
        Route::post('bairros', [EntidadeController::class, 'storeNeighborhood'])->name('neighborhoods.store');
        Route::patch('bairros/{neighborhood}', [EntidadeController::class, 'updateNeighborhood'])
            ->name('neighborhoods.update');
        Route::patch('modulos', [EntidadeController::class, 'updateModules'])->name('modules.update');
        Route::post('convites', [EntidadeInvitationController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('invitations.store');

        Route::prefix('transferencias')
            ->name('transfers.')
            ->group(function (): void {
                Route::get('/', [GabineteTransferController::class, 'index'])->name('index');
                Route::post('/', [GabineteTransferController::class, 'store'])->name('store');
                Route::get('{transfer}', [GabineteTransferController::class, 'show'])->name('show');
                Route::post('{transfer}/aceitar', [GabineteTransferController::class, 'accept'])->name('accept');
                Route::post('{transfer}/encerrar', [GabineteTransferController::class, 'reject'])->name('reject');
                Route::post('{transfer}/aprovar', [GabineteTransferController::class, 'approve'])->name('approve');
            });
    });

Route::prefix('entidades/{entidade}/gabinetes/{gabinete}')
    ->name('context.')
    ->middleware(['auth', 'verified', 'user.active', 'entidade.context', 'gabinete.active', 'tenant.user'])
    ->group(function (): void {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

require __DIR__.'/settings.php';
require __DIR__.'/tenant.php';
