import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import { CitizenLocationPicker } from '@/components/citizens/citizen-location-picker';
import { AddressFields } from '@/components/forms/address-fields';
import { FieldError } from '@/components/forms/field-error';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MaskedInput } from '@/components/ui/masked-input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useTenantUrl } from '@/hooks/use-tenant-url';
import { applyMask } from '@/lib/masks';
import type { MaskType } from '@/lib/masks';
import type { Citizen, Neighborhood, OfficeLocation } from '@/types';

const schema = z.object({
    nome: z.string().min(2, 'Informe o nome completo.'),
    cpf: z.string(),
    telefone: z.string(),
    whatsapp: z.string(),
    email: z.string(),
    data_nascimento: z.string(),
    estado: z.string(),
    municipio: z.string(),
    bairro_id: z.string(),
    endereco: z.string(),
    cep: z.string(),
    numero: z.string(),
    complemento: z.string(),
    ponto_referencia: z.string(),
    latitude: z.number().nullable(),
    longitude: z.number().nullable(),
    localizacao_origem: z
        .enum(['endereco', 'logradouro', 'municipio', 'manual'])
        .nullable(),
    observacoes: z.string(),
    consentimento_contato: z.boolean(),
    whatsapp_consentimento_operacional: z.boolean(),
    eleitor: z.boolean(),
});
type Values = z.infer<typeof schema>;
const text = (value: string | null | undefined) => value ?? '';
const coordinate = (value: string | null | undefined) => {
    const parsed = value ? Number(value) : Number.NaN;

    return Number.isFinite(parsed) ? parsed : null;
};

