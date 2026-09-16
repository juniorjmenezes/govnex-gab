import { Flag2BoldIcon, Flag2Icon } from '@/components/icons';
import { cn } from '@/lib/utils';

/**
 * Indica se o cidadão é eleitor do gabinete. A bandeira preenchida marca o
 * eleitor; a de contorno, quando pedida, marca explicitamente quem não é.
 */
export function VoterMark({
    voter,
    showWhenNotVoter = false,
    className,
}: {
    voter: boolean;
    showWhenNotVoter?: boolean;
    className?: string;
}) {
    if (!voter && !showWhenNotVoter) {
        return null;
    }

    const label = voter ? 'Eleitor' : 'Não eleitor';
    const Icon = voter ? Flag2BoldIcon : Flag2Icon;

    return (
        <span className={cn('inline-flex shrink-0', className)} title={label}>
            <Icon
                className={cn(
                    'size-4',
                    voter ? 'text-primary' : 'text-muted-foreground/50',
                )}
                aria-hidden="true"
            />
            <span className="sr-only">{label}</span>
        </span>
    );
}
