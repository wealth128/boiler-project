import { Link, router } from "@inertiajs/react";
import { ChevronDown, ChevronRight } from "lucide-react";
import { useState } from "react";
import { useCurrentUrl } from "@/hooks/use-current-url";
import { usePermissions } from "@/hooks/use-permissions";
import { cn } from "@/lib/utils";
import { logout } from "@/routes";
import { APP_NAV_GROUPS, APP_NAV_ITEMS } from "./nav-items";
import type { AppNavItem } from "./nav-items";

const linkClass =
    "rounded-md px-3 py-2 text-left text-sm font-medium whitespace-nowrap text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring";

const STORAGE_PREFIX = "nav-group:";

/** Was the group left open? Open when nothing is saved or storage is blocked. */
function readOpen(key: string): boolean {
    try {
        return window.localStorage.getItem(STORAGE_PREFIX + key) !== "closed";
    } catch {
        return true;
    }
}

function saveOpen(key: string, open: boolean): void {
    try {
        window.localStorage.setItem(
            STORAGE_PREFIX + key,
            open ? "open" : "closed",
        );
    } catch {
        // Private window or blocked storage: the menu still works, it just forgets.
    }
}

type NavGroupProps = {
    groupKey: string;
    label: string | null;
    items: AppNavItem[];
    isActive: (path: string) => boolean;
};

/**
 * One section of the menu. A section with a heading is a toggle: the heading
 * is a button (Enter / Space) with an arrow, aria-expanded, and it hides or
 * shows its links. The choice is remembered per browser. A section that holds
 * the current page is always opened when you arrive on that page.
 *
 * On a narrow screen the menu is a single row with no headings, so the links
 * are always shown there.
 */
function NavGroup({ groupKey, label, items, isActive }: NavGroupProps) {
    const hasActive = items.some((item) => isActive(item.activePath));
    const [open, setOpen] = useState(() => hasActive || readOpen(groupKey));
    const [sawActive, setSawActive] = useState(hasActive);

    // Arriving on a page of this section opens it.
    if (hasActive !== sawActive) {
        setSawActive(hasActive);

        if (hasActive) {
            setOpen(true);
        }
    }

    const linksId = `nav-group-${groupKey}`;

    function toggle() {
        setOpen(!open);
        saveOpen(groupKey, !open);
    }

    return (
        <>
            {label && (
                <button
                    type="button"
                    onClick={toggle}
                    aria-expanded={open}
                    aria-controls={linksId}
                    className="hidden w-full cursor-pointer items-center justify-between rounded-md px-3 pt-4 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring md:flex"
                >
                    {label}
                    {open ? (
                        <ChevronDown className="size-4" aria-hidden="true" />
                    ) : (
                        <ChevronRight className="size-4" aria-hidden="true" />
                    )}
                </button>
            )}
            <div
                id={linksId}
                className={cn(
                    !label || open ? "contents" : "hidden max-md:contents",
                )}
            >
                {items.map((item) => {
                    const active = isActive(item.activePath);

                    return (
                        <Link
                            key={item.title}
                            href={item.href}
                            prefetch
                            aria-current={active ? "page" : undefined}
                            className={cn(
                                linkClass,
                                active &&
                                    "bg-navy-soft font-semibold text-navy hover:bg-navy-soft hover:text-navy",
                            )}
                        >
                            {item.title}
                        </Link>
                    );
                })}
            </div>
        </>
    );
}

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
            className="flex shrink-0 gap-1 print:hidden overflow-x-auto border-b bg-card p-2 md:w-52 md:flex-col md:gap-0.5 md:overflow-x-visible md:border-r md:border-b-0 md:py-3"
        >
            {groups.map((group) => (
                <NavGroup
                    key={group.key}
                    groupKey={group.key}
                    label={group.label}
                    items={group.items}
                    isActive={isActive}
                />
            ))}

            <Link
                href={logout()}
                as="button"
                onClick={() => router.flushAll()}
                className={cn(
                    linkClass,
                    // A bordered red button, so it reads as clickable at a glance.
                    "ml-auto cursor-pointer border border-bad font-semibold text-bad hover:bg-bad-soft hover:text-bad md:mt-auto md:ml-0",
                )}
                data-test="logout-button"
            >
                Log out
            </Link>
        </nav>
    );
}
