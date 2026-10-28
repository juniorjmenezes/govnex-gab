<?php

namespace App\Http\Controllers;

use App\Enums\EntityStatus;
use App\Enums\OfficeTransferEventType;
use App\Enums\OfficeTransferStatus;
use App\Models\Entity;
use App\Models\Office;
use App\Models\OfficeTransfer;
use App\Models\OfficeTransferEvent;
use App\Models\User;
use App\Services\Entidades\OfficeTransferService;
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
        Entity $entidade,
        OfficeTransferService $service,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isRoot() || $user->canManageEntidade($entidade->id), 403);

        $transfers = OfficeTransfer::query()
            ->where(function ($query) use ($entidade): void {
                $query->where('entidade_origem_id', $entidade->id)
                    ->orWhere('entidade_destino_id', $entidade->id);
            })
            ->with(['gabinete', 'entidadeOrigem', 'entidadeDestino'])
            ->latest()
            ->paginate(PerPage::resolve($request, 30))
            ->withQueryString()
            ->through(fn (OfficeTransfer $transfer): array => $this->serializeTransfer($transfer));

        $canRequest = $user->canManageEntidade($entidade->id);

        return Inertia::render('entities/transfers/index', [
            'entidade' => $this->serializeEntidade($entidade),
            'gabinetes' => $canRequest
                ? Office::withoutGlobalScopes()
                    ->where('entidade_id', $entidade->id)
                    ->orderBy('nome')
                    ->get(['id', 'nome', 'slug', 'tipo_gabinete'])
                    ->map(fn (Office $gabinete): array => [
                        'id' => $gabinete->id,
                        'name' => $gabinete->nome,
                        'type_label' => $gabinete->tipo_gabinete->label(),
                    ])->values()->all()
                : [],
            'destinations' => $canRequest
                ? $service->eligibleDestinations($entidade)
                    ->map(fn (Entity $destination): array => $this->serializeEntidade($destination))
                    ->all()
                : [],
            'transfers' => $transfers,
            'canRequest' => $canRequest,
            'isRoot' => $user->isRoot(),
        ]);
    }

    public function store(
        Request $request,
        Entity $entidade,
        OfficeTransferService $service,
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
                    ->where('status', EntityStatus::Active->value),
            ],
        ]);
        /** @var Office $gabinete */
        $gabinete = Office::withoutGlobalScopes()->findOrFail($validated['gabinete_id']);
        /** @var Entity $destination */
        $destination = Entity::query()->findOrFail($validated['destination_id']);
        $transfer = $service->request($entidade, $gabinete, $destination, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transferência encaminhada para aceite da organização de destino.']);

        return redirect()->route('entidades.transfers.show', [$entidade, $transfer]);
    }

    public function show(
        Request $request,
        Entity $entidade,
        OfficeTransfer $transfer,
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
                'events' => $transfer->eventos->map(fn (OfficeTransferEvent $event): array => [
                    'id' => $event->id,
                    'event_label' => OfficeTransferEventType::from($event->evento)->label(),
                    'tone' => OfficeTransferEventType::from($event->evento)->tone(),
                    'previous_status_label' => $event->estado_anterior
                        ? OfficeTransferStatus::from($event->estado_anterior)->label()
                        : null,
                    'next_status_label' => $event->estado_novo
                        ? OfficeTransferStatus::from($event->estado_novo)->label()
                        : null,
                    'actor_name' => $event->usuario?->name,
                    'occurred_at' => $event->ocorrido_em->toIso8601String(),
                ])->values()->all(),
            ],
            'canAccept' => $transfer->status === OfficeTransferStatus::PendingDestination
                && $user->canManageEntidade($transfer->entidade_destino_id),
            'canCancel' => $transfer->status->isOpen()
                && ! $user->isRoot()
                && $user->canManageEntidade($transfer->entidade_origem_id),
            'canReject' => $transfer->status->isOpen()
                && ($user->isRoot() || $user->canManageEntidade($transfer->entidade_destino_id)),
            'canApprove' => $transfer->status === OfficeTransferStatus::PendingPlatform && $user->isRoot(),
        ]);
    }

    public function accept(
        Request $request,
        Entity $entidade,
        OfficeTransfer $transfer,
        OfficeTransferService $service,
    ): RedirectResponse {
        abort_unless((int) $entidade->id === (int) $transfer->entidade_destino_id, 404);
        $service->acceptDestination($transfer, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Destino aceitou a transferência. A aprovação da plataforma está pendente.']);

        return back();
    }

    public function reject(
        Request $request,
        Entity $entidade,
        OfficeTransfer $transfer,
        OfficeTransferService $service,
    ): RedirectResponse {
        $this->assertRelatedEntidade($entidade, $transfer);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $service->reject($transfer, $request->user(), $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transferência encerrada.']);

        return back();
    }

    public function approve(
        Request $request,
        Entity $entidade,
        OfficeTransfer $transfer,
        OfficeTransferService $service,
    ): RedirectResponse {
        $this->assertRelatedEntidade($entidade, $transfer);
        $completed = $service->approve($transfer, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transferência concluída. Módulos e WhatsApp foram recalculados para o destino.']);

        return redirect()->route('entidades.transfers.show', [$completed->entidadeDestino, $completed]);
    }

    private function assertVisibleInContext(
        Entity $entidade,
        OfficeTransfer $transfer,
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

    private function assertRelatedEntidade(Entity $entidade, OfficeTransfer $transfer): void
    {
        if ($entidade->id !== $transfer->entidade_origem_id
            && $entidade->id !== $transfer->entidade_destino_id) {
            abort(404);
        }
    }

    /** @return array<string,mixed> */
    private function serializeTransfer(OfficeTransfer $transfer): array
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
    private function serializeEntidade(Entity $entidade): array
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
