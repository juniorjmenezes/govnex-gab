import { MonitorIcon, MoonIcon, SunIcon } from '@solar-icons/react/outline';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import type { IconComponent } from '@/types/icon';

export default function AppearanceToggleTab({
    className = '',
}: {
    className?: string;
}) {
    const { appearance, updateAppearance } = useAppearance();

    const tabs: { value: Appearance; icon: IconComponent; label: string }[] = [
        { value: 'light', icon: SunIcon, label: 'Claro' },
        { value: 'dark', icon: MoonIcon, label: 'Escuro' },
        { value: 'system', icon: MonitorIcon, label: 'Sistema' },
    ];

    return (
        <ToggleGroup
            type="single"
            value={appearance}
            onValueChange={(value) => {
                if (value) {
                    updateAppearance(value as Appearance);
                }
            }}
            variant="outline"
            spacing={0}
            aria-label="Tema da interface"
            className={cn('w-full sm:w-fit', className)}
        >
            {tabs.map(({ value, icon: Icon, label }) => (
                <ToggleGroupItem
                    key={value}
                    value={value}
                    aria-label={`Tema ${label.toLowerCase()}`}
                    className="flex-1 sm:flex-none"
                >
                    <Icon aria-hidden="true" />
                    <span>{label}</span>
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
