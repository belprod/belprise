/**
 * Site vitrine belprise.fr (CAHIER_DES_CHARGES §5.15): static pages, no
 * JavaScript, same fonts and colours as the application. Built into
 * site/dist/, the web root of belprise.fr (indexable, unlike the application).
 */
import { defineConfig } from 'vite';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = path.dirname(fileURLToPath(import.meta.url));
const siteDir = path.join(rootDir, 'site');

// Address of the application (sign-in and sign-up buttons): %VITE_APP_URL% in the pages.
process.env.VITE_APP_URL ??= 'https://app.belprise.fr';

export default defineConfig({
  root: siteDir,
  base: '/',
  publicDir: path.join(siteDir, 'static'),
  build: {
    outDir: path.join(siteDir, 'dist'),
    emptyOutDir: true,
    assetsDir: 'assets',
    assetsInlineLimit: 0,
    rollupOptions: {
      input: {
        index: path.join(siteDir, 'index.html'),
        legal: path.join(siteDir, 'mentions-legales.html'),
        terms: path.join(siteDir, 'cgu.html'),
        privacy: path.join(siteDir, 'confidentialite.html'),
        notFound: path.join(siteDir, '404.html'),
      },
    },
  },
  server: { port: 5174, strictPort: true },
});
