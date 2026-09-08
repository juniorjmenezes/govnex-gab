<?php

namespace App\Rules;

use App\Services\IbgeLocationService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MunicipalityInState implements ValidationRule
{
    public function __construct(private readonly string $state) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(IbgeLocationService::class)->municipalityBelongsToState($this->state, (string) $value)) {
            $fail('Selecione um município válido para a UF informada.');
        }
    }
}
