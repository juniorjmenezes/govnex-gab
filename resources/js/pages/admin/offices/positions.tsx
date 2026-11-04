import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { ActivityMark } from '@/components/common/activity-mark';
import { BackButton } from '@/components/common/back-button';
import { HubManagedHint } from '@/components/common/hub-managed-hint';
import { TableActionButton } from '@/components/common/table-action-button';
import { FieldError } from '@/components/forms/field-error';
import { PenIcon } from '@/components/icons';
import { PageContainer } from '@/components/layout/page-container';
import { PageHeader } from '@/components/layout/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Surface,
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
} from '@/components/ui/surface';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';

/**
 * Equipe e cargos de um gabinete, para o root (Administração → Gabinetes).
 * É a partida da administração de cargos em tela: não depende de já haver
 * vereador ou chefe de gabinete. Pessoas e vínculos vêm do Govnex Hub; aqui
 * só se escolhe o cargo, com motivo registrado na auditoria.
 */
type Member = {
    id: number;
    name: string;
    email: string;
    position: string | null;
    link_active: boolean;
    account_active: boolean;
    last_login_at: string | null;
};
type PositionOption = { value: string; label: string };
type Props = {
    office: {
        id: number;
        name: string;
        type_label: string;
        entidade: string | null;
    };
    positionOptions: PositionOption[];
    members: Member[];
};

export default function OfficePositions({
    office,
    positionOptions,
    members,
}: Props) {
    const [target, setTarget] = useState<Member | null>(null);
    const [position, setPosition] = useState('');
    const [reason, setReason] = useState('');
    const [replace, setReplace] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);
    const positionLabel = (value: string) =>
        positionOptions.find((option) => option.value === value)?.label ??
        value;
    const withoutPosition = members.filter(
        (member) => member.link_active && member.position === null,
    ).length;
    const nobodyHasPosition = !members.some(
        (member) => member.link_active && member.position !== null,
    );

    // Vereador e chefe de gabinete: no máximo um ativo de cada por gabinete.
    const holder =
        position === 'VEREADOR' || position === 'CHEFE_GABINETE'
            ? members.find(
                  (member) =>
                      member.id !== target?.id &&
                      member.link_active &&
                      member.position === position,
              )
            : undefined;

    const openDialog = (member: Member) => {
        setTarget(member);
        setPosition(member.position ?? '');
        setReason('');
        setReplace(false);
        setErrors({});
    };
    const submit = () => {
        if (!target) {
            return;
        }

        router.put(
            `/admin/gabinetes/${office.id}/cargos/${target.id}`,
            {
                cargo: position === '' ? null : position,
                motivo: reason,
                substituir: replace,
            },
            {
                preserveScroll: true,
                onStart: () => setSubmitting(true),
                onFinish: () => setSubmitting(false),
                onSuccess: () => setTarget(null),
                onError: (failure) => setErrors(failure),
            },
        );
    };

    return (
        <>
            <Head title={`Equipe e cargos · ${office.name}`} />
            <PageContainer>
                <PageHeader
                    title="Equipe e cargos"
                    description={`${office.name} · ${office.type_label}${office.entidade ? ` · ${office.entidade}` : ''}`}
                    actions={<BackButton />}
                />

                {nobodyHasPosition && members.length > 0 && (
                    <Alert variant="warning">
                        <AlertTitle>Gabinete sem cargos</AlertTitle>
                        <AlertDescription>
                            Ninguém deste gabinete tem cargo, então ninguém
                            consegue acessá-lo. Conceda o primeiro cargo
                            (normalmente vereador ou chefe de gabinete); quem o
                            receber atribui os demais pela Equipe.
                        </AlertDescription>
                    </Alert>
                )}

                <Surface as="section" className="overflow-hidden">
                    <SurfaceHeader>
                        <SurfaceTitle as="h3">Integrantes</SurfaceTitle>
                        <SurfaceDescription>
                            {withoutPosition === 1
                                ? '1 sem cargo'
                                : `${withoutPosition} sem cargo`}
                        </SurfaceDescription>
                    </SurfaceHeader>
                    <div className="border-b p-4">
                        <HubManagedHint text="Pessoas e vínculos são gerenciados no Govnex Hub. Aqui você só define o cargo de quem já está vinculado ao gabinete." />
                    </div>
                    {members.length === 0 ? (
                        <p className="p-6 text-sm text-muted-foreground">
                            Nenhum integrante vinculado a este gabinete ainda.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">
                                        <span className="sr-only">
                                            Situação do vínculo
                                        </span>
                                    </TableHead>
                                    <TableHead>Nome</TableHead>
                                    <TableHead>Cargo</TableHead>
                                    <TableHead className="text-right">
                                        Ações
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {members.map((member) => (
                                    <TableRow key={member.id}>
                                        <TableCell className="w-10">
                                            <ActivityMark
                                                active={member.link_active}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <span className="block">
                                                {member.name}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {member.email}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {!member.link_active
                                                    ? 'Vínculo encerrado'
                                                    : member.last_login_at
                                                      ? `Último acesso: ${new Date(
                                                            member.last_login_at,
                                                        ).toLocaleString(
                                                            'pt-BR',
                                                        )}`
                                                      : 'Ainda não acessou'}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            {member.position ? (
                                                positionLabel(member.position)
                                            ) : (
                                                <Badge variant="warning">
                                                    Sem cargo
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end">
                                                <TableActionButton
                                                    variant="outline"
                                                    label={`Alterar o cargo de ${member.name}`}
                                                    onClick={() =>
                                                        openDialog(member)
                                                    }
                                                >
                                                    <PenIcon aria-hidden="true" />
                                                </TableActionButton>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Surface>
            </PageContainer>

            <Dialog
                open={target !== null}
                onOpenChange={(open) => !open && setTarget(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cargo de {target?.name}</DialogTitle>
                        <DialogDescription>
                            A mudança fica registrada na auditoria com o seu
                            usuário e o motivo informado.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="position">Cargo</Label>
                            <AppSelect
                                id="position"
                                value={position}
                                onValueChange={setPosition}
                                options={positionOptions}
                                emptyLabel="Sem cargo"
                                placeholder="Sem cargo"
                                clearable={false}
                                aria-invalid={Boolean(errors.cargo)}
                            />
                            <FieldError message={errors.cargo} />
                            {holder && (
                                <label className="flex items-start gap-2 pt-2 text-sm">
                                    <Checkbox
                                        checked={replace}
                                        onCheckedChange={(checked) =>
                                            setReplace(checked === true)
                                        }
                                    />
                                    <span>
                                        Substituir {holder.name}: retira o cargo
                                        dele e atribui a {target?.name}, com o
                                        mesmo motivo.
                                    </span>
                                </label>
                            )}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="reason">
                                Motivo <span aria-hidden="true">*</span>
                            </Label>
                            <Textarea
                                id="reason"
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                maxLength={255}
                                rows={3}
                                placeholder="Ex.: partida do gabinete, chamado #123"
                                aria-invalid={Boolean(errors.motivo)}
                            />
                            <FieldError message={errors.motivo} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setTarget(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={submit} disabled={submitting}>
                            Salvar cargo
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

OfficePositions.layout = {
    breadcrumbs: [
        { title: 'Gabinetes', href: '/admin/gabinetes' },
        { title: 'Equipe e cargos', href: '#' },
    ],
};
