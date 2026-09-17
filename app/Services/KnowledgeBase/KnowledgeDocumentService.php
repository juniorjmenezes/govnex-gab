<?php

namespace App\Services\KnowledgeBase;

use App\Models\ConhecimentoDocumento;
use App\Models\ConhecimentoLeitura;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class KnowledgeDocumentService
{
    public const DISK = 'local';

    /** Guarda o PDF em disco privado; `$gabineteId` nulo = biblioteca da plataforma. */
    public function store(
        UploadedFile $file,
        string $title,
        ?string $description,
        ?int $gabineteId,
        User $user,
    ): ConhecimentoDocumento {
        $this->assertPdfSignature($file);

        $directory = $gabineteId === null
            ? 'conhecimento/plataforma'
            : "gabinetes/{$gabineteId}/conhecimento";
        $path = $file->storeAs($directory, Str::uuid().'.pdf', self::DISK);

        if (! is_string($path)) {
            throw new \RuntimeException('Não foi possível armazenar o arquivo.');
        }

        try {
            $document = new ConhecimentoDocumento;
            $document->forceFill([
                'gabinete_id' => $gabineteId,
                'titulo' => $title,
                'descricao' => $description,
                'disk' => self::DISK,
                'caminho' => $path,
                'nome_original' => Str::limit($file->getClientOriginalName(), 255, ''),
                'tamanho' => (int) $file->getSize(),
                'enviado_por_id' => $user->id,
            ])->save();

            return $document;
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }
    }

    public function stream(ConhecimentoDocumento $document): StreamedResponse
    {
        abort_unless(Storage::disk($document->disk)->exists($document->caminho), 404);

        return Storage::disk($document->disk)->response($document->caminho, $document->nome_original, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Soma uma página vista ao progresso da pessoa. Ao cobrir todas as
     * páginas pela primeira vez, a leitura é concluída e o contador global do
     * documento sobe uma única vez para essa pessoa.
     */
    public function recordPage(
        ConhecimentoDocumento $document,
        User $user,
        int $page,
        int $reportedTotal,
    ): ConhecimentoLeitura {
        return DB::transaction(function () use ($document, $user, $page, $reportedTotal): ConhecimentoLeitura {
            $document = ConhecimentoDocumento::query()->lockForUpdate()->findOrFail($document->id);

            // O primeiro leitor informa o total de páginas; depois ele é fixo.
            if ($document->total_paginas === null) {
                $document->forceFill(['total_paginas' => $reportedTotal])->save();
            }

            $total = (int) $document->total_paginas;

            if ($page > $total) {
                throw ValidationException::withMessages([
                    'pagina' => "O documento tem {$total} páginas.",
                ]);
            }

            $reading = ConhecimentoLeitura::query()
                ->lockForUpdate()
                ->firstOrNew(['documento_id' => $document->id, 'usuario_id' => $user->id]);

            $pages = collect($reading->paginas_lidas ?? [])
                ->push($page)
                ->map(fn ($item): int => (int) $item)
                ->filter(fn (int $item): bool => $item >= 1 && $item <= $total)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $completedNow = $reading->concluida_em === null && count($pages) >= $total;

            $reading->forceFill([
                'paginas_lidas' => $pages,
                'ultima_pagina' => $page,
                'concluida_em' => $completedNow ? now() : $reading->concluida_em,
            ])->save();

            if ($completedNow) {
                $document->increment('leituras_completas');
            }

            return $reading;
        });
    }

    public function delete(ConhecimentoDocumento $document): void
    {
        $path = $document->caminho;
        $disk = $document->disk;
        $document->delete();
        Storage::disk($disk)->delete($path);
    }

    /** @return array<string, mixed> */
    public function summary(ConhecimentoDocumento $document, User $user, ?ConhecimentoLeitura $reading): array
    {
        return [
            'id' => $document->id,
            'title' => $document->titulo,
            'description' => $document->descricao,
            'origin' => $document->isPlatform() ? 'plataforma' : 'gabinete',
            'size' => $document->tamanho,
            'total_pages' => $document->total_paginas,
            'completed_readings' => $document->leituras_completas,
            'uploaded_by' => $document->enviadoPor?->name,
            'created_at' => $document->created_at?->toIso8601String(),
            'can_delete' => $user->can('delete', $document),
            'progress' => $this->progress($document, $reading),
        ];
    }

    /** @return array{pages_read: list<int>, last_page: int, percent: int, completed_at: string|null} */
    public function progress(ConhecimentoDocumento $document, ?ConhecimentoLeitura $reading): array
    {
        $pages = $reading->paginas_lidas ?? [];
        $total = (int) $document->total_paginas;

        return [
            'pages_read' => $pages,
            'last_page' => $reading->ultima_pagina ?? 1,
            'percent' => $total > 0 ? (int) min(100, floor(count($pages) * 100 / $total)) : 0,
            'completed_at' => $reading?->concluida_em?->toIso8601String(),
        ];
    }

    /** Recusa arquivos que não começam com a assinatura de PDF, mesmo com extensão certa. */
    private function assertPdfSignature(UploadedFile $file): void
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $header = $handle === false ? '' : (string) fread($handle, 5);

        if ($handle !== false) {
            fclose($handle);
        }

        if ($header !== '%PDF-') {
            throw ValidationException::withMessages([
                'arquivo' => 'O arquivo enviado não é um PDF válido.',
            ]);
        }
    }
}
