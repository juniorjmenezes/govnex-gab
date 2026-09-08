<?php

namespace App\Http\Requests\Demands;

use App\Enums\DemandStatus;
use App\Models\Demanda;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionDemandStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $demand = $this->route('demanda');

        return $demand instanceof Demanda && $this->user()->can('update', $demand);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $demand = $this->route('demanda');

        return [
            'status' => [
                'required',
                Rule::enum(DemandStatus::class),
                function (string $attribute, mixed $value, Closure $fail) use ($demand): void {
                    $status = is_string($value) ? DemandStatus::tryFrom($value) : null;

                    if ($demand instanceof Demanda && $status && ! $demand->status->canTransitionTo($status)) {
                        $fail("A transição de {$demand->status->label()} para {$status->label()} não é permitida. Use reabrir para sair de Resolvida ou Encerrada.");
                    }
                },
            ],
        ];
    }
}
