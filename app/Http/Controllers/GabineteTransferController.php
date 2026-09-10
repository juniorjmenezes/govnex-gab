<?php

namespace App\Http\Controllers;

use App\Enums\EntidadeStatus;
use App\Enums\GabineteTransferEvent;
use App\Enums\GabineteTransferStatus;
use App\Models\Entidade;
use App\Models\Gabinete;
use App\Models\GabineteTransferencia;
use App\Models\GabineteTransferenciaEvento;
use App\Models\User;
use App\Services\Entidades\GabineteTransferService;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GabineteTransferController extends Controller
{
    public function index(
        Request $request,
        Entidade $entidade,
        GabineteTransferService $service,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isRoot() || $user->canManageEntidade($entidade->id), 403);

        $transfers = GabineteTransferencia::query()
            ->where(function ($query) use ($entidade): void {
                $query->where('entidade_origem_id', $entidade->id)
                    ->orWhere('entidade_destino_id', $entidade->id);
            })
            ->with(['gabinete', 'entidadeOrigem', 'entidadeDestino'])
            ->latest()
            ->paginate(PerPage::resolve($request, 30))
            ->withQueryString()
            ->through(fn (GabineteTransferencia $transfer): array => $this->serializeTransfer($transfer));

        $canRequest = $user->canManageEntidade($entidade->id);

        return Inertia::render('entities/transfers/index', [
            'entidade' => $this->serializeEntidade($entidade),
            'gabinetes' => $canRequest
                ? Gabinete::withoutGlobalScopes()
                    ->where('entidade_id', $entidade->id)
                    ->orderBy('nome')
                    ->get(['id', 'nome', 'slug', 'tipo_gabinete'])
                    ->map(fn (Gabinete $gabinete): array => [
                        'id' => $gabinete->id,
                        'name' => $gabinete->nome,
                        'type_label' => $gabinete->tipo_gabinete->label(),
                    ])->values()->all()
                : [],
            'destinations' => $canRequest
                ? $service->eligibleDestinations($entidade)
                    ->map(fn (Entidade $destination): array => $this->serializeEntidade($destination))
                    ->all()
                : [],
            'transfers' => $transfers,
            'canRequest' => $canRequest,
            'isRoot' => $user->isRoot(),
        ]);
    }

    public function store(
        Request $request,
        Entidade $entidade,
        GabineteTransferService $service,
    ): RedirectResponse {
        $validated = $request->validate([
            'gabinete_id' => [
                'required',
                'integer',
                Rule::exists('gabinetes', 'id')->where('entidade_id', $entidade->id),
            ],
            'destination_id' => [
                'required',
                'integer',
                Rule::notIn([$entidade->id]),
                Rule::exists('entidades', 'id')
                    ->whereNull('deleted_at')
                    ->where('status', EntidadeStatus::Active->value),
            ],
        ]);
        /** @var Gabinete $gabinete */
        $gabinete = Gabinete::withoutGlobalScopes()->findOrFail($validated['gabinete_id']);
        /** @var Entidade $destination */
        $destination = Entidade::query()->findOrFail($validated['destination_id']);
        $transfer = $service->request($entidade, $gabinete, $destination, $request->user());

        return redirect()->route('entidades.transfers.show', [$entidade, $transfer])
            ->with('success', 'Transferência encaminhada para aceite da organização de destino.');
    }

    public function show(
        Request $request,
        Entidade $entidade,
        GabineteTransferencia $transfer,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $this->assertVisibleInContext($entidade, $transfer, $user);
        $transfer->load([
            'gabinete',
            'entidadeOrigem',
            'entidadeDestino',
            'eventos' => fn ($query) => $query->orderBy('ocorrido_em'),
            'eventos.usuario',
        ]);

        return Inertia::render('entities/transfers/show', [
            'entidade' => $this->serializeEntidade($entidade),
            'transfer' => [
                ...$this->serializeTransfer($transfer),
                'events' => $transfer->eventos->map(fn (GabineteTransferenciaEvento $event): array => [
                    'id' => $event->id,
                    'event_label' => GabineteTransferEvent::from($event->evento)->label(),
                    'tone' => GabineteTransferEvent::from($event->evento)->tone(),
                    'previous_status_label' => $event->estado_anterior
                        ? GabineteTransferStatus::from($event->estado_anterior)->label()
                        : null,
                    'next_status_label' => $event->estado_novo
                        ? GabineteTransferStatus::from($event->estado_novo)->label()
                        : null,
                    'actor_name' => $event->usuario?->name,
                    'occurred_at' => $event->ocorrido_em->toIso8601String(),
                ])->values()->all(),
            ],
            'canAccept' => $transfer->status === GabineteTransferStatus::PendingDestination
                && $user->canManageEntidade($transfer->entidade_destino_id),
            'canCancel' => $transfer->status->isOpen()
                && ! $user->isRoot()
                && $user->canManageEntidade($transfer->entidade_origem_id),
            'canReject' => $transfer->status->isOpen()
                && ($user->isRoot() || $user->canManageEntidade($transfer->entidade_destino_id)),
            'canApprove' => $transfer->status === GabineteTransferStatus::PendingPlatform && $user->isRoot(),
        ]);
    }

    public function accept(
        Request $request,
        Entidade $entidade,
        GabineteTransferencia $transfer,
        GabineteTransferService $service,
    ): RedirectResponse {
        abort_unless((int) $entidade->id === (int) $transfer->entidade_destino_id, 404);
        $service->acceptDestination($transfer, $request->user());

        return back()->with('success', 'Destino aceitou a transferência. A aprovação da plataforma está pendente.');
    }

    public function reject(
        Request $request,
        Entidade $entidade,
        GabineteTransferencia $transfer,
        GabineteTransferService $service,
    ): RedirectResponse {
        $this->assertRelatedEntidade($entidade, $transfer);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $service->reject($transfer, $request->user(), $validated['reason']);

        return back()->with('success', 'Transferência encerrada.');
    }

    public function approve(
        Request $request,
        Entidade $entidade,
        GabineteTransferencia $transfer,
        GabineteTransferService $service,
    ): RedirectResponse {
        $this->assertRelatedEntidade($entidade, $transfer);
        $completed = $service->approve($transfer, $request->user());

        return redirect()->route('entidades.transfers.show', [$completed->entidadeDestino, $completed])
            ->with('success', 'Transferência concluída. Módulos e WhatsApp foram recalculados para o destino.');
    }

    private function assertVisibleInContext(
        Entidade $entidade,
        GabineteTransferencia $transfer,
        User $user,
    ): void {
        $this->assertRelatedEntidade($entidade, $transfer);
        abort_unless(
            $user->isRoot()
            || $user->canManageEntidade($transfer->entidade_origem_id)
            || $user->canManageEntidade($transfer->entidade_destino_id),
            403,
        );
    }

    private function assertRelatedEntidade(Entidade $entidade, GabineteTransferencia $transfer): void
    {
        if ($entidade->id !== $transfer->entidade_origem_id
            && $entidade->id !== $transfer->entidade_destino_id) {
            abort(404);
        }
    }

    /** @return array<string,mixed> */
    private function serializeTransfer(GabineteTransferencia $transfer): array
    {
        return [
            'id' => $transfer->id,
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
            'gabinete' => [
                'id' => $transfer->gabinete?->id,
                'name' => $transfer->gabinete?->nome,
                'slug' => $transfer->gabinete?->slug,
            ],
            'source' => $transfer->entidadeOrigem ? $this->serializeEntidade($transfer->entidadeOrigem) : null,
            'destination' => $transfer->entidadeDestino ? $this->serializeEntidade($transfer->entidadeDestino) : null,
            'created_at' => $transfer->created_at?->toIso8601String(),
            'accepted_destination_at' => $transfer->aceita_destino_em?->toIso8601String(),
            'completed_at' => $transfer->concluida_em?->toIso8601String(),
            'closed_at' => $transfer->encerrada_em?->toIso8601String(),
            'closing_reason' => $transfer->motivo_encerramento,
            'manifest_hash' => $transfer->manifesto_hash,
        ];
    }

    /** @return array{id:int,name:string,slug:string,type_label:string,city:string,state:string} */
    private function serializeEntidade(Entidade $entidade): array
    {
        return [
            'id' => $entidade->id,
            'name' => $entidade->nome,
            'slug' => $entidade->slug,
            'type_label' => $entidade->tipo->label(),
            'city' => $entidade->municipio,
            'state' => $entidade->estado,
        ];
    }
}
