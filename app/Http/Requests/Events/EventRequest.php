<?php

namespace App\Http\Requests\Events;

use App\Enums\EventDuration;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\UserRole;
use App\Models\Cidadao;
use App\Models\Evento;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('evento');

        return $event instanceof Evento
            ? $this->user()->can('update', $event)
            : $this->user()->can('create', Evento::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $officeId = $this->user()->gabinete_id;

        return [
            'titulo' => ['required', 'string', 'min:3', 'max:255'],
            'tipo' => ['required', Rule::enum(EventType::class)],
            'status' => ['required', Rule::enum(EventStatus::class)],
            'duracao' => ['required', Rule::enum(EventDuration::class)],
            'inicio_em' => ['required', 'date'],
            'fim_em' => ['required', 'date', 'after:inicio_em'],
            'local' => ['nullable', 'string', 'max:255'],
            'responsavel_id' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $officeId)
                    ->where('role', '!=', UserRole::Root->value)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'participantes_usuarios' => ['array', 'max:100'],
            'participantes_usuarios.*' => [
                'integer',
                'distinct',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $officeId)
                    ->where('role', '!=', UserRole::Root->value)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'participantes_cidadaos' => ['array', 'max:500'],
            'participantes_cidadaos.*' => [
                'integer',
                'distinct',
                Rule::exists(Cidadao::class, 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $officeId)
                    ->whereNull('deleted_at')),
            ],
            'descricao' => ['nullable', 'string', 'max:10000'],
            'observacoes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $start = $this->dateAndTime((string) $this->input('inicio_em'));
                $end = $this->dateAndTime((string) $this->input('fim_em'));
                $duration = $this->string('duracao')->toString();

                if ($start === null || $end === null) {
                    return;
                }

                if ($duration === EventDuration::SingleDay->value && $start['date'] !== $end['date']) {
                    $validator->errors()->add(
                        'fim_em',
                        'Eventos de um dia devem começar e terminar na mesma data.',
                    );
                }

                if ($duration === EventDuration::MultipleDays->value && $end['date'] <= $start['date']) {
                    $validator->errors()->add(
                        'fim_em',
                        'Eventos de vários dias devem terminar em uma data posterior.',
                    );
                }

                if ($end['time'] <= $start['time']) {
                    $validator->errors()->add(
                        'fim_em',
                        'O horário diário de término deve ser posterior ao horário de início.',
                    );
                }
            },
        ];
    }

    /** @return array{date: string, time: string}|null */
    private function dateAndTime(string $value): ?array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $value, $matches) !== 1) {
            return null;
        }

        return [
            'date' => $matches[1],
            'time' => $matches[2],
        ];
    }
}
