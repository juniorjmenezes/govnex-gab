<?php

namespace App\Services\Citizens;

use App\Models\Cidadao;
use Illuminate\Support\Collection;

class CitizenDuplicateFinder
{
    /**
     * @param  array{cpf?: ?string, telefone?: ?string, whatsapp?: ?string, email?: ?string}  $data
     * @return Collection<int, array{id: int, nome: string, matches: list<string>}>
     */
    public function find(array $data, ?Cidadao $ignore = null): Collection
    {
        $fields = collect([
            'cpf' => ['value' => $data['cpf'] ?? null, 'label' => 'CPF'],
            'telefone' => ['value' => $data['telefone'] ?? null, 'label' => 'telefone'],
            'whatsapp' => ['value' => $data['whatsapp'] ?? null, 'label' => 'WhatsApp'],
            'email' => ['value' => $data['email'] ?? null, 'label' => 'e-mail'],
        ])->filter(fn (array $field): bool => filled($field['value']));

        if ($fields->isEmpty()) {
            return collect();
        }

        return Cidadao::query()
            ->select(['id', 'nome', ...$fields->keys()->all()])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->where(function ($query) use ($fields): void {
                foreach ($fields as $column => $field) {
                    $query->orWhere($column, $field['value']);
                }
            })
            ->limit(5)
            ->get()
            ->map(function (Cidadao $cidadao) use ($fields): array {
                $matches = array_values($fields
                    ->filter(fn (array $field, string $column): bool => $cidadao->getAttribute($column) === $field['value'])
                    ->pluck('label')
                    ->map(fn (mixed $label): string => (string) $label)
                    ->all());

                return [
                    'id' => $cidadao->id,
                    'nome' => $cidadao->nome,
                    'matches' => $matches,
                ];
            });
    }
}
