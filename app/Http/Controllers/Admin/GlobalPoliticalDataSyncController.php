<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncElectorateFromGovnexApiRequest;
use App\Http\Requests\Admin\SyncTseAutomaticFallbackRequest;
use App\Http\Requests\Admin\UploadTseManualDatasetRequest;
use App\Jobs\DownloadAndProcessTseDataset;
use App\Jobs\ProcessUploadedTseDataset;
use App\Jobs\SyncElectorateFromGovnexApi;
use App\Models\SincronizacaoTse;
use App\Services\Politics\TsePoliticalDataSyncService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

class GlobalPoliticalDataSyncController extends Controller
{
    public function fallback(
        SyncTseAutomaticFallbackRequest $request,
        TsePoliticalDataSyncService $service,
    ): RedirectResponse {
        $dataset = $request->validated('dataset');
        $year = TsePoliticalDataSyncService::datasetRequiresYear($dataset)
            ? (int) $request->validated('ano')
            : now()->year;
        $uf = $request->validated('uf') !== null
            ? mb_strtoupper($request->validated('uf'))
            : null;

        try {
            $service->assertDatasetPrerequisites($dataset, $year, $uf);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['dataset' => $exception->getMessage()]);
        }

        try {
            $queued = Cache::lock($this->enqueueLockKey($dataset, $year, $uf), 15)
                ->block(5, function () use ($dataset, $year, $uf, $request, $service): bool {
                    if ($this->hasActiveRun($dataset, $year, $uf)) {
                        return false;
                    }

                    $sourceUrl = $service->sourceUrl($dataset, $year, $uf);
                    $run = SincronizacaoTse::query()->create([
                        'gabinete_id' => null,
                        'solicitado_por_id' => $request->user()->id,
                        'dataset' => $dataset,
                        'ano' => $year,
                        'uf' => $uf,
                        'fonte_url' => $sourceUrl,
                        'situacao' => 'pendente',
                        'iniciada_em' => now(),
                    ]);

                    try {
                        DownloadAndProcessTseDataset::dispatch($run->id, $uf);
                    } catch (Throwable $exception) {
                        $run->update([
                            'situacao' => 'falhou',
                            'erro' => 'Não foi possível adicionar o download à fila: '.$exception->getMessage(),
                            'concluida_em' => now(),
                        ]);

                        throw $exception;
                    }

                    return true;
                });
        } catch (LockTimeoutException) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Outra solicitação deste dataset está sendo registrada. Tente novamente em alguns segundos.',
            ]);

            return back();
        }

        if (! $queued) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Este dataset já possui uma importação em andamento ou travada. Cancele-a antes de tentar de novo.',
            ]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Fallback automático adicionado à fila. Se o TSE bloquear o download, use o upload manual.',
        ]);

        return back();
    }

    /**
     * Dispara a sincronização do eleitorado via GOVNEX API — sem arquivo
     * nenhum, ao contrário de upload()/fallback() (ver GOVNEX_API_DATASETS).
     */
    public function syncElectorateFromGovnexApi(
        SyncElectorateFromGovnexApiRequest $request,
    ): RedirectResponse {
        $dataset = 'electorate';
        $year = (int) $request->validated('ano');

        try {
            $queued = Cache::lock($this->enqueueLockKey($dataset, $year, null), 15)
                ->block(5, function () use ($dataset, $year, $request): bool {
                    if ($this->hasActiveRun($dataset, $year, null)) {
                        return false;
                    }

                    $run = SincronizacaoTse::query()->create([
                        'gabinete_id' => null,
                        'solicitado_por_id' => $request->user()->id,
                        'dataset' => $dataset,
                        'ano' => $year,
                        'fonte_url' => rtrim((string) config('services.govnex_api.url'), '/').'/sources/tse/datasets',
                        'situacao' => 'pendente',
                        'iniciada_em' => now(),
                    ]);

                    try {
                        SyncElectorateFromGovnexApi::dispatch($run->id);
                    } catch (Throwable $exception) {
                        $run->update([
                            'situacao' => 'falhou',
                            'erro' => 'Não foi possível adicionar a sincronização à fila: '.$exception->getMessage(),
                            'concluida_em' => now(),
                        ]);

                        throw $exception;
                    }

                    return true;
                });
        } catch (LockTimeoutException) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Outra solicitação deste dataset está sendo registrada. Tente novamente em alguns segundos.',
            ]);

            return back();
        }

        if (! $queued) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'O eleitorado já possui uma importação em andamento ou travada. Cancele-a antes de tentar de novo.',
            ]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Sincronização do eleitorado via GOVNEX API adicionada à fila.',
        ]);

        return back();
    }

    public function upload(
        UploadTseManualDatasetRequest $request,
        TsePoliticalDataSyncService $service,
    ): RedirectResponse {
        $dataset = $request->validated('dataset');
        $year = TsePoliticalDataSyncService::datasetRequiresYear($dataset)
            ? (int) $request->validated('ano')
            : now()->year;
        $uf = $request->validated('uf') !== null ? mb_strtoupper($request->validated('uf')) : null;
        $file = $request->file('arquivo');

        try {
            $service->assertDatasetPrerequisites($dataset, $year, $uf);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['dataset' => $exception->getMessage()]);
        }

        // Valida em cima do arquivo temporário original, antes de mover.
        // Se a validação falhar depois de mover, o objeto UploadedFile da
        // requisição continua apontando para o caminho antigo (já removido
        // pelo move); se algo tentar inspecioná-lo depois disso — inclusive
        // a própria página de erro do Laravel em modo debug — quebra com
        // "The file ... does not exist" em vez de mostrar o erro real.
        try {
            $service->assertUploadedArchiveIsValid($file->getRealPath(), $dataset, $year, $uf);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['arquivo' => $exception->getMessage()]);
        }

        $storedPath = null;
        $run = null;

        try {
            Cache::lock($this->enqueueLockKey($dataset, $year, $uf), 15)
                ->block(5, function () use (
                    $dataset,
                    $year,
                    $uf,
                    $request,
                    $service,
                    $file,
                    &$storedPath,
                    &$run,
                ): void {
                    if ($this->hasActiveRun($dataset, $year, $uf)) {
                        throw ValidationException::withMessages([
                            'arquivo' => 'Este dataset já possui uma importação em andamento ou travada. Cancele-a (veja o status abaixo) antes de enviar um novo arquivo.',
                        ]);
                    }

                    $directory = storage_path('app/private/tse');
                    File::ensureDirectoryExists($directory);
                    $storedPath = $directory.DIRECTORY_SEPARATOR."{$dataset}-{$year}-".Str::uuid().'.zip';
                    $file->move($directory, basename($storedPath));

                    $run = SincronizacaoTse::query()->create([
                        'gabinete_id' => null,
                        'solicitado_por_id' => $request->user()->id,
                        'dataset' => $dataset,
                        'ano' => $year,
                        'uf' => $uf,
                        'fonte_url' => $service->sourceUrl($dataset, $year, $uf),
                        'situacao' => 'pendente',
                        'iniciada_em' => now(),
                    ]);

                    ProcessUploadedTseDataset::dispatch($run->id, $storedPath, $uf);
                });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'arquivo' => 'Outra solicitação deste dataset está sendo registrada. Tente novamente em alguns segundos.',
            ]);
        } catch (Throwable $exception) {
            if (is_string($storedPath)) {
                File::delete($storedPath);
            }

            if ($run instanceof SincronizacaoTse && in_array($run->situacao, ['pendente', 'processando'], true)) {
                $run->update([
                    'situacao' => 'falhou',
                    'erro' => 'Não foi possível adicionar o arquivo à fila: '.$exception->getMessage(),
                    'concluida_em' => now(),
                ]);
            }

            throw $exception;
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Arquivo enviado. O processamento roda em segundo plano e pode levar alguns minutos.',
        ]);

        return back();
    }

    /** Solicita também a interrupção cooperativa de um job em execução. */
    public function cancel(Request $request, SincronizacaoTse $sync): RedirectResponse
    {
        abort_if($sync->gabinete_id !== null, 404);

        if (! in_array($sync->situacao, ['pendente', 'processando'], true)) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Só é possível cancelar sincronizações pendentes ou em processamento.',
            ]);

            return back();
        }

        $sync->update([
            'situacao' => 'cancelada',
            'erro' => "Cancelada manualmente por {$request->user()->name}.",
            'progresso_etapa' => null,
            'progresso_percentual' => null,
            'concluida_em' => now(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Sincronização cancelada. Já é possível enviar o arquivo novamente.',
        ]);

        return back();
    }

    private function hasActiveRun(string $dataset, int $year, ?string $uf): bool
    {
        return SincronizacaoTse::query()
            ->whereNull('gabinete_id')
            ->where('dataset', $dataset)
            ->when(
                TsePoliticalDataSyncService::datasetRequiresYear($dataset),
                fn ($query) => $query->where('ano', $year),
            )
            ->where('uf', $uf)
            ->whereIn('situacao', ['pendente', 'processando'])
            ->exists();
    }

    private function enqueueLockKey(string $dataset, int $year, ?string $uf): string
    {
        return 'tse:enqueue:'.TsePoliticalDataSyncService::datasetHistoryKey($dataset, $year, $uf);
    }
}
