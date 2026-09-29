<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useTheme } from '../composables/useTheme'
import { Lock, Mail, ArrowRight, ShieldCheck, Plug, Layers, Sun, Moon } from 'lucide-vue-next'

const auth = useAuthStore()
const router = useRouter()
const { isDark, toggle } = useTheme()
const email = ref('admin@1techlink.com')
const password = ref('password')
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push('/dashboard')
  } catch (e) {
    error.value = e.response?.data?.message || e.message || 'That didn\u2019t work — check the email and password.'
  } finally {
    loading.value = false
  }
}

function fill(which) {
  email.value = which === 'admin' ? 'admin@1techlink.com' : 'manager@1techlink.com'
  password.value = 'password'
}
</script>

<template>
  <div class="login-shell">
    <div class="login-wrap">
      <aside class="login-aside">
        <div class="row">
          <div class="brand-mark" style="background:#fff;color:#1d6ff2">◈</div>
          <div class="brand-text">
            <b style="color:#fff">ITechLink</b>
            <span style="color:#93a5d4">Social Hub</span>
          </div>
          <span class="badge outline" style="margin-left:auto;background:transparent;border-color:rgba(255,255,255,.2);color:#9db1dd">Demo</span>
        </div>

        <h1>All your channels, one calm screen.</h1>
        <p>Write once, schedule sensibly, and see what actually landed — without juggling five tabs.</p>

        <div class="stack g1 mt3">
          <div class="login-feature">
            <ShieldCheck />
            <div>
              <b>Locked down properly</b>
              <span>Hashed passwords, CSRF on every write, and an audit trail you can actually read.</span>
            </div>
          </div>
          <div class="login-feature">
            <Plug />
            <div>
              <b>Mock today, live tomorrow</b>
              <span>Flip SOCIAL_MODE to live and the same buttons talk to the real APIs.</span>
            </div>
          </div>
          <div class="login-feature">
            <Layers />
            <div>
              <b>Every channel, tracked solo</b>
              <span>One post can succeed on Instagram and flop on LinkedIn — you\u2019ll see both.</span>
            </div>
          </div>
        </div>

        <div style="margin-top:auto;padding-top:18px;border-top:1px solid rgba(255,255,255,.12);font-size:11.5px;color:#8b99c9">
          © 2026 1TechLink · Say it in real time.
        </div>
      </aside>

      <section class="login-main">
        <div class="row-between">
          <div class="kicker">Sign in</div>
          <button class="icon-btn theme-toggle" :title="isDark ? 'Light mode' : 'Dark mode'" @click="toggle">
            <Sun v-if="isDark" /><Moon v-else />
          </button>
        </div>
        <h2 style="font-size:20px;font-weight:750;letter-spacing:-.02em;margin-top:4px">Welcome back</h2>
        <p style="font-size:13.5px;color:var(--fg-subtle);margin-top:3px">Pick a demo account below, or use your own.</p>

        <div v-if="error" class="alert error mt2">
          <Lock />{{ error }}
        </div>

        <form @submit.prevent="submit" class="stack g2 mt3">
          <div class="field">
            <label class="label" for="email">Work email</label>
            <div class="input-icon">
              <Mail />
              <input id="email" v-model="email" type="email" required placeholder="you@1techlink.com" autocomplete="username" />
            </div>
          </div>

          <div class="field">
            <label class="label" for="password">Password</label>
            <div class="input-icon">
              <Lock />
              <input id="password" v-model="password" type="password" required placeholder="••••••••" autocomplete="current-password" />
            </div>
          </div>

          <button class="btn primary lg block" type="submit" :disabled="loading">
            {{ loading ? 'Checking…' : 'Sign in' }}
            <ArrowRight v-if="!loading" />
          </button>
        </form>

        <div class="card card-p-sm mt3" style="background:var(--surface-2)">
          <div class="kicker">Tap to fill in</div>
          <div class="stack g1 mt1">
            <button class="row-between" style="width:100%;text-align:left" @click="fill('admin')">
              <span class="badge brand">Admin — full access</span>
              <span class="mono muted">admin@1techlink.com</span>
            </button>
            <button class="row-between" style="width:100%;text-align:left" @click="fill('manager')">
              <span class="badge muted">Manager — write + schedule</span>
              <span class="mono muted">manager@1techlink.com</span>
            </button>
            <div class="faint" style="font-size:11.5px">Password for both: <code>password</code></div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
