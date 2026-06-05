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
    rollupOptions: {
      input: 'index.html',
      output: {
        entryFileNames: 'js/forge-app.js',
        chunkFileNames: 'js/forge-app-[hash].js',
        assetFileNames: ( info ) => {
          if ( info.name?.endsWith( '.css' ) ) return 'css/forge-app.css';
          return 'img/[name][extname]';
        },
      },
    },
  },
  assetsInclude: [ '**/*.svg', '**/*.csv' ],
});
