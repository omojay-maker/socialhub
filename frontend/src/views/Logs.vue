<script setup>
import { ref, onMounted, computed } from 'vue'
import client from '../api/client'
import { ScrollText, Search, Send, CalendarDays, UserPlus, RefreshCw, Trash2, LogIn } from 'lucide-vue-next'

const logs = ref([])
const loading = ref(true)
const q = ref('')

onMounted(async () => {
  try {
    const { data } = await client.get('/public/api/logs.php')
    logs.value = data.logs || []
  } finally {
    loading.value = false
  }
})

const shown = computed(() => {
  const t = q.value.trim().toLowerCase()
  if (!t) return logs.value
  return logs.value.filter(l =>
    (l.action || '').toLowerCase().includes(t) ||
    (l.description || '').toLowerCase().includes(t) ||
    (l.user_name || '').toLowerCase().includes(t)
  )
})
const uniqueActions = computed(() => [...new Set(logs.value.map(l => l.action))].length)

function actionIcon(a = '') {
  a = a.toLowerCase()
  if (a.includes('publish') || a.includes('post')) return Send
  if (a.includes('schedul') || a.includes('calendar')) return CalendarDays
  if (a.includes('login') || a.includes('auth')) return LogIn
  if (a.includes('user') || a.includes('invite') || a.includes('team')) return UserPlus
  if (a.includes('sync') || a.includes('account')) return RefreshCw
  if (a.includes('delet')) return Trash2
  return ScrollText
}
function plainAction(a = '') {
  return a.replace(/_/g, ' ')
}
function when(s) {
  if (!s) return ''
  const d = new Date(s.replace(' ', 'T'))
  if (isNaN(d)) return s.slice(0, 16)
  const h = (Date.now() - d.getTime()) / 36e5
  if (h < 1) return 'just now'
  if (h < 24) return `${Math.round(h)}h ago`
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' +
    d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}
</script>

<template>
  <div>
    <div class="page-head" style="margin-bottom:18px">
      <div>
        <div class="eyebrow">Trust</div>
        <h1>Audit log</h1>
        <p>{{ logs.length ? `${logs.length} events · ${uniqueActions} kinds. If something looks off, this is where you\u2019ll find who did what.` : 'A running record of who did what, and when. Nothing here yet.' }}</p>
      </div>
      <div class="topbar-search" style="width:260px">
        <Search />
        <input v-model="q" type="search" placeholder="Filter by person, action…" />
      </div>
    </div>

    <div class="panel">
      <div v-if="loading" class="panel-body faint">Reading the trail…</div>
      <div v-else-if="!shown.length" class="panel-body" style="text-align:center;padding:44px 24px">
        <ScrollText style="width:26px;height:26px;margin:0 auto 10px;color:var(--fg-faint)" />
        <div style="font-weight:700">{{ logs.length ? 'No match for that filter' : 'Nothing recorded yet' }}</div>
        <p class="human-note" style="margin-top:4px">{{ logs.length ? 'Try a name, or just “publish”.' : 'Publish, schedule or invite someone — it\u2019ll show up here.' }}</p>
      </div>
      <div v-else class="list">
        <div v-for="l in shown" :key="l.id" class="list-row">
          <span class="activity-ico"><component :is="actionIcon(l.action)" /></span>
          <div class="grow">
            <div class="row wrap g2">
              <span style="font-weight:650;text-transform:capitalize">{{ plainAction(l.action) }}</span>
              <span class="faint" style="font-size:12px">by {{ l.user_name || 'the system' }} · {{ when(l.created_at) }}</span>
              <span class="mono faint" style="font-size:11px;margin-left:auto">{{ l.ip_address || '' }}</span>
            </div>
            <p class="muted" style="font-size:13px;margin-top:2px">{{ l.description }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
