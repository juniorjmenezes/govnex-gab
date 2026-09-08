<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppContact;
use App\Models\WhatsAppNotification;

final class WhatsAppSensitiveDataPurger
{
    public function purge(int $limit = 1000): int
    {
        $notificationIds = WhatsAppNotification::withoutGlobalScopes()
            ->whereNotNull('expurgar_sensiveis_em')
            ->where('expurgar_sensiveis_em', '<=', now())
            ->where(function ($query): void {
                $query->whereNotNull('telefone_criptografado')
                    ->orWhereNotNull('parametros_corpo_criptografados')
                    ->orWhereNotNull('parametros_botoes_criptografados');
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $notifications = $notificationIds->isEmpty()
            ? 0
            : WhatsAppNotification::withoutGlobalScopes()->whereIn('id', $notificationIds->all())->update([
                'telefone_criptografado' => null,
                'parametros_corpo_criptografados' => null,
                'parametros_botoes_criptografados' => null,
                'updated_at' => now(),
            ]);

        $cutoff = now()->subDays((int) config('whatsapp.retention_days', 90));
        $contactIds = WhatsAppContact::withoutGlobalScopes()
            ->whereNotNull('telefone_criptografado')
            ->where(function ($query) use ($cutoff): void {
                $query->where('revogado_em', '<=', $cutoff)
                    ->orWhere('invalido_em', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $contacts = $contactIds->isEmpty()
            ? 0
            : WhatsAppContact::withoutGlobalScopes()->whereIn('id', $contactIds->all())->update([
                'telefone_criptografado' => null,
                'updated_at' => now(),
            ]);

        return $notifications + $contacts;
    }
}
