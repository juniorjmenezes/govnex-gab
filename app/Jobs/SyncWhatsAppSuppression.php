<?php

namespace App\Jobs;

use App\Exceptions\WhatsAppGatewayException;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\WhatsAppGatewayClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncWhatsAppSuppression implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 45;

    public function __construct(
        public readonly int $contactId,
        public readonly ?int $adminUserId = null,
        public readonly ?string $phoneHash = null,
        public readonly ?string $phoneLastFour = null,
    ) {}

    public function handle(WhatsAppGatewayClient $gateway): void
    {
        if (config('whatsapp.driver') !== 'gateway') {
            return;
        }
        $contact = WhatsAppContact::withoutGlobalScopes()->find($this->contactId);
        $hash = $this->phoneHash ?? $contact?->telefone_hash;
        $lastFour = $this->phoneLastFour ?? $contact?->telefone_final;
        if (! preg_match('/^[a-f0-9]{64}$/', (string) $hash)) {
            return;
        }

        $matchingContacts = WhatsAppContact::withoutGlobalScopes()
            ->where('telefone_hash', $hash)
            ->get();
        $shouldSuppress = $matchingContacts->isEmpty()
            || $matchingContacts->contains(
                static fn (WhatsAppContact $matchingContact): bool => ! $matchingContact->isEligible(),
            );

        try {
            if ($shouldSuppress) {
                $gateway->suppress((string) $hash, (string) $lastFour, $this->adminUserId);

                return;
            }

            $gateway->releaseSuppression((string) $hash, $this->adminUserId);
        } catch (WhatsAppGatewayException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }
        }
    }
}
