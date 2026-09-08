<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EntidadeStatus;
use App\Enums\EntidadeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEntidadeRequest;
use App\Models\Entidade;
use App\Services\Entidades\EntidadeEntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EntidadeController extends Controller
{
    public function create(): Response
    {
        abort_unless(request()->user()?->isRoot(), 403);

        return Inertia::render('admin/entities/form', [
            'types' => array_map(fn (EntidadeType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => match ($type) {
                    EntidadeType::IndependentOffice => 'Uma organização simples com apenas um gabinete.',
                    EntidadeType::CityCouncil => 'Pode reunir gabinetes parlamentares e setores administrativos.',
                    EntidadeType::CityHall => 'Pode reunir o gabinete do prefeito, secretarias e setores administrativos.',
                },
            ], EntidadeType::cases()),
        ]);
    }

    public function store(
        StoreEntidadeRequest $request,
        EntidadeEntitlementService $entitlements,
    ): RedirectResponse {
        $validated = $request->validated();
        $type = EntidadeType::from($validated['tipo']);
        $entidade = DB::transaction(function () use ($validated, $type, $entitlements): Entidade {
            $entidade = Entidade::query()->create([
                'tipo' => $type,
                'nome' => $validated['nome'],
                'slug' => $this->uniqueSlug($validated['nome']),
                'status' => EntidadeStatus::Active,
                'municipio' => $validated['municipio'],
                'estado' => $validated['estado'],
                'timezone' => $validated['timezone'],
                'interface_simplificada' => $type === EntidadeType::IndependentOffice,
            ]);
            $entitlements->provisionLegacyCompatible($entidade);

            return $entidade;
        });

        Log::info('Entidade criada pela administração da plataforma.', [
            'entidade_id' => $entidade->id,
            'tipo' => $entidade->tipo->value,
            'administrador_id' => $request->user()->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Entidade criada. Agora cadastre o primeiro gabinete.',
        ]);

        return to_route('admin.offices.create', ['entidade' => $entidade->id]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'entidade';
        $slug = $base;
        $suffix = 2;

        while (Entidade::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
