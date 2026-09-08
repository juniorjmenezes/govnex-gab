<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetRootUserPasswordRequest;
use App\Http\Requests\Admin\StoreRootUserRequest;
use App\Http\Requests\Admin\UpdateRootUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RootUserController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isRoot(), 403);

        return Inertia::render('admin/users/index', [
            'users' => User::query()
                ->where('role', UserRole::Root)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'is_active', 'last_login_at', 'created_at'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ])->values()->all(),
        ]);
    }

    public function store(StoreRootUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        (new User)->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'gabinete_id' => null,
            'role' => UserRole::Root,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Usuário root cadastrado.']);

        return to_route('admin.users.index');
    }

    public function update(UpdateRootUserRequest $request, User $rootUsuario): RedirectResponse
    {
        $rootUsuario->forceFill([
            'is_active' => $request->validated('is_active'),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Usuário root atualizado.']);

        return to_route('admin.users.index');
    }

    public function resetPassword(ResetRootUserPasswordRequest $request, User $rootUsuario): RedirectResponse
    {
        $rootUsuario->forceFill(['password' => $request->validated('password')])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Senha redefinida com sucesso.']);

        return to_route('admin.users.index');
    }

    public function destroy(Request $request, User $rootUsuario): RedirectResponse
    {
        abort_unless($request->user()->isRoot(), 403);
        abort_if($request->user()->is($rootUsuario), 403);
        abort_unless($rootUsuario->role === UserRole::Root, 404);

        $rootUsuario->forceFill(['is_active' => false])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Usuário root desativado.']);

        return to_route('admin.users.index');
    }
}
