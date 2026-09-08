<?php

namespace App\Services\Demands;

use App\Enums\DemandEventType;
use App\Models\Demanda;
use App\Models\DemandaAnexo;
use App\Models\DemandaEvento;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DemandAttachmentService
{
    public function __construct(private readonly DemandTimelineRecorder $timeline) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return Collection<int, DemandaAnexo>
     */
    public function store(Demanda $demand, array $files, User $user, ?DemandaEvento $event = null): Collection
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($demand, $files, $user, $event, &$storedPaths): Collection {
                $attachments = collect();
                $directory = "gabinetes/{$demand->gabinete_id}/demandas/{$demand->id}";

                foreach ($files as $file) {
                    $extension = Str::lower($file->getClientOriginalExtension());
                    $storedName = Str::uuid().($extension !== '' ? ".{$extension}" : '');
                    $path = $file->storeAs($directory, $storedName, 'local');

                    if (! is_string($path)) {
                        throw new \RuntimeException('Não foi possível armazenar o arquivo.');
                    }

                    $storedPaths[] = $path;
                    $attachment = new DemandaAnexo;
                    $attachment->forceFill([
                        'gabinete_id' => $demand->gabinete_id,
                        'demanda_id' => $demand->id,
                        'demanda_evento_id' => $event?->id,
                        'usuario_id' => $user->id,
                        'disk' => 'local',
                        'caminho' => $path,
                        'nome_original' => Str::limit($file->getClientOriginalName(), 255, ''),
                        'nome_armazenado' => $storedName,
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'extensao' => $extension,
                        'tamanho' => $file->getSize(),
                        'imagem' => str_starts_with((string) $file->getMimeType(), 'image/'),
                    ])->save();
                    $attachments->push($attachment);

                    if ($event === null) {
                        $this->timeline->record(
                            $demand,
                            $user,
                            DemandEventType::AnexoAdicionado,
                            "Arquivo {$attachment->nome_original} anexado.",
                            ['anexo_id' => $attachment->id, 'nome' => $attachment->nome_original],
                        );
                    }
                }

                return $attachments;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    public function delete(DemandaAnexo $attachment, User $user): void
    {
        DB::transaction(function () use ($attachment, $user): void {
            $demand = $attachment->demanda()->firstOrFail();
            $attachment->delete();
            Storage::disk($attachment->disk)->delete($attachment->caminho);

            $this->timeline->record(
                $demand,
                $user,
                DemandEventType::AnexoRemovido,
                "Arquivo {$attachment->nome_original} removido.",
                ['anexo_id' => $attachment->id, 'nome' => $attachment->nome_original],
            );
        });
    }
}
