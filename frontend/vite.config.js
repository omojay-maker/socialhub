import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  // Where the app is mounted on the web server. Override per environment with
  // VITE_BASE_PATH (e.g. VITE_BASE_PATH=/ for a dedicated vhost). Keep it in
  // sync with APP_URL in config/secrets.php.
  const BASE = (env.VITE_BASE_PATH || '/PHP_projects/social-hub').replace(/\/$/, '')

  return {
    plugins: [vue()],
    base: `${BASE}/public/dist/`,
    build: {
      outDir: '../public/dist',
      emptyOutDir: true,
      sourcemap: false,
    },
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    server: {
      port: 5173,
      proxy: {
        [`${BASE}/public/api`]: { target: 'http://localhost', changeOrigin: true },
        [`${BASE}/storage`]: { target: 'http://localhost', changeOrigin: true },
      },
    },
  }
})