export function CitizenForm({
    citizen,
    neighborhoods,
    officeLocation,
    whatsappConsentText,
}: {
    citizen?: Citizen;
    neighborhoods: Neighborhood[];
    officeLocation?: OfficeLocation;
    whatsappConsentText: string;
}) {
    const tenantUrl = useTenantUrl();
    const {
        control,
        register,
        handleSubmit,
        setError,
        setValue,
        formState: { errors, isSubmitting },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            nome: text(citizen?.nome),
            cpf: applyMask(citizen?.cpf, 'cpf'),
            telefone: applyMask(citizen?.telefone, 'phone'),
            whatsapp: applyMask(citizen?.whatsapp, 'phone'),
            email: text(citizen?.email),
            data_nascimento: text(citizen?.data_nascimento),
            estado: text(citizen?.bairro?.estado ?? officeLocation?.estado),
            municipio: text(
                citizen?.bairro?.municipio ?? officeLocation?.municipio,
            ),
            bairro_id: citizen?.bairro_id?.toString() ?? '',
            endereco: text(citizen?.endereco),
            numero: text(citizen?.numero),
            cep: '',
            complemento: text(citizen?.complemento),
            ponto_referencia: text(citizen?.ponto_referencia),
            latitude: coordinate(citizen?.latitude),
            longitude: coordinate(citizen?.longitude),
            localizacao_origem: citizen?.localizacao_origem ?? null,
            observacoes: text(citizen?.observacoes),
            consentimento_contato: citizen?.consentimento_contato ?? false,
            whatsapp_consentimento_operacional:
                citizen?.whatsapp_consentimento_operacional ?? false,
            eleitor: citizen?.eleitor ?? false,
        },
    });
    const [
        eleitor,
        bairroId,
        endereco,
        numero,
        latitude,
        longitude,
        locationSource,
    ] = useWatch({
        control,
        name: [
            'eleitor',
            'bairro_id',
            'endereco',
            'numero',
            'latitude',
            'longitude',
            'localizacao_origem',
        ],
    });
    const neighborhood = neighborhoods.find(
        (item) => item.id.toString() === bairroId,
    );
    const locationSearchAddress = {
        street: endereco,
        number: numero,
        neighborhood: neighborhood?.nome ?? '',
        city: neighborhood?.municipio ?? '',
        state: neighborhood?.estado ?? '',
    };
    const coordinates =
        latitude !== null && longitude !== null
            ? { latitude, longitude }
            : null;

    const submit = (values: Values) => {
        const payload = values.eleitor
            ? values
            : {
                  ...values,
                  latitude: null,
                  longitude: null,
                  localizacao_origem: null,
              };
        const options = {
            preserveScroll: true,
            onError: (serverErrors: Record<string, string>) =>
                Object.entries(serverErrors).forEach(([key, message]) =>
                    setError(key as keyof Values, { message }),
                ),
        };

        if (citizen) {
            router.put(tenantUrl(`/cidadaos/${citizen.id}`), payload, options);
        } else {
            router.post(tenantUrl('/cidadaos'), payload, options);
        }
    };
    const field = (
        name: keyof Values,
        label: string,
        type = 'text',
        mask?: MaskType,
    ) => (
        <div className="space-y-1">
            <Label htmlFor={name}>{label}</Label>
            {mask ? (
                <MaskedInput
                    id={name}
                    mask={mask}
                    {...register(name)}
                    aria-invalid={Boolean(errors[name])}
                />
            ) : (
                <Input
                    id={name}
                    type={type}
                    {...register(name)}
                    aria-invalid={Boolean(errors[name])}
                />
            )}
            <FieldError message={errors[name]?.message} />
        </div>
    );

    return (
        <form onSubmit={handleSubmit(submit)} className="space-y-6">
            <Card className="gap-0 py-0">
                <div className="border-b p-4">
                    <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                        Dados pessoais
                    </h2>
                </div>
                <div className="grid gap-5 p-5 md:grid-cols-2">
                    {field('nome', 'Nome completo')}{' '}
                    {field('cpf', 'CPF (opcional)', 'text', 'cpf')}{' '}
                    {field('telefone', 'Telefone', 'text', 'phone')}{' '}
                    {field('whatsapp', 'WhatsApp', 'text', 'phone')}{' '}
                    {field('email', 'E-mail', 'email')}{' '}
                    {field('data_nascimento', 'Data de nascimento', 'date')}
                </div>
            </Card>
            <Card className="gap-0 py-0">
                <div className="border-b p-4">
                    <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                        Endereço
                    </h2>
                </div>
                <div className="space-y-5 p-5">
                    <AddressFields
                        control={control}
                        register={register}
                        setValue={setValue}
                        errors={errors}
                        bairroName="bairro_id"
                        renderBairro={() => (
                            <div className="space-y-1">
                                <Label htmlFor="bairro_id">Bairro</Label>
                                <Controller
                                    control={control}
                                    name="bairro_id"
                                    render={({ field }) => (
                                        <AppSelect
                                            id="bairro_id"
                                            value={field.value}
                                            onValueChange={field.onChange}
                                            placeholder="Selecione"
                                            options={neighborhoods.map(
                                                (item) => ({
                                                    value: item.id.toString(),
                                                    label: `${item.nome} — ${item.municipio}/${item.estado}`,
                                                }),
                                            )}
                                            aria-invalid={Boolean(
                                                errors.bairro_id,
                                            )}
                                        />
                                    )}
                                />
                                <FieldError
                                    message={errors.bairro_id?.message}
                                />
                            </div>
                        )}
                    />
                    {field('ponto_referencia', 'Ponto de referência')}
                </div>
            </Card>
            <Card className="gap-0 py-0">
                <div className="border-b p-4">
                    <h2 className="text-xs font-semibold tracking-wide text-foreground uppercase">
                        Observações e consentimentos
                    </h2>
                </div>
                <div className="space-y-5 p-5">
                    <div className="space-y-1">
                        <Label htmlFor="observacoes">Observações</Label>
                        <Textarea
                            id="observacoes"
                            rows={4}
                            {...register('observacoes')}
                        />
                        <FieldError message={errors.observacoes?.message} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="flex min-h-14 items-center justify-between gap-3 rounded-md border p-3">
                            <span className="min-w-0">
                                <span className="block text-sm font-medium">
                                    Eleitor do vereador
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    Marque quando o cidadão informar que é
                                    eleitor do vereador.
                                </span>
                            </span>
                            <Controller
                                control={control}
                                name="eleitor"
                                render={({ field }) => (
                                    <Switch
                                        checked={field.value}
                                        onCheckedChange={(checked) => {
                                            field.onChange(checked);

                                            if (!checked) {
                                                setValue('latitude', null, {
                                                    shouldDirty: true,
                                                });
                                                setValue('longitude', null, {
                                                    shouldDirty: true,
                                                });
                                                setValue(
                                                    'localizacao_origem',
                                                    null,
                                                    {
                                                        shouldDirty: true,
                                                    },
                                                );
                                            }
                                        }}
                                        aria-label="Eleitor do vereador"
                                    />
                                )}
                            />
                        </div>
                        <div className="flex min-h-14 items-center justify-between gap-3 rounded-md border p-3">
                            <span className="min-w-0">
                                <span className="block text-sm font-medium">
                                    Consentimento para contato
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    O cidadão autorizou receber mensagens e
                                    retornos do gabinete.
                                </span>
                            </span>
                            <Controller
                                control={control}
                                name="consentimento_contato"
                                render={({ field }) => (
                                    <Switch
                                        checked={field.value}
                                        onCheckedChange={field.onChange}
                                        aria-label="Consentimento para contato"
                                    />
                                )}
                            />
                        </div>
                    </div>
                    <div className="flex min-h-14 items-center justify-between gap-3 rounded-md border p-3">
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">
                                Notificações operacionais pelo WhatsApp
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {whatsappConsentText}
                            </span>
                        </span>
                        <Controller
                            control={control}
                            name="whatsapp_consentimento_operacional"
                            render={({ field }) => (
                                <Switch
                                    checked={field.value}
                                    onCheckedChange={field.onChange}
                                    aria-label="Consentimento para notificações pelo WhatsApp"
                                />
                            )}
                        />
                    </div>
                    <FieldError
                        message={
                            errors.whatsapp_consentimento_operacional?.message
                        }
                    />
                    {eleitor && (
                        <div className="space-y-2">
                            <CitizenLocationPicker
                                address={locationSearchAddress}
                                coordinates={coordinates}
                                source={locationSource}
                                onChange={(nextCoordinates, source) => {
                                    setValue(
                                        'latitude',
                                        nextCoordinates?.latitude ?? null,
                                        {
                                            shouldDirty: true,
                                            shouldValidate: true,
                                        },
                                    );
                                    setValue(
                                        'longitude',
                                        nextCoordinates?.longitude ?? null,
                                        {
                                            shouldDirty: true,
                                            shouldValidate: true,
                                        },
                                    );
                                    setValue('localizacao_origem', source, {
                                        shouldDirty: true,
                                    });
                                }}
                            />
                            <FieldError
                                message={
                                    errors.latitude?.message ??
                                    errors.longitude?.message
                                }
                            />
                        </div>
                    )}
                </div>
            </Card>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => history.back()}
                >
                    Cancelar
                </Button>
                <Button type="submit" disabled={isSubmitting}>
                    {citizen ? 'Salvar alterações' : 'Cadastrar cidadão'}
                </Button>
            </div>
        </form>
    );
}
