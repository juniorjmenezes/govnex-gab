import {
    ScrollableDialogContent,
    ScrollableDialogHeader,
} from '@/components/common/scrollable-dialog';
import { Dialog, DialogTitle } from '@/components/ui/dialog';
import type { KnowledgeDocument } from '@/types';
import { KnowledgeReader } from './knowledge-reader';

/**
 * Abre a leitura de um documento da Base de Conhecimento em um modal, sem
 * navegar para outra página. O leitor mantém zoom, navegação e rastreamento
 * de leitura intactos; só o card externo dele é removido para caber aqui.
 *
 * `ScrollableDialogContent` é um grid de 3 linhas (header / body / footer);
 * a linha do meio (`minmax(0,1fr)`) só precisa de `min-h-0 overflow-hidden`
 * para não quebrar o grid — quem rola de fato é o próprio `KnowledgeReader`
 * (`embedded`), pois a rolagem contínua por páginas depende de um container
 * de scroll estável e próprio, igual nos dois modos (modal e página cheia).
 */
export function KnowledgeReaderDialog({
    document: knowledgeDocument,
    fileUrl,
    progressUrl,
    onClose,
}: {
    document: KnowledgeDocument | null;
    fileUrl: string | null;
    progressUrl: string | null;
    onClose: () => void;
}) {
    const open = knowledgeDocument !== null && fileUrl !== null && progressUrl !== null;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <ScrollableDialogContent className="sm:max-w-5xl">
                <ScrollableDialogHeader>
                    <DialogTitle>{knowledgeDocument?.title}</DialogTitle>
                </ScrollableDialogHeader>

                <div className="min-h-0 overflow-hidden">
                    {knowledgeDocument && fileUrl && progressUrl ? (
                        <KnowledgeReader
                            key={knowledgeDocument.id}
                            document={knowledgeDocument}
                            fileUrl={fileUrl}
                            progressUrl={progressUrl}
                            embedded
                        />
                    ) : null}
                </div>
            </ScrollableDialogContent>
        </Dialog>
    );
}
