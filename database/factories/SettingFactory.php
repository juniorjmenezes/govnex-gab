<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Setting> */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return [
            'gabinete_id' => Office::factory(),
            'partido' => fake()->randomElement(['PSD', 'MDB', 'PSB', 'PT', 'UNIÃO']),
            'legislatura' => '2025–2028',
        ];
    }

    public function forGabinete(Office $gabinete): static
    {
        return $this->state(fn (): array => ['gabinete_id' => $gabinete->id]);
    }
}
