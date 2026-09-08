/**
 * Extensões aceitas para anexos de demanda, espelhando a validação do
 * backend (`StoreDemandAttachmentsRequest` e afins) — mantidas num só lugar
 * para os três pontos de anexo (atualização, encaminhamento, retorno) não
 * divergirem entre si.
 */
export const DEMAND_ATTACHMENT_EXTENSIONS = [
    'pdf',
    'jpg',
    'jpeg',
    'png',
    'webp',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'mp3',
    'm4a',
];

export const DEMAND_ATTACHMENT_ACCEPT = DEMAND_ATTACHMENT_EXTENSIONS.map(
    (extension) => `.${extension}`,
).join(',');
