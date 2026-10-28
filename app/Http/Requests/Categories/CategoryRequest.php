<?php

namespace App\Http\Requests\Categories;

use App\Models\Category;
use App\Tenancy\OfficeContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $categoria = $this->route('categoria');

        return $categoria instanceof Category
            ? $this->user()->can('update', $categoria)
            : $this->user()->can('create', Category::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'nome' => [
                'required',
                'string',
                'max:100',
                Rule::unique('categorias', 'nome')
                    ->where(fn ($query) => $query->where('gabinete_id', app(OfficeContext::class)->officeIdFor($this->user())))
                    ->ignore($this->route('categoria')),
            ],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'icone' => [
                'nullable',
                Rule::in([
                    'tag',
                    'heart-pulse',
                    'graduation-cap',
                    'hard-hat',
                    'lamp-desk',
                    'trash-2',
                    'bus-front',
                    'hand-heart',
                    'shield-check',
                    'leaf',
                    'house',
                    'briefcase-business',
                    'ellipsis',
                ]),
            ],
            'cor_semantica' => ['required', Rule::in(['neutra', 'informativa', 'sucesso', 'atencao', 'critica'])],
            'ativo' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['ativo' => $this->boolean('ativo')]);
    }
}
