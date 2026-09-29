import axios from 'axios'

// Mount point of the app on the web server. Must match APP_URL's path and the
// `base` option in vite.config.js. Override with VITE_BASE_PATH.
const BASE = (import.meta.env.VITE_BASE_PATH || '/PHP_projects/social-hub').replace(/\/$/, '')

const client = axios.create({
  baseURL: BASE,
  withCredentials: true,
  headers: { 'Content-Type': 'application/json' },
  // A hung request should not leave the UI spinning forever.
  timeout: 45000,
})

let csrfToken = null

export async function fetchCsrf() {
  const { data } = await client.get('/public/api/csrf.php')
  csrfToken = data.csrf_token
  return csrfToken
}
export function getCsrf() { return csrfToken }
export function setCsrf(t) { csrfToken = t }

// attach csrf to every request
client.interceptors.request.use(config => {
  if (csrfToken && ['post','put','delete','patch'].includes((config.method||'').toLowerCase())) {
    config.headers['X-CSRF-TOKEN'] = csrfToken
    // also add to body if json
    if (config.data && typeof config.data === 'object' && !(config.data instanceof FormData)) {
      config.data.csrf_token = csrfToken
    }
  }
  return config
})

export default client
export { BASE }
