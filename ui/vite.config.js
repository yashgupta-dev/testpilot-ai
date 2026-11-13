import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Builds straight into the Laravel package so the dashboard ships with it (no publish step).
export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: '../packages/laravel/dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/main.js',
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'app-[name].js',
        assetFileNames: (info) => (info.name && info.name.endsWith('.css') ? 'app.css' : '[name][extname]'),
        inlineDynamicImports: true,
      },
    },
  },
})
