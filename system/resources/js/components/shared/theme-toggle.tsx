import { Moon, Sun } from "lucide-react";
import { useAppearance } from "@/hooks/use-appearance";

/**
 * Sun / moon button for the top bar: switches between Light and Dark. The
 * choice is remembered (cookie, so the next page loads without a flash).
 * "System" is chosen on Settings > Appearance.
 */
export function ThemeToggle() {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const dark = resolvedAppearance === "dark";
    const Icon = dark ? Sun : Moon;
    const label = dark ? "Switch to light theme" : "Switch to dark theme";

    return (
        <button
            type="button"
            onClick={() => updateAppearance(dark ? "light" : "dark")}
            aria-label={label}
            title={label}
            data-test="theme-toggle"
            className="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-md text-bar-foreground hover:bg-sidebar-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sidebar-ring print:hidden"
        >
            <Icon className="size-5" aria-hidden="true" />
        </button>
    );
}
