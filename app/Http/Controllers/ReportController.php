<?php

namespace App\Http\Controllers;

use App\Enums\DemandOrigin;
use App\Enums\DemandPriority;
use App\Enums\DemandStatus;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\UserRole;
use App\Http\Requests\Reports\ReportRequest;
use App\Http\Requests\Reports\StoreReportExportRequest;
use App\Jobs\GenerateReportExport;
use App\Models\Bairro;
use App\Models\Categoria;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\DemandReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(ReportRequest $request, DemandReportService $reports): Response
    {
        $this->authorize('viewAny', ReportExport::class);
        $gabineteId = $request->user()->gabinete_id;
        abort_unless($gabineteId !== null, 403);
        $filters = $request->filters();

        return Inertia::render('reports/index', [
            'filters' => $filters,
            ...$reports->screen($gabineteId, $filters),
            'options' => [
                'statuses' => $this->enumOptions(DemandStatus::cases()),
                'priorities' => $this->enumOptions(DemandPriority::cases()),
                'origins' => $this->enumOptions(DemandOrigin::cases()),
                'categories' => Categoria::query()->select(['id', 'nome'])->where('ativo', true)->orderBy('nome')->get(),
                'neighborhoods' => Bairro::query()->select(['id', 'nome'])->where('ativo', true)->orderBy('nome')->get(),
                'members' => User::query()
                    ->where('gabinete_id', $gabineteId)
                    ->where('role', '!=', UserRole::Root)
                    ->where('is_active', true)
                    ->select(['id', 'name'])
                    ->orderBy('name')
                    ->get(),
            ],
            'exports' => ReportExport::query()
                ->with('solicitadoPor:id,name')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (ReportExport $export): array => [
                    'id' => $export->id,
                    'format' => $export->formato->value,
                    'format_label' => $export->formato->label(),
                    'status' => $export->status->value,
                    'status_label' => $export->status->label(),
                    'file_name' => $export->nome_arquivo,
                    'size' => $export->tamanho,
                    'error' => $export->erro,
                    'created_at' => $export->created_at?->toIso8601String(),
                    'completed_at' => $export->concluido_em?->toIso8601String(),
                    'expires_at' => $export->expira_em?->toIso8601String(),
                    'requested_by' => $export->solicitadoPor?->name,
                    'downloadable' => $export->status === ReportExportStatus::Completed
                        && $export->caminho !== null
                        && ($export->expira_em === null || $export->expira_em->isFuture()),
                ]),
        ]);
    }

    public function store(StoreReportExportRequest $request): RedirectResponse
    {
        $this->authorize('create', ReportExport::class);
        $user = $request->user();
        abort_unless($user->gabinete_id !== null, 403);

        $export = ReportExport::query()->create([
            'solicitado_por_id' => $user->id,
            'formato' => ReportExportFormat::from((string) $request->validated('formato')),
            'filtros' => $request->filters(),
            'status' => ReportExportStatus::Pending,
            'disk' => 'local',
        ]);

        GenerateReportExport::dispatch($export->id);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Exportação adicionada à fila. Acompanhe o processamento nesta página.',
        ]);

        return back();
    }

    public function download(ReportExport $reportExport): StreamedResponse
    {
        $this->authorize('download', $reportExport);
        abort_unless($reportExport->status === ReportExportStatus::Completed, 409, 'A exportação ainda não está disponível.');
        abort_if($reportExport->expira_em?->isPast(), 410, 'Esta exportação expirou.');
        abort_unless(
            $reportExport->caminho !== null
            && $reportExport->nome_arquivo !== null
            && Storage::disk($reportExport->disk)->exists($reportExport->caminho),
            404,
        );

        return Storage::disk($reportExport->disk)->download(
            $reportExport->caminho,
            $reportExport->nome_arquivo,
            ['Content-Type' => $reportExport->mime_type ?? 'application/octet-stream'],
        );
    }

    /**
     * @param  array<int, DemandStatus>|array<int, DemandPriority>|array<int, DemandOrigin>  $cases
     * @return array<int, array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(
            fn (DemandStatus|DemandPriority|DemandOrigin $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }
}
