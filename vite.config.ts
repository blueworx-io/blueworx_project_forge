import { defineConfig } from 'vite';
import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [ react(), tailwindcss() ],
  resolve: {
    alias: { '@': path.resolve( __dirname, './src' ) },
  },
  // Relative URLs, so the stylesheet finds its fonts from wherever WordPress
  // serves the plugin (#295). The default is absolute from the site root, which
  // is never where a plugin lives.
  base: './',
  build: {
    outDir: 'assets',
    // One real stylesheet rather than styles injected by the script: a font
    // URL in an injected style tag resolves against the page, not the plugin.
    cssCodeSplit: false,
    emptyOutDir: true,
    rollupOptions: {
      input: 'index.html',
      output: {
        format: 'iife',
        inlineDynamicImports: true,
        entryFileNames: 'js/blueworx-forge.js',
        assetFileNames: ( info ) => {
          if ( info.name?.endsWith( '.css' ) ) return 'css/blueworx-forge.css';
          // Beside the stylesheet, not in assets/fonts: that folder is the admin
          // design system's, hashed whole by the foundation's drift check.
          if ( /\.woff2?$/.test( info.name ?? '' ) ) return 'css/fonts/[name][extname]';
          return 'img/[name][extname]';
        },
      },
    },
  },
  assetsInclude: [ '**/*.svg', '**/*.csv' ],
});
