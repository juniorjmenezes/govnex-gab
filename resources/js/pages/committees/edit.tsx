import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { BackButton } from '@/components/common/back-button';
import { FieldError } from '@/components/forms/field-error';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Surface, SurfaceHeader, SurfaceTitle } from '@/components/ui/surface';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import type {
    CommitteeEditProps,
    CommitteeStatus,
    CommitteeType,
} from '@/types';

/**
 * Dados da comissão. Extinguir encerra a participação dos membros; reativar
 * os devolve. Membros e documentos são geridos na página da comissão.
 */
export default function CommitteeEdit({
    committee,
    options,
}: CommitteeEditProps) {
    const tenantUrl = useTenantUrl();
    const showUrl = tenantUrl(`/comissoes/${committee.id}`);
    const form = useForm<{
        nome: string;
        sigla: string;
        tipo: CommitteeType;
        status: CommitteeStatus;
    }>({
        nome: committee.nome,
        sigla: committee.sigla ?? '',
        tipo: committee.tipo,
        status: committee.status,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(showUrl);
    };

    return (
        <>
            <Head title={`Editar ${committee.nome}`} />
            <PageContainer>
                <PageHeader
                    title="Editar comissão"
                    description={committee.nome}
                    actions={<BackButton />}
                />
                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle>Dados da comissão</SurfaceTitle>
                    </SurfaceHeader>
                    <form
                        noValidate
                        onSubmit={submit}
                        className="grid gap-4 p-4"
                    >
                        <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_10rem]">
                            <Field
                                id="committee-name"
                                label="Nome"
                                error={form.errors.nome}
                            >
                                <Input
                                    id="committee-name"
                                    value={form.data.nome}
                                    onChange={(event) =>
                                        form.setData('nome', event.target.value)
                                    }
                                    maxLength={180}
                                    aria-invalid={Boolean(form.errors.nome)}
                                />
                            </Field>
                            <Field
                                id="committee-acronym"
                                label="Sigla"
                                error={form.errors.sigla}
                            >
                                <Input
                                    id="committee-acronym"
                                    value={form.data.sigla}
                                    onChange={(event) =>
                                        form.setData(
                                            'sigla',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={20}
                                />
                            </Field>
                        </div>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Field
                                id="committee-type"
                                label="Tipo"
                                error={form.errors.tipo}
                            >
                                <AppSelect
                                    id="committee-type"
                                    value={form.data.tipo}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'tipo',
                                            (value ||
                                                committee.tipo) as CommitteeType,
                                        )
                                    }
                                    options={options.types}
                                    clearable={false}
                                />
                            </Field>
                            <Field
                                id="committee-status"
                                label="Situação"
                                error={form.errors.status}
                            >
                                <AppSelect
                                    id="committee-status"
                                    value={form.data.status}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'status',
                                            (value ||
                                                committee.status) as CommitteeStatus,
                                        )
                                    }
                                    options={options.statuses}
                                    clearable={false}
                                />
                            </Field>
                        </div>
                        {committee.status === 'ativa' &&
                            form.data.status === 'extinta' && (
                                <p className="text-sm text-muted-foreground">
                                    Ao extinguir, a participação dos membros é
                                    encerrada na data de hoje e a comissão deixa
                                    de receber novos membros.
                                </p>
                            )}
                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Button asChild variant="ghost">
                                <Link href={showUrl}>Cancelar</Link>
                            </Button>
                            <Button disabled={form.processing}>
                                Salvar alterações
                            </Button>
                        </div>
                    </form>
                </Surface>
            </PageContainer>
        </>
    );
}

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <FieldError message={error} />
        </div>
    );
}

CommitteeEdit.layout = (page: CommitteeEditProps) => ({
    breadcrumbs: [
        { title: 'Comissões', href: '/comissoes' },
        {
            title: page.committee.nome,
            href: `/comissoes/${page.committee.id}`,
        },
        { title: 'Editar', href: '#' },
    ],
});
