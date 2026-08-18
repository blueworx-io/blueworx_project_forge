import { defineConfig } from 'vite';
import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [ react(), tailwindcss() ],
  resolve: {
    alias: { '@': path.resolve( __dirname, './src' ) },
  },
  build: {
    outDir: 'assets',
    emptyOutDir: true,
    rollupOptions: {
      input: 'index.html',
      output: {
        format: 'iife',
        inlineDynamicImports: true,
        entryFileNames: 'js/blueworx-forge.js',
        assetFileNames: ( info ) => {
          if ( info.name?.endsWith( '.css' ) ) return 'css/blueworx-forge.css';
          return 'img/[name][extname]';
        },
      },
    },
  },
  assetsInclude: [ '**/*.svg', '**/*.csv' ],
});
