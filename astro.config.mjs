
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// https://astro.build/config
export default defineConfig({
  site: 'https://soreyapi.com/',
  outDir: './dist',
  publicDir: './public',
  build: {
    assets: 'assets'
  },
  server: {
    port: 3000,
    host: true
  },
  integrations: [sitemap()]
});