<?php

namespace App\Actions\Appointments;

use App\Actions\Demands\CompleteNextAction;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Appointments\AppointmentReminderScheduler;
use Illuminate\Support\Facades\DB;

class SaveAppointment
{
    public function __construct(
        private readonly AppointmentReminderScheduler $scheduler,
        private readonly CompleteNextAction $completeNextAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $participants
     * @param  array<int, array<string, mixed>>  $reminders
     */
    public function handle(?Appointment $appointment, array $data, array $participants, array $reminders, User $user): Appointment
    {
        return DB::transaction(function () use ($appointment, $data, $participants, $reminders, $user): Appointment {
            $appointment ??= new Appointment;
            $appointment->forceFill([
                ...$data,
                'gabinete_id' => $user->gabinete_id,
                'criado_por_id' => $appointment->exists ? $appointment->criado_por_id : $user->id,
            ])->save();
            $appointment->participantes()->sync($participants);
            $this->scheduler->replace($appointment, $reminders);

            // Compromisso vinculado a uma demanda, salvo já como "concluído"
            // (ex.: registro retroativo de algo que já aconteceu): conclui a
            // próxima ação pendente correspondente. Agendar o compromisso
            // não é o suficiente — só a realização dele conta (ver também
            // AppointmentController::updateStatus, mesmo gatilho para quando
            // o status muda depois de já criado).
            if ($appointment->status === AppointmentStatus::Completed) {
                $demand = $appointment->demanda;
                if ($demand !== null && $demand->hasNextActionPending()) {
                    $this->completeNextAction->handle($demand, $user);
                }
            }

            return $appointment->refresh();
        });
    }
}
