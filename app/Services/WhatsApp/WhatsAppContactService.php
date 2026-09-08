<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppConsentAction;
use App\Enums\WhatsAppContactStatus;
use App\Jobs\SyncWhatsAppSuppression;
use App\Models\Cidadao;
use App\Models\User;
use App\Models\WhatsAppConsent;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class WhatsAppContactService
{
    public function __construct(private readonly WhatsAppPhoneService $phones) {}

    public function declareForUser(User $user, string $phone, User $actor, string $source = 'PROFILE'): WhatsAppContact
    {
        if (! $user->gabinete_id || (! $actor->isRoot() && $actor->id !== $user->id)) {
            throw new InvalidArgumentException('O usuário não pode declarar este contato.');
        }

        return $this->declare((int) $user->gabinete_id, $phone, $actor, $source, $user->id, null);
    }

    public function declareForCitizen(Cidadao $citizen, string $phone, User $actor, string $source = 'CITIZEN_FORM'): WhatsAppContact
    {
        if (! $actor->isRoot() && (int) $actor->gabinete_id !== (int) $citizen->gabinete_id) {
            throw new InvalidArgumentException('O usuário não pode declarar este contato.');
        }

        return $this->declare((int) $citizen->gabinete_id, $phone, $actor, $source, null, $citizen->id);
    }

    public function revoke(WhatsAppContact $contact, User $actor, string $source = 'PROFILE'): WhatsAppContact
    {
        if (! $actor->isRoot() && (int) $actor->gabinete_id !== (int) $contact->gabinete_id) {
            throw new InvalidArgumentException('O usuário não pode revogar este contato.');
        }

        $phoneHash = (string) $contact->telefone_hash;
        DB::transaction(function () use ($contact, $actor, $source, $phoneHash): void {
            $locked = WhatsAppContact::withoutGlobalScopes()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $affected = trim($phoneHash) === ''
                ? collect([$locked])
                : WhatsAppContact::withoutGlobalScopes()
                    ->where('telefone_hash', $phoneHash)
                    ->lockForUpdate()
                    ->get();
            foreach ($affected as $affectedContact) {
                if ($affectedContact->status === WhatsAppContactStatus::Revoked
                    && ! $affectedContact->hasCurrentConsent()) {
                    continue;
                }
                $affectedContact->forceFill([
                    'status' => WhatsAppContactStatus::Revoked,
                    'revogado_em' => now(),
                    'piloto' => false,
                ])->save();
                $this->recordConsent($affectedContact, $actor, WhatsAppConsentAction::Revoked, $source);
            }
        }, 3);

        $contact->refresh();
        if ($phoneHash !== '') {
            SyncWhatsAppSuppression::dispatch($contact->id, $actor->id)->onQueue('whatsapp')->afterCommit();
        }

        return $contact;
    }

    private function declare(
        int $officeId,
        string $phone,
        User $actor,
        string $source,
        ?int $userId,
        ?int $citizenId,
    ): WhatsAppContact {
        $normalized = $this->phones->normalize($phone);
        $hash = $this->phones->hash($normalized);
        $previous = ['hash' => null, 'last_four' => null];
        $contact = DB::transaction(function () use (
            $officeId,
            $normalized,
            $hash,
            $actor,
            $source,
            $userId,
            $citizenId,
            &$previous,
        ): WhatsAppContact {
            $query = WhatsAppContact::withoutGlobalScopes()->where('gabinete_id', $officeId);
            $query->where($userId !== null ? 'usuario_id' : 'cidadao_id', $userId ?? $citizenId);
            $contact = $query->lockForUpdate()->first() ?? new WhatsAppContact;
            $changed = ! $contact->exists
                || ! hash_equals((string) $contact->telefone_hash, $hash)
                || ! $contact->hasCurrentConsent()
                || $contact->status !== WhatsAppContactStatus::Declared;
            if ($contact->exists
                && trim((string) $contact->telefone_hash) !== ''
                && ! hash_equals((string) $contact->telefone_hash, $hash)) {
                $previous = [
                    'hash' => (string) $contact->telefone_hash,
                    'last_four' => (string) $contact->telefone_final,
                ];
            }
            $contact->forceFill([
                'gabinete_id' => $officeId,
                'usuario_id' => $userId,
                'cidadao_id' => $citizenId,
                'telefone_criptografado' => $normalized,
                'telefone_hash' => $hash,
                'telefone_final' => substr($normalized, -4),
                'status' => WhatsAppContactStatus::Declared,
                'origem' => strtoupper($source),
                'declarado_em' => $changed ? now() : $contact->declarado_em,
                'revogado_em' => null,
                'invalido_em' => null,
            ])->save();
            if ($changed) {
                $this->recordConsent($contact, $actor, WhatsAppConsentAction::Accepted, $source);
            }

            return $contact;
        }, 3);

        if ($previous['hash']) {
            SyncWhatsAppSuppression::dispatch(
                $contact->id,
                $actor->id,
                $previous['hash'],
                $previous['last_four'],
            )->onQueue('whatsapp')->afterCommit();
        }
        SyncWhatsAppSuppression::dispatch($contact->id, $actor->id)
            ->onQueue('whatsapp')
            ->afterCommit();

        return $contact;
    }

    private function recordConsent(
        WhatsAppContact $contact,
        User $actor,
        WhatsAppConsentAction $action,
        string $source,
    ): void {
        $text = (string) config('whatsapp.consent.text');
        $consent = new WhatsAppConsent;
        $consent->forceFill([
            'gabinete_id' => $contact->gabinete_id,
            'whatsapp_contato_id' => $contact->id,
            'registrado_por_id' => $actor->id,
            'acao' => $action,
            'versao' => (string) config('whatsapp.consent.version'),
            'texto_hash' => hash('sha256', $text),
            'origem' => strtoupper($source),
            'ocorrido_em' => now(),
        ])->save();
    }
}
