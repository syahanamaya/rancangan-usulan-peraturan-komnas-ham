import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

const phpBaseUrl = process.env.PHP_BASE_URL || 'http://localhost/RancanganPeraturan';

export default defineConfig({
  root: 'frontend',
  base: './',
  plugins: [react()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: true,
  },
  server: {
    proxy: {
      '/api': {
        target: phpBaseUrl,
        changeOrigin: true,
      },
      '/Logo_Komnas_HAM.png': {
        target: phpBaseUrl,
        changeOrigin: true,
      },
    },
  },
});
