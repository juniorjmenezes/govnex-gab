<?php

namespace App\Http\Controllers;

use App\Enums\EntidadeType;
use App\Models\Entidade;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EntidadeDirectoryController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $search = Str::squish($request->string('q')->toString());
        $accessibleGabineteIds = $user->isRoot()
            ? null
            : $user->gabinetes()
                ->where('ativo', true)
                ->pluck('gabinete_id');
        $query = Entidade::query()->with([
            'gabinetes' => fn ($query) => $query
                ->withoutGlobalScopes()
                ->when($accessibleGabineteIds !== null, fn (Builder $query) => $query
                    ->whereIn('gabinetes.id', $accessibleGabineteIds))
                ->orderBy('nome'),
        ])
            ->when($search !== '', function (Builder $query) use ($search, $accessibleGabineteIds): void {
                $query->where(function (Builder $query) use ($search, $accessibleGabineteIds): void {
                    $query->where('nome', 'like', "%{$search}%")
                        ->orWhere('municipio', 'like', "%{$search}%")
                        ->orWhere('estado', 'like', "%{$search}%")
                        ->orWhereHas('gabinetes', fn (Builder $gabinetes) => $gabinetes
                            ->withoutGlobalScopes()
                            ->when($accessibleGabineteIds !== null, fn (Builder $query) => $query
                                ->whereIn('gabinetes.id', $accessibleGabineteIds))
                            ->where('nome', 'like', "%{$search}%"));
                });
            })
            ->orderBy('nome');

        if (! $user->isRoot()) {
            $query->whereHas('membros', fn ($query) => $query
                ->where('usuario_id', $user->id)
                ->where('ativo', true));
        }

        $entidades = $query
            ->paginate(8)
            ->withQueryString()
            ->through(fn (Entidade $entidade): array => [
                'id' => $entidade->id,
                'name' => $entidade->nome,
                'slug' => $entidade->slug,
                'type' => $entidade->tipo->value,
                'type_label' => $entidade->tipo->label(),
                'city' => $entidade->municipio,
                'state' => $entidade->estado,
                'can_create_gabinete' => $user->isRoot()
                    && $entidade->isActive()
                    && ($entidade->tipo !== EntidadeType::IndependentOffice
                        || $entidade->gabinetes->isEmpty()),
                'gabinetes' => $entidade->gabinetes
                    ->map(fn ($gabinete): array => [
                        'id' => $gabinete->id,
                        'name' => $gabinete->nome,
                        'slug' => $gabinete->slug,
                        'type' => $gabinete->tipo_gabinete->value,
                        'type_label' => $gabinete->tipo_gabinete->label(),
                    ])->values()->all(),
            ]);

        return Inertia::render('entities/index', [
            'entidades' => $entidades,
            'filters' => ['q' => $search],
            'isRoot' => $user->isRoot(),
        ]);
    }
}
