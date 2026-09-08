<?php

namespace App\Exceptions;

use App\Enums\GabineteModule;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class GabineteModuleDisabledException extends RuntimeException
{
    public function __construct(public readonly GabineteModule $module)
    {
        parent::__construct("O módulo {$module->label()} não está habilitado para este gabinete.");
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'module' => $this->module->value,
            ], 403);
        }

        return Inertia::render('errors/module-disabled', [
            'module' => [
                'code' => $this->module->value,
                'name' => $this->module->label(),
                'description' => $this->module->description(),
            ],
        ])->toResponse($request)->setStatusCode(403);
    }
}
