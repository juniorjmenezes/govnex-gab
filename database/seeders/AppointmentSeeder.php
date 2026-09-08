<?php

namespace Database\Seeders;

use App\Enums\AppointmentRecurrence;
use App\Enums\AppointmentStatus;
use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use App\Models\Appointment;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\Gabinete;
use App\Models\User;
use App\Notifications\DemandActivityNotification;
use Illuminate\Database\Seeder;

class AppointmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->seed();
    }

    public function runForDeployment(): void
    {
        $this->seed();
    }

    private function seed(): void
    {
        Gabinete::withoutGlobalScopes()->where('status', 'ativo')->each(function (Gabinete $office): void {
            $members = User::query()
                ->where('gabinete_id', $office->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->get();
            $creator = $members->first();
            $citizen = Cidadao::withoutGlobalScopes()
                ->where('gabinete_id', $office->id)
                ->where('consentimento_contato', true)
                ->whereNotNull('whatsapp')
                ->first();
            $demand = Demanda::withoutGlobalScopes()
                ->where('gabinete_id', $office->id)
                ->first();

            if (! $creator) {
                return;
            }

            $items = [
                [
                    'titulo' => 'Reunião de alinhamento da equipe',
                    'inicio_em' => now($office->timezone)->addDay()->setTime(9, 0)->utc(),
                    'fim_em' => now($office->timezone)->addDay()->setTime(10, 0)->utc(),
                    'tipo' => 'reuniao',
                    'status' => AppointmentStatus::Confirmed,
                    'recorrencia' => AppointmentRecurrence::Weekly,
                    'recorrencia_ate' => now()->addMonths(2)->toDateString(),
                ],
                [
                    'titulo' => 'Atendimento comunitário',
                    'inicio_em' => now($office->timezone)->addDays(3)->setTime(14, 0)->utc(),
                    'fim_em' => now($office->timezone)->addDays(3)->setTime(16, 0)->utc(),
                    'tipo' => 'atendimento',
                    'status' => AppointmentStatus::Scheduled,
                    'recorrencia' => AppointmentRecurrence::None,
                ],
                [
                    'titulo' => 'Visita técnica ao bairro',
                    'inicio_em' => now($office->timezone)->subDays(2)->setTime(10, 0)->utc(),
                    'fim_em' => now($office->timezone)->subDays(2)->setTime(11, 30)->utc(),
                    'tipo' => 'visita',
                    'status' => AppointmentStatus::Completed,
                    'recorrencia' => AppointmentRecurrence::None,
                ],
            ];

            foreach ($items as $index => $data) {
                $appointment = Appointment::withoutGlobalScopes()
                    ->where('gabinete_id', $office->id)
                    ->where('titulo', $data['titulo'])
                    ->first() ?? new Appointment;
                $appointment->forceFill([
                    'gabinete_id' => $office->id,
                    'responsavel_id' => $members[$index % $members->count()]->id,
                    'cidadao_id' => $index === 1 ? $citizen?->id : null,
                    'demanda_id' => $index === 1 ? $demand?->id : null,
                    'criado_por_id' => $creator->id,
                    'descricao' => 'Compromisso fictício para demonstração da agenda do gabinete.',
                    'dia_inteiro' => false,
                    'local' => $index === 2 ? 'Comgabinete local' : 'Gabinete',
                    'observacoes' => 'Dados exclusivamente demonstrativos.',
                    ...$data,
                ])->save();
                $appointment->participantes()->sync(
                    $members->take(2)->pluck('id')->all(),
                );
                $appointment->lembretes()->delete();

                if ($appointment->inicio_em->isFuture()) {
                    $appointment->lembretes()->forceCreate([
                        'gabinete_id' => $office->id,
                        'canal' => ReminderChannel::Internal,
                        'antecedencia_minutos' => 30,
                        'destinatarios' => $members->take(2)->pluck('id')->all(),
                        'ativo' => true,
                        'agendado_para' => $appointment->inicio_em->copy()->subMinutes(30),
                        'status' => ReminderStatus::Pending,
                    ]);

                    if ($citizen && $index === 1) {
                        $appointment->lembretes()->forceCreate([
                            'gabinete_id' => $office->id,
                            'canal' => ReminderChannel::FakeWhatsApp,
                            'antecedencia_minutos' => 60,
                            'destinatarios' => ['cidadao'],
                            'ativo' => true,
                            'agendado_para' => $appointment->inicio_em->copy()->subHour(),
                            'status' => ReminderStatus::Pending,
                        ]);
                    }
                }
            }

            if ($demand && ! $creator->notifications()->where('data', 'like', '%"key":"seed:attention"%')->exists()) {
                $creator->notify(new DemandActivityNotification(
                    'seed:attention',
                    'Demanda precisa de atenção',
                    "{$demand->protocolo} possui uma atualização pendente.",
                    $demand->id,
                ));
            }
        });
    }
}
