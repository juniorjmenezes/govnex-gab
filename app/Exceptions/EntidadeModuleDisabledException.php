<?php

namespace App\Exceptions;

use App\Enums\EntidadeModule;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class EntidadeModuleDisabledException extends RuntimeException
{
    public function __construct(public readonly EntidadeModule $module)
    {
        parent::__construct("O módulo {$module->label()} não está disponível para esta organização.");
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
                'description' => 'A licença ou a gestão da entidade não disponibilizou este módulo.',
            ],
        ])->toResponse($request)->setStatusCode(403);
    }
}
