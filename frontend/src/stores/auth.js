import { defineStore } from 'pinia'
import client, { fetchCsrf, setCsrf, getCsrf } from '../api/client'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    csrf: null,
    loading: false,
  }),
  getters: {
    isAdmin: (s) => s.user?.role === 'admin',
    isAuth: (s) => !!s.user,
  },
  actions: {
    async init() {
      try {
        const csrf = await fetchCsrf()
        this.csrf = csrf
        setCsrf(csrf)
      } catch {}
      try {
        const { data } = await client.get('/public/api/auth.php?action=me')
        if (data.success) {
          this.user = data.user
          this.csrf = data.csrf_token
          setCsrf(data.csrf_token)
        }
      } catch { /* not logged */ }
    },
    async login(email, password) {
      if (!this.csrf) await fetchCsrf().then(t=>{this.csrf=t; setCsrf(t)})
      const { data } = await client.post('/public/api/auth.php?action=login', {
        email, password, csrf_token: getCsrf()
      })
      if (!data.success) throw new Error(data.message)
      this.user = data.user
      this.csrf = data.csrf_token
      setCsrf(data.csrf_token)
      return data
    },
    async logout() {
      await client.post('/public/api/auth.php?action=logout', { csrf_token: getCsrf() })
      this.user = null
    }
  }
})
