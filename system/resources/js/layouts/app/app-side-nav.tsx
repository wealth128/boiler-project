import { Link, router } from '@inertiajs/react';
import { Fragment } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { APP_NAV_GROUPS, APP_NAV_ITEMS } from './nav-items';

const linkClass =
    'rounded-md px-3 py-2 text-left text-sm font-medium whitespace-nowrap text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring';

/**
 * Left menu. Shows only what the user's role allows. On narrow screens it
 * becomes a row that scrolls sideways under the top bar.
 */
export function AppSideNav() {
    const { can } = usePermissions();
    const { currentUrl } = useCurrentUrl();

    const visible = APP_NAV_ITEMS.filter((item) => can(item.permission));
    const groups = APP_NAV_GROUPS.map((group) => ({
        ...group,
        items: visible.filter((item) => item.group === group.key),
    })).filter((group) => group.items.length > 0);

    const isActive = (path: string) =>
        currentUrl === path || currentUrl.startsWith(`${path}/`);

    return (
        <nav
            aria-label="Main"
            className="flex shrink-0 gap-1 overflow-x-auto border-b bg-card p-2 md:w-52 md:flex-col md:gap-0.5 md:overflow-x-visible md:border-r md:border-b-0 md:py-3"
        >
            {groups.map((group) => (
                <Fragment key={group.key}>
                    {group.label && (
                        <p className="hidden px-3 pt-4 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase md:block">
                            {group.label}
                        </p>
                    )}
                    {group.items.map((item) => {
                        const active = isActive(item.activePath);

                        return (
                            <Link
                                key={item.title}
                                href={item.href}
                                prefetch
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    linkClass,
                                    active &&
                                        'bg-navy-soft font-semibold text-navy hover:bg-navy-soft hover:text-navy',
                                )}
                            >
                                {item.title}
                            </Link>
                        );
                    })}
                </Fragment>
            ))}

            <Link
                href={logout()}
                as="button"
                onClick={() => router.flushAll()}
                className={cn(
                    linkClass,
                    'ml-auto cursor-pointer md:mt-auto md:ml-0',
                )}
                data-test="logout-button"
            >
                Log out
            </Link>
        </nav>
    );
}
