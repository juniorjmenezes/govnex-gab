<?php

namespace App\Http\Requests\Committees;

use App\Enums\CommitteeRole;
use App\Models\ComissaoLegislativa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Formato do pedido. As regras de domínio (gabinete de vereador da mesma
 * entidade, pessoa do gabinete, um presidente) ficam em
 * `CommitteeMembershipService`, para valerem fora da camada HTTP.
 */
class CommitteeMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $comissao = $this->route('comissao');

        return $comissao instanceof ComissaoLegislativa
            && $this->user()->can('update', $comissao);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $creating = $this->route('membro') === null;

        return [
            'gabinete_id' => $creating ? ['required', 'integer'] : ['prohibited'],
            'usuario_id' => ['nullable', 'integer'],
            'cargo' => ['required', Rule::enum(CommitteeRole::class)],
            'ingressou_em' => $creating ? ['nullable', 'date'] : ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'gabinete_id.required' => 'Selecione o gabinete de vereador.',
            'gabinete_id.prohibited' => 'O gabinete do membro não pode ser trocado; remova e inclua outro.',
        ];
    }

    public function userId(): ?int
    {
        $value = $this->validated('usuario_id');

        return $value === null ? null : (int) $value;
    }

    public function role(): CommitteeRole
    {
        return CommitteeRole::from((string) $this->validated('cargo'));
    }
}
