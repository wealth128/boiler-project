import { Link, router } from "@inertiajs/react";
import { ChevronDown, ChevronRight, Menu } from "lucide-react";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from "@/components/ui/sheet";
import { useCurrentUrl } from "@/hooks/use-current-url";
import { usePermissions } from "@/hooks/use-permissions";
import { cn } from "@/lib/utils";
import { logout } from "@/routes";
import { APP_NAV_GROUPS, APP_NAV_ITEMS } from "./nav-items";
import type { AppNavItem } from "./nav-items";

const linkClass =
    "flex min-h-10 items-center rounded-md px-3 py-2 text-left text-sm font-medium whitespace-nowrap text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-2 focus-visible:outline-sidebar-ring";

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
 * The same sections are used by the side menu (1024 px and wider) and by the
 * slide-in menu (below 1024 px).
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
                    className="flex w-full cursor-pointer items-center justify-between rounded-md px-3 pt-4 pb-1 text-xs font-semibold tracking-wide text-sidebar-muted uppercase hover:text-sidebar-foreground focus-visible:outline-2 focus-visible:outline-sidebar-ring"
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
                className={cn(!label || open ? "contents" : "hidden")}
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
                                    "bg-sidebar-primary font-semibold text-sidebar-primary-foreground hover:bg-sidebar-primary hover:text-sidebar-primary-foreground",
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

/** The sections and the Log out button, shared by both menus. */
function NavContent() {
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
        <>
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
                    "mt-4 cursor-pointer border border-sidebar-bad font-semibold text-sidebar-bad hover:bg-sidebar-accent hover:text-sidebar-bad lg:mt-auto",
                )}
                data-test="logout-button"
            >
                Log out
            </Link>
        </>
    );
}

/**
 * Left menu for screens 1024 px and wider. Shows only what the user's role
 * allows. Below 1024 px it is replaced by the menu button in the top bar
 * (MobileMenu).
 */
export function AppSideNav() {
    return (
        <nav
            aria-label="Main"
            className="hidden w-52 shrink-0 flex-col gap-0.5 border-r border-sidebar-border bg-sidebar px-2 py-3 text-sidebar-foreground lg:flex print:hidden"
        >
            <NavContent />
        </nav>
    );
}

/**
 * Menu button (hamburger) and slide-in panel for screens below 1024 px.
 * Same links and same collapsible ADMINISTRATION section as the side menu.
 * The panel closes by itself when you open a page.
 */
export function MobileMenu() {
    const [open, setOpen] = useState(false);
    const { currentUrl } = useCurrentUrl();
    const [seenUrl, setSeenUrl] = useState(currentUrl);

    // Opening a page closes the panel.
    if (seenUrl !== currentUrl) {
        setSeenUrl(currentUrl);
        setOpen(false);
    }

    // The panel is for small screens: a window made wider closes it.
    useEffect(() => {
        const wide = window.matchMedia("(min-width: 1024px)");
        const close = () => wide.matches && setOpen(false);

        wide.addEventListener("change", close);

        return () => wide.removeEventListener("change", close);
    }, []);

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-10 text-bar-foreground hover:bg-sidebar-accent hover:text-bar-foreground lg:hidden print:hidden"
                    aria-label="Open menu"
                    data-test="menu-button"
                >
                    <Menu className="size-6" aria-hidden="true" />
                </Button>
            </SheetTrigger>
            <SheetContent
                side="left"
                className="w-72 max-w-[85vw] gap-0 overflow-y-auto border-sidebar-border bg-sidebar p-0 text-sidebar-foreground lg:hidden"
            >
                <SheetTitle className="border-b border-sidebar-border px-4 py-3 text-base text-sidebar-foreground">
                    Menu
                </SheetTitle>
                <SheetDescription className="sr-only">
                    Pages you can open
                </SheetDescription>
                <nav
                    aria-label="Main menu"
                    className="flex flex-1 flex-col gap-0.5 px-2 py-3"
                >
                    <NavContent />
                </nav>
            </SheetContent>
        </Sheet>
    );
}
