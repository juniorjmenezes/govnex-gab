import { MoonIcon, SunIcon } from '@solar-icons/react/outline';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import { useIsHydrated } from '@/hooks/use-is-hydrated';

export function ThemeToggle() {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const isHydrated = useIsHydrated();
    const isDark = isHydrated && resolvedAppearance === 'dark';

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
            aria-label={isDark ? 'Ativar tema claro' : 'Ativar tema escuro'}
            title={isDark ? 'Tema claro' : 'Tema escuro'}
        >
            {isDark ? (
                <SunIcon aria-hidden="true" />
            ) : (
                <MoonIcon aria-hidden="true" />
            )}
        </Button>
    );
}
