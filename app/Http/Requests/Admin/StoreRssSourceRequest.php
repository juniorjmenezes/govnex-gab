<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRssSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRoot() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:120'],
            'url' => [
                'required',
                'string',
                'max:500',
                'url:http,https',
                Rule::unique('fontes_rss', 'url')->ignore($this->route('fonteRss')),
            ],
            'ativo' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.unique' => 'Esse feed já está cadastrado.',
            'url.url' => 'Informe o endereço completo do feed, começando com http:// ou https://.',
        ];
    }
}
