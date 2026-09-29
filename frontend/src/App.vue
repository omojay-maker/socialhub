<script setup>
import { useAuthStore } from './stores/auth'
import { useTheme } from './composables/useTheme'
import { useRoute, useRouter } from 'vue-router'
import { computed, ref, watch } from 'vue'
import {
  LayoutDashboard, FileText, SquarePen, CalendarDays, Bell, BarChart3,
  Layers, Images, Users, ScrollText, Settings as SettingsIcon,
  LogOut, PanelLeftClose, PanelLeftOpen, Menu, X, Sun, Moon, Search,
  ChevronDown
} from 'lucide-vue-next'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { isDark, toggle } = useTheme()

const isLogin = computed(() => route.name === 'login')
const mobileOpen = ref(false)
const collapsed = ref(localStorage.getItem('sh_collapsed') === '1')
const search = ref('')

const titles = {
  dashboard: 'Dashboard', posts: 'Posts', create: 'Composer',
  calendar: 'Calendar', notifications: 'Notifications', analytics: 'Analytics',
  accounts: 'Accounts', media: 'Media', users: 'Team', logs: 'Audit log'
}
const title = computed(() => titles[route.name] || 'Workspace')
const showSearch = computed(() => !['login'].includes(route.name))

watch(() => route.name, () => { mobileOpen.value = false })

function runSearch() {
  const term = search.value.trim()
  router.push(term ? { path: '/posts', query: { q: term } } : '/posts')
}
function onKeydown(e) {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    document.getElementById('global-search')?.focus()
  }
}
window.addEventListener('keydown', onKeydown)

function toggleSidebar() {
  collapsed.value = !collapsed.value
  localStorage.setItem('sh_collapsed', collapsed.value ? '1' : '0')
}
async function logout() {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <router-view v-if="isLogin" />

  <div v-else class="app-shell">
    <div v-if="mobileOpen" class="sidebar-backdrop" @click="mobileOpen = false" />

    <aside class="sidebar" :class="{ 'is-collapsed': collapsed, 'is-open': mobileOpen }">
      <div class="sidebar-brand">
        <div class="brand-mark">◈</div>
        <div class="brand-text">
          <b>ITechLink</b>
          <span>Social Hub</span>
        </div>
      </div>

      <nav class="sidebar-nav scroll">
        <router-link to="/dashboard" class="nav-item" active-class="active">
          <LayoutDashboard /><span class="nav-label">Dashboard</span>
        </router-link>
        <router-link to="/posts" class="nav-item" active-class="active">
          <FileText /><span class="nav-label">Posts</span>
        </router-link>
        <router-link to="/create" class="nav-item" active-class="active">
          <SquarePen /><span class="nav-label">Composer</span>
        </router-link>
        <router-link to="/calendar" class="nav-item" active-class="active">
          <CalendarDays /><span class="nav-label">Calendar</span>
        </router-link>
        <router-link to="/analytics" class="nav-item" active-class="active">
          <BarChart3 /><span class="nav-label">Analytics</span>
        </router-link>
        <router-link to="/accounts" class="nav-item" active-class="active">
          <Layers /><span class="nav-label">Accounts</span>
        </router-link>
        <router-link to="/notifications" class="nav-item" active-class="active">
          <Bell /><span class="nav-label">Notifications</span>
          <span class="nav-count">3</span>
        </router-link>
        <router-link to="/media" class="nav-item" active-class="active">
          <Images /><span class="nav-label">Media</span>
        </router-link>
        <router-link v-if="auth.isAdmin" to="/users" class="nav-item" active-class="active">
          <Users /><span class="nav-label">Team</span>
        </router-link>
        <router-link to="/logs" class="nav-item" active-class="active">
          <ScrollText /><span class="nav-label">Audit Log</span>
        </router-link>
        <router-link to="/accounts" class="nav-item" active-class="active">
          <SettingsIcon /><span class="nav-label">Settings</span>
        </router-link>
      </nav>

      <div class="sidebar-foot">
        <div class="user-chip" :title="auth.user?.email || ''">
          <div class="avatar">{{ (auth.user?.name || 'A')[0].toUpperCase() }}</div>
          <div class="user-meta">
            <b>{{ auth.user?.name || 'Admin' }}</b>
            <span>{{ auth.user?.role === 'admin' ? 'Super Admin' : (auth.user?.role?.replace('_', ' ') || 'Team') }}</span>
          </div>
          <button class="icon-btn" title="Sign out" @click="logout"><LogOut /></button>
        </div>
        <div class="brand-foot">
          <div class="brand-mark">◈</div>
          <div>
            <b>ITechLink</b>
            <span>Say it in real time.</span>
          </div>
        </div>
      </div>
    </aside>

    <div class="main">
      <header class="topbar">
        <div class="topbar-left">
          <button class="icon-btn mobile-only" @click="mobileOpen = !mobileOpen">
            <X v-if="mobileOpen" /><Menu v-else />
          </button>
          <button class="icon-btn desktop-only" title="Toggle sidebar" @click="toggleSidebar">
            <PanelLeftOpen v-if="collapsed" /><PanelLeftClose v-else />
          </button>
          <span class="topbar-title mobile-only">{{ title }}</span>
        </div>

        <div class="topbar-center">
          <form v-if="showSearch" class="topbar-search desktop-only" @submit.prevent="runSearch">
            <Search />
            <input id="global-search" v-model="search" type="search" placeholder="Search posts, accounts, or keywords..." />
            <span class="kbd">⌘ K</span>
          </form>
        </div>

        <div class="topbar-right">
          <button class="icon-btn theme-toggle desktop-only" :title="isDark ? 'Light mode' : 'Dark mode'" @click="toggle">
            <Sun v-if="isDark" /><Moon v-else />
          </button>
          <router-link to="/notifications" class="icon-btn bell-wrap" title="Notifications">
            <Bell />
            <span class="bell-badge">3</span>
          </router-link>
          <div class="profile-chip">
            <div class="avatar">{{ (auth.user?.name || 'A')[0].toUpperCase() }}</div>
            <div class="profile-meta desktop-only">
              <b>{{ auth.user?.name || 'Admin' }}</b>
              <span>{{ auth.user?.role === 'admin' ? 'Super Admin' : (auth.user?.role || 'Team') }}</span>
            </div>
            <ChevronDown class="desktop-only" style="width:15px;height:15px;color:var(--fg-faint)" />
          </div>
        </div>
      </header>

      <main class="content scroll">
        <div class="content-inner">
          <router-view />
        </div>
      </main>
    </div>
  </div>
</template>
