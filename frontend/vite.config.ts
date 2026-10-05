import { spawn } from "node:child_process";
import type { ChildProcess } from "node:child_process";
import { fileURLToPath } from "node:url";
import { defineConfig } from "vite";
import type { Plugin } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

// Both ports are in the machine-wide port registry (aidingminds/docs/server-ports-at-a-glance.csv).
const VITE_DEV_PORT = 9500;
const PHP_API_PORT = 9501;

/**
 * Dev only: starts the PHP API alongside the Vite dev server and stops it with it, so `npm run dev`
 * is the one command that brings the whole app up. Without the API every /api call answers 502 and
 * the app has no name, no session and no data.
 */
function phpApiServer(): Plugin {
  let phpProcess: ChildProcess | null = null;

  return {
    name: "payroll-php-api-server",
    apply: "serve",
    configureServer(viteServer) {
      const publicDirectory = fileURLToPath(new URL("../public", import.meta.url));

      phpProcess = spawn("php", ["-S", `127.0.0.1:${PHP_API_PORT}`, "-t", publicDirectory, `${publicDirectory}/index.php`], {
        stdio: ["ignore", "ignore", "inherit"],
      });
      phpProcess.on("error", (spawnError) => {
        viteServer.config.logger.error(`PHP API could not be started (is php on your PATH?): ${spawnError.message}`);
      });
      phpProcess.on("exit", (exitCode) => {
        if (exitCode !== null && exitCode !== 0) {
          viteServer.config.logger.error(`PHP API stopped with exit code ${exitCode}. Is port ${PHP_API_PORT} already in use?`);
        }
      });
      viteServer.config.logger.info(`  PHP API on http://127.0.0.1:${PHP_API_PORT}`);

      const stopPhpApi = () => phpProcess?.kill();
      viteServer.httpServer?.once("close", stopPhpApi);
      process.once("exit", stopPhpApi);
    },
  };
}

// Dev: this server serves the app and proxies /api to the PHP API, so the browser sees one origin
// and the session cookie needs no CORS.
// Build: static files into public/spa/, served by the PHP vhost under /spa/.
export default defineConfig(({ command }) => ({
  plugins: [react(), tailwindcss(), phpApiServer()],
  base: command === "build" ? "/spa/" : "/",
  build: {
    outDir: "../public/spa",
    emptyOutDir: true,
  },
  server: {
    port: VITE_DEV_PORT,
    strictPort: true,
    proxy: {
      "/api": `http://127.0.0.1:${PHP_API_PORT}`,
    },
  },
}));
