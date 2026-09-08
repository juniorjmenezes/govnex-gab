import {
    BusIcon,
    HandHeartIcon,
    HeartPulseIcon,
    HouseIcon,
    LampIcon,
    LeafIcon,
    MenuDotsCircleIcon,
    ShieldCheckIcon,
    SquareAcademicCapIcon,
    SuitcaseIcon,
    TagIcon,
    ToolboxIcon,
    TrashBinTrashIcon,
} from '@solar-icons/react/outline';
import { cn } from '@/lib/utils';
import type { CategorySemanticColor } from '@/types';
import type { IconComponent } from '@/types/icon';

const iconMap: Record<string, IconComponent> = {
    tag: TagIcon,
    'heart-pulse': HeartPulseIcon,
    'graduation-cap': SquareAcademicCapIcon,
    'hard-hat': ToolboxIcon,
    'lamp-desk': LampIcon,
    'trash-2': TrashBinTrashIcon,
    'bus-front': BusIcon,
    'hand-heart': HandHeartIcon,
    'shield-check': ShieldCheckIcon,
    leaf: LeafIcon,
    house: HouseIcon,
    'briefcase-business': SuitcaseIcon,
    ellipsis: MenuDotsCircleIcon,
};

export const categoryIconOptions = [
    { value: 'tag', label: 'Geral' },
    { value: 'heart-pulse', label: 'Saúde' },
    { value: 'graduation-cap', label: 'Educação' },
    { value: 'hard-hat', label: 'Infraestrutura' },
    { value: 'lamp-desk', label: 'Iluminação' },
    { value: 'trash-2', label: 'Limpeza urbana' },
    { value: 'bus-front', label: 'Transporte' },
    { value: 'hand-heart', label: 'Assistência social' },
    { value: 'shield-check', label: 'Segurança' },
    { value: 'leaf', label: 'Meio ambiente' },
    { value: 'house', label: 'Habitação' },
    { value: 'briefcase-business', label: 'Emprego e renda' },
    { value: 'ellipsis', label: 'Outros' },
] as const;

export const categoryColorOptions: Array<{
    value: CategorySemanticColor;
    label: string;
}> = [
    { value: 'neutra', label: 'Neutra' },
    { value: 'informativa', label: 'Informativa' },
    { value: 'sucesso', label: 'Sucesso' },
    { value: 'atencao', label: 'Atenção' },
    { value: 'critica', label: 'Crítica' },
];

const colorStyles: Record<
    CategorySemanticColor,
    { surface: string; badge: string }
> = {
    neutra: {
        surface: 'bg-muted text-muted-foreground',
        badge: 'bg-muted text-muted-foreground',
    },
    informativa: {
        surface: 'bg-blue-500/10 text-blue-700 dark:text-blue-400',
        badge: 'bg-blue-500/10 text-blue-700 dark:text-blue-400',
    },
    sucesso: {
        surface: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
        badge: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    atencao: {
        surface: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
        badge: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    critica: {
        surface: 'bg-destructive/10 text-destructive',
        badge: 'bg-destructive/10 text-destructive',
    },
};

export function CategoryIcon({
    name,
    className,
}: {
    name: string | null | undefined;
    className?: string;
}) {
    const Icon = iconMap[name ?? ''] ?? TagIcon;

    return <Icon className={className} aria-hidden="true" />;
}

export function CategoryIconBadge({
    name,
    color,
    className,
}: {
    name: string | null | undefined;
    color: CategorySemanticColor;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'grid size-10 shrink-0 place-items-center rounded-sm',
                colorStyles[color].surface,
                className,
            )}
        >
            <CategoryIcon name={name} className="size-5" />
        </span>
    );
}

export function CategorySemanticBadge({
    color,
    className,
}: {
    color: CategorySemanticColor;
    className?: string;
}) {
    const label =
        categoryColorOptions.find((option) => option.value === color)?.label ??
        color;

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium',
                colorStyles[color].badge,
                className,
            )}
        >
            {label}
        </span>
    );
}
