/// <reference types="vitest/config" />
import { defineConfig, type Plugin } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = path.dirname(fileURLToPath(import.meta.url));
const frontendDir = path.join(rootDir, 'frontend');
const outDir = path.join(rootDir, 'public/assets');
const hotFile = path.join(outDir, '.hot');

const DEV_PORT = 5173;
const DEV_ORIGIN = `http://localhost:${DEV_PORT}`;

/**
 * While `npm run dev` runs, public/assets/.hot tells PHP to load scripts
 * from the Vite dev server instead of the built files.
 */
function hotFilePlugin(): Plugin {
  return {
    name: 'belprise-hot-file',
    apply: 'serve',
    configureServer(server) {
      server.httpServer?.once('listening', () => {
        fs.mkdirSync(outDir, { recursive: true });
        fs.writeFileSync(hotFile, DEV_ORIGIN);
      });
      const clean = () => fs.rmSync(hotFile, { force: true });
      process.once('exit', clean);
      for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP'] as const) {
        process.once(signal, () => {
          clean();
          process.exit();
        });
      }
    },
  };
}

export default defineConfig({
  root: frontendDir,
  base: '/assets/',
  publicDir: path.join(frontendDir, 'static'),
  plugins: [svelte(), hotFilePlugin()],
  server: {
    port: DEV_PORT,
    strictPort: true,
    origin: DEV_ORIGIN,
    cors: { origin: 'http://localhost:8080' },
  },
  build: {
    outDir,
    emptyOutDir: true,
    manifest: true,
    assetsDir: '',
    // No data: URIs (strict CSP) — every asset is a file.
    assetsInlineLimit: 0,
    modulePreload: { polyfill: false },
    rollupOptions: {
      input: {
        admin: path.join(frontendDir, 'src/admin/main.ts'),
        client: path.join(frontendDir, 'src/client/main.ts'),
        base: path.join(frontendDir, 'src/styles/base.css'),
      },
      output: {
        assetFileNames: (asset) =>
          asset.names.some((name) => name.endsWith('.woff2'))
            ? 'fonts/[name]-[hash][extname]'
            : '[name]-[hash][extname]',
      },
    },
  },
  test: {
    include: ['src/**/*.test.ts'],
    environment: 'node',
  },
});
