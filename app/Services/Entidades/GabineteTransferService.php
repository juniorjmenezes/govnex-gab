<?php

namespace App\Services\Entidades;

use App\Enums\AccessRole;
use App\Enums\EntidadeStatus;
use App\Enums\GabineteModule;
use App\Enums\GabineteTransferEvent;
use App\Enums\GabineteTransferStatus;
use App\Enums\WhatsAppMode;
use App\Enums\WhatsAppNotificationStatus;
use App\Models\Entidade;
use App\Models\EntidadeMembro;
use App\Models\Gabinete;
use App\Models\GabineteMembro;
use App\Models\GabineteTransferencia;
use App\Models\GabineteTransferenciaEvento;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GabineteTransferService
{
    /** @return Collection<int, Entidade> */
    public function eligibleDestinations(Entidade $source): Collection
    {
        return Entidade::query()
            ->whereKeyNot($source->id)
            ->where('status', EntidadeStatus::Active->value)
            ->whereRaw('UPPER(estado) = ?', [Str::upper($source->estado)])
            ->orderBy('nome')
            ->get()
            ->filter(fn (Entidade $candidate): bool => $this->jurisdictionKey($candidate) === $this->jurisdictionKey($source))
            ->values();
    }

    public function request(
        Entidade $source,
        Gabinete $gabinete,
        Entidade $destination,
        User $actor,
    ): GabineteTransferencia {
        abort_unless($actor->canManageEntidade($source->id), 403);
        abort_unless((int) $gabinete->entidade_id === (int) $source->id, 404);
        $this->assertDestination($source, $destination);

        return DB::transaction(function () use ($source, $gabinete, $destination, $actor): GabineteTransferencia {
            $lockedUnit = Gabinete::withoutGlobalScopes()->lockForUpdate()->findOrFail($gabinete->id);
            abort_unless((int) $lockedUnit->entidade_id === (int) $source->id, 409);

            $openExists = GabineteTransferencia::query()
                ->where('gabinete_id', $lockedUnit->id)
                ->whereIn('status', [
                    GabineteTransferStatus::PendingDestination,
                    GabineteTransferStatus::PendingPlatform,
                ])
                ->lockForUpdate()
                ->exists();
            if ($openExists) {
                throw ValidationException::withMessages([
                    'gabinete_id' => 'O gabinete já possui uma transferência em andamento.',
                ]);
            }

            $transfer = GabineteTransferencia::query()->create([
                'gabinete_id' => $lockedUnit->id,
                'entidade_origem_id' => $source->id,
                'entidade_destino_id' => $destination->id,
                'status' => GabineteTransferStatus::PendingDestination,
                'solicitada_por' => $actor->id,
                'aceita_origem_por' => $actor->id,
                'aceita_origem_em' => now(),
            ]);
            $this->event($transfer, $actor, GabineteTransferEvent::RequestedAndAcceptedByOrigin, null, $transfer->status, [
                'gabinete_id' => $lockedUnit->id,
                'origem_id' => $source->id,
                'destino_id' => $destination->id,
            ]);

            return $transfer;
        });
    }

    public function acceptDestination(GabineteTransferencia $transfer, User $actor): GabineteTransferencia
    {
        abort_unless($actor->canManageEntidade($transfer->entidade_destino_id), 403);

        return DB::transaction(function () use ($transfer, $actor): GabineteTransferencia {
            $locked = GabineteTransferencia::query()->lockForUpdate()->findOrFail($transfer->id);
            abort_unless($locked->status === GabineteTransferStatus::PendingDestination, 409);
            $this->revalidateTransfer($locked);
            $previous = $locked->status;
            $locked->forceFill([
                'status' => GabineteTransferStatus::PendingPlatform,
                'aceita_destino_por' => $actor->id,
                'aceita_destino_em' => now(),
            ])->save();
            $this->event($locked, $actor, GabineteTransferEvent::AcceptedByDestination, $previous, $locked->status);

            return $locked;
        });
    }

    public function reject(GabineteTransferencia $transfer, User $actor, string $reason): GabineteTransferencia
    {
        $allowed = $actor->isRoot()
            || $actor->canManageEntidade($transfer->entidade_origem_id)
            || $actor->canManageEntidade($transfer->entidade_destino_id);
        abort_unless($allowed, 403);

        return DB::transaction(function () use ($transfer, $actor, $reason): GabineteTransferencia {
            $locked = GabineteTransferencia::query()->lockForUpdate()->findOrFail($transfer->id);
            abort_unless($locked->status->isOpen(), 409);
            $previous = $locked->status;
            $status = ! $actor->isRoot()
                && $actor->canManageEntidade($locked->entidade_origem_id)
                ? GabineteTransferStatus::Cancelled
                : GabineteTransferStatus::Rejected;
            $locked->forceFill([
                'status' => $status,
                'encerrada_por' => $actor->id,
                'encerrada_em' => now(),
                'motivo_encerramento' => Str::limit(Str::squish($reason), 1000, ''),
            ])->save();
            $this->event($locked, $actor, $status === GabineteTransferStatus::Cancelled ? GabineteTransferEvent::Cancelled : GabineteTransferEvent::Rejected, $previous, $status);

            return $locked;
        });
    }

    public function approve(GabineteTransferencia $transfer, User $actor): GabineteTransferencia
    {
        abort_unless($actor->isRoot(), 403);

        return DB::transaction(function () use ($transfer, $actor): GabineteTransferencia {
            $locked = GabineteTransferencia::query()->lockForUpdate()->findOrFail($transfer->id);
            abort_unless($locked->status === GabineteTransferStatus::PendingPlatform, 409);
            [$gabinete, $source, $destination] = $this->revalidateTransfer($locked, true);
            $this->assertWhatsAppIdle($gabinete);

            $members = $this->moveMemberships($gabinete, $source, $destination, $actor);

            $gabinete->forceFill(['entidade_id' => $destination->id])->save();
            $disabledModules = $this->recalculateModules($gabinete, $destination, $actor);
            $this->disableWhatsApp($gabinete, $destination);

            $manifest = [
                'versao' => 1,
                'gabinete_id' => $gabinete->id,
                'entidade_origem_id' => $source->id,
                'entidade_destino_id' => $destination->id,
                'membros' => $members,
                'modulos_desativados' => $disabledModules,
                'whatsapp' => 'DESATIVADO_AGUARDANDO_NOVA_VINCULACAO',
                'concluida_em' => now()->toIso8601String(),
            ];
            $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $previous = $locked->status;
            $locked->forceFill([
                'status' => GabineteTransferStatus::Completed,
                'aprovada_por' => $actor->id,
                'aprovada_em' => now(),
                'concluida_em' => now(),
                'manifesto' => $manifest,
                'manifesto_hash' => hash('sha256', $manifestJson),
            ])->save();
            $this->event($locked, $actor, GabineteTransferEvent::ApprovedAndCompleted, $previous, $locked->status, [
                'manifesto_hash' => $locked->manifesto_hash,
            ]);

            return $locked->fresh(['gabinete', 'entidadeOrigem', 'entidadeDestino']);
        }, 3);
    }

    /** @return array{0:Gabinete,1:Entidade,2:Entidade} */
    private function revalidateTransfer(GabineteTransferencia $transfer, bool $lock = false): array
    {
        $gabineteQuery = Gabinete::withoutGlobalScopes();
        $sourceQuery = Entidade::query();
        $destinationQuery = Entidade::query();
        if ($lock) {
            $gabineteQuery->lockForUpdate();
            $sourceQuery->lockForUpdate();
            $destinationQuery->lockForUpdate();
        }
        $gabinete = $gabineteQuery->findOrFail($transfer->gabinete_id);
        $source = $sourceQuery->findOrFail($transfer->entidade_origem_id);
        $destination = $destinationQuery->findOrFail($transfer->entidade_destino_id);
        abort_unless((int) $gabinete->entidade_id === (int) $source->id, 409, 'O gabinete não pertence mais à entidade de origem.');
        $this->assertDestination($source, $destination);

        return [$gabinete, $source, $destination];
    }

    private function assertDestination(Entidade $source, Entidade $destination): void
    {
        if ($source->is($destination)) {
            throw ValidationException::withMessages(['destination_id' => 'Selecione outra organização.']);
        }
        if (! $destination->isActive()) {
            throw ValidationException::withMessages(['destination_id' => 'A organização de destino não está ativa.']);
        }
        if ($this->jurisdictionKey($source) !== $this->jurisdictionKey($destination)) {
            throw ValidationException::withMessages([
                'destination_id' => 'Transferências são permitidas somente entre organizações do mesmo município e estado.',
            ]);
        }
    }

    private function jurisdictionKey(Entidade $entidade): string
    {
        return Str::upper(trim($entidade->estado)).':'.Str::lower(Str::ascii(Str::squish($entidade->municipio)));
    }

    private function assertWhatsAppIdle(Gabinete $gabinete): void
    {
        $busy = DB::table('whatsapp_notificacoes')
            ->where('gabinete_id', $gabinete->id)
            ->whereIn('status', [
                WhatsAppNotificationStatus::Pending->value,
                WhatsAppNotificationStatus::Processing->value,
                WhatsAppNotificationStatus::Reconciling->value,
            ])
            ->exists();
        if ($busy) {
            throw ValidationException::withMessages([
                'whatsapp' => 'O gabinete possui envios WhatsApp em processamento. Aguarde a reconciliação antes da transferência.',
            ]);
        }
    }

    /** @return array{vinculados_destino:int,desativados_origem:int} */
    private function moveMemberships(Gabinete $gabinete, Entidade $source, Entidade $destination, User $actor): array
    {
        $members = GabineteMembro::query()->where('gabinete_id', $gabinete->id)->where('ativo', true)->lockForUpdate()->get();
        $linked = 0;
        $deactivated = 0;

        foreach ($members as $member) {
            $destinationMembership = EntidadeMembro::query()->firstOrNew([
                'entidade_id' => $destination->id,
                'usuario_id' => $member->usuario_id,
            ]);
            if (! $destinationMembership->exists) {
                $destinationMembership->forceFill([
                    'papel' => AccessRole::Operator,
                    'ingressou_em' => now(),
                    'criado_por' => $actor->id,
                ]);
            }
            $destinationMembership->forceFill(['ativo' => true, 'desativado_em' => null])->save();
            $linked++;

            $sourceMembership = EntidadeMembro::query()
                ->where('entidade_id', $source->id)
                ->where('usuario_id', $member->usuario_id)
                ->lockForUpdate()
                ->first();
            if ($sourceMembership?->papel !== AccessRole::Operator) {
                continue;
            }
            $hasAnotherSourceUnit = GabineteMembro::query()
                ->where('usuario_id', $member->usuario_id)
                ->where('ativo', true)
                ->where('gabinete_id', '<>', $gabinete->id)
                ->whereIn('gabinete_id', Gabinete::withoutGlobalScopes()->where('entidade_id', $source->id)->select('id'))
                ->exists();
            if (! $hasAnotherSourceUnit) {
                $sourceMembership->forceFill(['ativo' => false, 'desativado_em' => now()])->save();
                $deactivated++;
            }
        }

        return ['vinculados_destino' => $linked, 'desativados_origem' => $deactivated];
    }

    /** @return list<string> */
    private function recalculateModules(Gabinete $gabinete, Entidade $destination, User $actor): array
    {
        $available = DB::table('entidade_modulos')
            ->where('entidade_id', $destination->id)
            ->where('contratado', true)
            ->where('ativo', true)
            ->pluck('modulo')->all();
        $disabled = [];

        foreach (DB::table('gabinete_modulos')->where('gabinete_id', $gabinete->id)->lockForUpdate()->get() as $setting) {
            if (! $setting->ativo || in_array($setting->modulo, $available, true)) {
                continue;
            }
            DB::table('gabinete_modulos')->where('id', $setting->id)->update([
                'ativo' => false,
                'desativado_em' => now(),
                'administrador_id' => $actor->id,
                'updated_at' => now(),
            ]);
            $disabled[] = (string) $setting->modulo;
        }

        return array_values(array_filter(
            $disabled,
            fn (string $module): bool => GabineteModule::tryFrom($module) !== null,
        ));
    }

    private function disableWhatsApp(Gabinete $gabinete, Entidade $destination): void
    {
        DB::table('whatsapp_configuracoes')->where('gabinete_id', $gabinete->id)->update([
            'entidade_id' => $destination->id,
            'entidade_whatsapp_conexao_id' => null,
            'modo' => WhatsAppMode::Off->value,
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed>|null $context */
    private function event(
        GabineteTransferencia $transfer,
        ?User $actor,
        GabineteTransferEvent $event,
        ?GabineteTransferStatus $previous,
        ?GabineteTransferStatus $next,
        ?array $context = null,
    ): void {
        GabineteTransferenciaEvento::query()->create([
            'transferencia_id' => $transfer->id,
            'usuario_id' => $actor?->id,
            'evento' => $event->value,
            'estado_anterior' => $previous?->value,
            'estado_novo' => $next?->value,
            'contexto' => $context,
            'ocorrido_em' => now(),
        ]);
    }
}
