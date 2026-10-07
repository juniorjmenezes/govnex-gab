import { Head, Link } from '@inertiajs/react';
import { AppLogoMark, AppWordmark } from '@/components/app-logo-mark';
import {
    ClockCircleIcon,
    CompassIcon,
    DangerTriangleIcon,
    GhostIcon,
    SettingsIcon,
    ShieldCrossIcon,
} from '@/components/icons';
import { Button } from '@/components/ui/button';
import { ErrorPage } from '@govnex/ui';
import type { IconComponent } from '@govnex/ui';

type Props = {
    status: number;
};

const content: Record<
    number,
    { title: string; description: string; icon: IconComponent }
> = {
    403: {
        title: 'Acesso não autorizado',
        description: 'Você não tem permissão para acessar esta página.',
        icon: ShieldCrossIcon,
    },
    404: {
        title: 'Página não encontrada',
        description: 'O endereço acessado não existe ou foi movido.',
        icon: GhostIcon,
    },
    419: {
        title: 'Sessão expirada',
        description:
            'Sua sessão expirou por inatividade. Atualize a página e tente de novo.',
        icon: ClockCircleIcon,
    },
    429: {
        title: 'Muitas tentativas',
        description:
            'Você fez muitas requisições em pouco tempo. Aguarde um instante e tente de novo.',
        icon: DangerTriangleIcon,
    },
    500: {
        title: 'Erro interno',
        description:
            'Algo deu errado do nosso lado. Já fomos notificados e estamos cuidando disso.',
        icon: CompassIcon,
    },
    503: {
        title: 'Em manutenção',
        description:
            'O sistema está passando por manutenção. Voltamos em breve.',
        icon: SettingsIcon,
    },
};

export default function ErrorFallback({ status }: Props) {
    const { title, description, icon } = content[status] ?? {
        title: 'Algo deu errado',
        description: 'Não foi possível concluir a ação. Tente novamente.',
        icon: DangerTriangleIcon,
    };

    return (
        <>
            <Head title={title} />
            <ErrorPage
                status={status}
                title={title}
                description={description}
                icon={icon}
                brand={
                    <div className="flex flex-col items-center gap-2">
                        <AppLogoMark
                            className="h-8 w-auto"
                            aria-hidden="true"
                        />
                        <AppWordmark className="text-xl font-semibold tracking-tight" />
                    </div>
                }
                action={
                    <Button asChild>
                        <Link href="/">Voltar ao início</Link>
                    </Button>
                }
            />
        </>
    );
}
