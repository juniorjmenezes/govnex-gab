<?php

namespace App\Http\Requests\Appointments;

use App\Enums\AppointmentRecurrence;
use App\Enums\AppointmentStatus;
use App\Enums\GabineteModule;
use App\Enums\ReminderChannel;
use App\Enums\UserRole;
use App\Enums\WhatsAppPurpose;
use App\Models\Cidadao;
use App\Models\Demanda;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Modules\GabineteModuleManager;
use App\Services\WhatsApp\WhatsAppEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->gabinete_id !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $gabineteId = $this->user()->gabinete_id;
        $demandsEnabled = app(GabineteModuleManager::class)
            ->isActive($gabineteId, GabineteModule::Demands);

        return [
            'titulo' => ['required', 'string', 'max:180'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'inicio_em' => ['required', 'date'],
            'fim_em' => ['required', 'date', 'after:inicio_em'],
            'dia_inteiro' => ['sometimes', 'boolean'],
            'local' => ['nullable', 'string', 'max:220'],
            'responsavel_id' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $gabineteId)
                    ->where('role', '!=', UserRole::Root->value)
                    ->where('is_active', true)),
            ],
            'participantes' => ['array', 'max:30'],
            'participantes.*' => [
                'integer',
                'distinct',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $gabineteId)
                    ->where('role', '!=', UserRole::Root->value)
                    ->where('is_active', true)),
            ],
            'cidadao_id' => [
                'nullable',
                'integer',
                Rule::exists(Cidadao::class, 'id')->where('gabinete_id', $gabineteId),
            ],
            'demanda_id' => [
                $demandsEnabled ? 'nullable' : 'prohibited',
                'integer',
                Rule::exists(Demanda::class, 'id')->where('gabinete_id', $gabineteId),
            ],
            'tipo' => ['required', 'string', 'max:80'],
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
            'observacoes' => ['nullable', 'string', 'max:5000'],
            'recorrencia' => ['required', Rule::enum(AppointmentRecurrence::class)],
            'recorrencia_ate' => ['nullable', 'date', 'after_or_equal:inicio_em'],
            'lembretes' => ['array', 'max:8'],
            'lembretes.*.canal' => ['required', Rule::enum(ReminderChannel::class)],
            'lembretes.*.antecedencia_minutos' => ['required', 'integer', 'min:0', 'max:525600'],
            'lembretes.*.destinatarios' => ['required', 'array', 'min:1', 'max:30'],
            'lembretes.*.destinatarios.*' => ['required'],
            'lembretes.*.ativo' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $timezone = $this->user()->gabinete->timezone ?? 'America/Sao_Paulo';

                try {
                    $start = CarbonImmutable::parse((string) $this->input('inicio_em'), $timezone);

                    if ($start->startOfDay()->lt(CarbonImmutable::now($timezone)->startOfDay())) {
                        $validator->errors()->add(
                            'inicio_em',
                            'Não é permitido inserir ou reagendar compromissos em dias que já passaram.',
                        );
                    }
                } catch (\Throwable) {
                    // A regra base de data informa valores inválidos.
                }

                $reminders = $this->input('lembretes', []);
                $channels = is_array($reminders) ? array_column($reminders, 'canal') : [];

                if (array_intersect([
                    ReminderChannel::WhatsApp->value,
                    ReminderChannel::FakeWhatsApp->value,
                ], $channels) !== []
                    && ! app(GabineteModuleManager::class)->isActive(
                        $this->user()->gabinete_id,
                        GabineteModule::WhatsApp,
                    )) {
                    $validator->errors()->add(
                        'lembretes',
                        'O módulo WhatsApp não está habilitado para este gabinete.',
                    );
                }

                if (in_array(ReminderChannel::FakeWhatsApp->value, $channels, true)) {
                    $citizenId = $this->integer('cidadao_id');
                    $citizen = $citizenId ? Cidadao::query()->find($citizenId) : null;

                    if (! $citizen?->consentimento_contato || ! $citizen->whatsapp) {
                        $validator->errors()->add(
                            'lembretes',
                            'O WhatsApp simulado exige cidadão com número e consentimento para contato.',
                        );
                    }
                }

                foreach ((array) $reminders as $index => $reminder) {
                    if (($reminder['canal'] ?? null) !== ReminderChannel::WhatsApp->value) {
                        continue;
                    }
                    foreach ((array) ($reminder['destinatarios'] ?? []) as $recipient) {
                        $isCitizen = $recipient === 'cidadao';
                        $contact = $isCitizen
                            ? WhatsAppContact::withoutGlobalScopes()
                                ->where('gabinete_id', $this->user()->gabinete_id)
                                ->where('cidadao_id', $this->integer('cidadao_id'))
                                ->first()
                            : WhatsAppContact::withoutGlobalScopes()
                                ->where('gabinete_id', $this->user()->gabinete_id)
                                ->where('usuario_id', filter_var($recipient, FILTER_VALIDATE_INT))
                                ->first();
                        $purpose = $isCitizen
                            ? WhatsAppPurpose::AppointmentCitizenReminder
                            : WhatsAppPurpose::AppointmentStaffReminder;
                        $evaluation = $contact
                            ? app(WhatsAppEligibilityService::class)->evaluate($contact, $purpose)
                            : ['eligible' => false, 'reason' => 'O destinatário não possui contato WhatsApp autorizado.'];
                        if (! $evaluation['eligible']) {
                            $validator->errors()->add(
                                "lembretes.{$index}.destinatarios",
                                (string) $evaluation['reason'],
                            );
                        }
                    }
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function appointmentData(): array
    {
        return collect($this->validated())
            ->except(['participantes', 'lembretes'])
            ->all();
    }
}
