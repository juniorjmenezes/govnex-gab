<?php

namespace App\Http\Requests\Attendances;

use App\Enums\GabineteModule;
use App\Models\Atendimento;
use App\Services\Modules\GabineteModuleManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attendance = $this->route('atendimento');

        return $attendance instanceof Atendimento
            ? $this->user()->can('update', $attendance)
            : $this->user()->can('create', Atendimento::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $officeId = $this->user()->gabinete_id;
        $citizenId = $this->integer('cidadao_id');
        $demandsEnabled = app(GabineteModuleManager::class)
            ->isActive($officeId, GabineteModule::Demands);

        return [
            'cidadao_id' => [
                'required',
                'integer',
                Rule::exists('cidadaos', 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $officeId)
                    ->whereNull('deleted_at')),
            ],
            'atendente_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $officeId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'demanda_id' => [
                $demandsEnabled ? 'nullable' : 'prohibited',
                'integer',
                Rule::exists('demandas', 'id')->where(fn ($query) => $query
                    ->where('gabinete_id', $officeId)
                    ->where('cidadao_id', $citizenId)
                    ->whereNull('deleted_at')),
            ],
            'assunto' => ['required', 'string', 'min:3', 'max:255'],
            'relato' => ['required', 'string', 'min:10', 'max:10000'],
            'providencias' => ['nullable', 'string', 'max:10000'],
            'atendido_em' => ['required', 'date', 'before_or_equal:now'],
            'duracao_minutos' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'requer_retorno' => ['required', 'boolean'],
            'retorno_previsto_em' => [
                'nullable',
                'required_if:requer_retorno,true',
                'date',
            ],
        ];
    }
}
