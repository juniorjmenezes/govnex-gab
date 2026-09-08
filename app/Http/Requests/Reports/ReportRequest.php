<?php

namespace App\Http\Requests\Reports;

use App\Enums\DemandOrigin;
use App\Enums\DemandPriority;
use App\Enums\DemandStatus;
use App\Models\Bairro;
use App\Models\Categoria;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->gabinete_id !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $gabineteId = $this->user()?->gabinete_id;

        return [
            'inicio' => ['required', 'date', 'before_or_equal:fim'],
            'fim' => ['required', 'date', 'after_or_equal:inicio'],
            'status' => ['nullable', Rule::enum(DemandStatus::class)],
            'prioridade' => ['nullable', Rule::enum(DemandPriority::class)],
            'origem' => ['nullable', Rule::enum(DemandOrigin::class)],
            'categoria_id' => [
                'nullable',
                'integer',
                Rule::exists(Categoria::class, 'id')->where('gabinete_id', $gabineteId),
            ],
            'bairro_id' => [
                'nullable',
                'integer',
                Rule::exists(Bairro::class, 'id')->where('gabinete_id', $gabineteId),
            ],
            'responsavel_id' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where('gabinete_id', $gabineteId),
            ],
            'atrasadas' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{inicio: string, fim: string, status: string|null, prioridade: string|null, origem: string|null, categoria_id: int|null, bairro_id: int|null, responsavel_id: int|null, atrasadas: bool} */
    public function filters(): array
    {
        return [
            'inicio' => (string) $this->validated('inicio'),
            'fim' => (string) $this->validated('fim'),
            'status' => $this->string('status')->trim()->toString() ?: null,
            'prioridade' => $this->string('prioridade')->trim()->toString() ?: null,
            'origem' => $this->string('origem')->trim()->toString() ?: null,
            'categoria_id' => $this->integer('categoria_id') ?: null,
            'bairro_id' => $this->integer('bairro_id') ?: null,
            'responsavel_id' => $this->integer('responsavel_id') ?: null,
            'atrasadas' => $this->boolean('atrasadas'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'inicio' => $this->input('inicio') ?: now()->subDays(89)->toDateString(),
            'fim' => $this->input('fim') ?: now()->toDateString(),
        ]);
    }
}
