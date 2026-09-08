<?php

namespace App\Notifications;

use App\Models\EntidadeConvite;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EntidadeInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly EntidadeConvite $invitation,
        private readonly string $credential,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $entidadeName = (string) $this->invitation->entidade()->value('nome');

        return (new MailMessage)
            ->subject('Convite para acessar o GOVNEX GAB')
            ->greeting('Olá!')
            ->line("Você recebeu um convite para participar de {$entidadeName} no GOVNEX GAB.")
            ->action('Revisar convite', route('entidade-invitations.show', $this->credential))
            ->line('O convite expira em 48 horas e pode ser utilizado uma única vez.');
    }
}
