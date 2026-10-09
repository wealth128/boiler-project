import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

const TONES = {
    neutral: 'bg-muted text-muted-foreground',
    ok: 'bg-ok-soft text-ok',
    bad: 'bg-bad-soft text-bad',
    warn: 'bg-warn-soft text-warn',
    navy: 'bg-navy-soft text-navy',
    /** Deleted or voided items: struck through. */
    void: 'bg-muted text-muted-foreground line-through',
} as const;

export type ChipTone = keyof typeof TONES;

type ChipProps = ComponentProps<'span'> & {
    tone?: ChipTone;
};

/**
 * Small rounded label, e.g. "New patient", "Closed", or a count next to a
 * heading. For visit categories use <CategoryChip />.
 */
export function Chip({ tone = 'neutral', className, ...props }: ChipProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap tabular-nums',
                TONES[tone],
                className,
            )}
            {...props}
        />
    );
}
