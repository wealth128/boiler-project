import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * The browser's own <select>, styled like <Input>. Use it on data-entry
 * forms: it follows Tab order, opens with the keyboard and jumps to an
 * option when you type its first letters. For menus with icons or rich
 * items, use ui/select instead.
 */
function NativeSelect({
    className,
    ...props
}: React.ComponentProps<'select'>) {
    return (
        <select
            data-slot="native-select"
            className={cn(
                'flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-muted [&>option]:bg-background [&>option]:text-foreground',
                'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                'aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40',
                className,
            )}
            {...props}
        />
    );
}

export { NativeSelect };
