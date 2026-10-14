<?php

namespace App\Http\Requests\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use App\Models\CompromissoInstitucional;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InstitutionalAppointmentRequest extends FormRequest
{
    /** A autorização de verdade fica na Policy, chamada pelo controller. */
    public function authorize(): bool
    {
        return $this->user()?->gabinete_id !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $entidadeId = $this->user()->gabinete?->entidade_id;

        return [
            'titulo' => ['required', 'string', 'max:180'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'inicio_em' => ['required', 'date'],
            'fim_em' => ['required', 'date', 'after:inicio_em'],
            'dia_inteiro' => ['sometimes', 'boolean'],
            'local' => ['nullable', 'string', 'max:220'],
            'tipo' => ['required', 'string', 'max:80'],
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
            // Reunião de comissão: o público sai dos membros dela e a lista
            // manual é ignorada. Entidade, situação e membros ativos são
            // conferidos por `InstitutionalAudienceService`.
            'comissao_id' => ['nullable', 'integer'],
            // Vazio = todos os gabinetes de vereador da Câmara. Só entram
            // gabinetes de vereador ativos da mesma entidade da Secretaria.
            'gabinetes' => ['array', 'max:200'],
            'gabinetes.*' => [
                'integer',
                'distinct',
                Rule::exists('gabinetes', 'id')->where(fn ($query) => $query
                    ->where('entidade_id', $entidadeId)
                    ->where('tipo_gabinete', GabineteType::CouncilorOffice->value)
                    ->where('status', GabineteStatus::Active->value)
                    ->whereNull('deleted_at')),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'gabinetes.*.exists' => 'Selecione apenas gabinetes de vereador ativos desta Câmara.',
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $timezone = $this->timezone();

                try {
                    $start = CarbonImmutable::parse((string) $this->input('inicio_em'), $timezone);
                } catch (\Throwable) {
                    return; // A regra base de data informa valores inválidos.
                }

                // Na edição, manter o início de um compromisso que já passou é
                // permitido (ex.: só marcar como concluído); mover para o
                // passado, não.
                $current = $this->route('compromisso');
                if ($current instanceof CompromissoInstitucional
                    && $start->utc()->equalTo(CarbonImmutable::instance($current->inicio_em))) {
                    return;
                }

                if ($start->startOfDay()->lt(CarbonImmutable::now($timezone)->startOfDay())) {
                    $validator->errors()->add(
                        'inicio_em',
                        'Não é permitido inserir ou reagendar compromissos em dias que já passaram.',
                    );
                }
            },
        ];
    }

    /** @return array<string, mixed> Campos do compromisso, com as datas em UTC. */
    public function appointmentData(): array
    {
        $data = collect($this->validated())->except(['gabinetes', 'comissao_id'])->all();
        $data['inicio_em'] = CarbonImmutable::parse((string) $data['inicio_em'], $this->timezone())->utc();
        $data['fim_em'] = CarbonImmutable::parse((string) $data['fim_em'], $this->timezone())->utc();
        $data['dia_inteiro'] = (bool) ($data['dia_inteiro'] ?? false);

        return $data;
    }

    public function committeeId(): ?int
    {
        $id = $this->validated('comissao_id');

        return $id === null ? null : (int) $id;
    }

    /** @return list<int> */
    public function targets(): array
    {
        return array_values(array_map('intval', $this->validated('gabinetes', [])));
    }

    private function timezone(): string
    {
        return $this->user()->gabinete->timezone ?? 'America/Sao_Paulo';
    }
}
