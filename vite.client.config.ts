import { defineConfig } from 'vite';
import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

/*
 * The client plugin's build (#297). A second entry rather than a switch in
 * the studio's: the two bundles ship in different plugins, and ARCH-1 wants a
 * client site to be physically unable to carry studio code. The source lives
 * beside the studio's in src/ and shares the kit; only what this entry
 * imports reaches client/assets.
 */
export default defineConfig({
  plugins: [ react(), tailwindcss() ],
  root: 'client',
  resolve: {
    alias: { '@': path.resolve( __dirname, './src' ) },
  },
  base: './',
  server: { port: 5174 },
  build: {
    outDir: 'assets',
    cssCodeSplit: false,
    emptyOutDir: true,
    rollupOptions: {
      input: path.resolve( __dirname, 'client/index.html' ),
      output: {
        format: 'iife',
        inlineDynamicImports: true,
        entryFileNames: 'js/blueworx-forge-client.js',
        assetFileNames: ( info ) => {
          if ( info.name?.endsWith( '.css' ) ) return 'css/blueworx-forge-client.css';
          if ( /\.woff2?$/.test( info.name ?? '' ) ) return 'css/fonts/[name][extname]';
          return 'img/[name][extname]';
        },
      },
    },
  },
});
