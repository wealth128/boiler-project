import { fileURLToPath } from "node:url";
import { defineConfig } from "vitest/config";

/**
 * Only for the small browser-side tests that run without a browser
 * (resources/js, files ending in .test.ts). They are started by
 * tests/Feature/Security/SvgChartWhitelistTest.php, or by `npx vitest run`.
 * It does not use the app's vite.config.ts.
 */
export default defineConfig({
    resolve: {
        alias: {
            "@": fileURLToPath(new URL("./resources/js", import.meta.url)),
        },
    },
    test: {
        include: ["resources/js/**/*.test.ts"],
        environment: "node",
    },
});
