<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGovnexApiIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isRoot();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'url:http,https', 'max:512'],
            // Opcional: em branco mantém a chave já gravada, já que a tela
            // nunca a exibe de volta para ser reenviada.
            'chave' => ['nullable', 'string', 'min:16', 'max:255'],
            'remover_chave' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.required' => 'Informe a URL base da GOVNEX API.',
            'url.url' => 'A URL precisa começar com http:// ou https://.',
            'chave.min' => 'A chave gerada pela GOVNEX API tem pelo menos 16 caracteres.',
        ];
    }
}
