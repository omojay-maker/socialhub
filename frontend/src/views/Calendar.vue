<script setup>
import { ref, onMounted, computed } from 'vue'
import client from '../api/client'
import { useTheme } from '../composables/useTheme'
import { Plus, CalendarDays, ListChecks } from 'lucide-vue-next'

const posts = ref([])
const loading = ref(true)
const { cssVar } = useTheme()

onMounted(async () => {
  try {
    const { data } = await client.get('/public/api/calendar.php')
    posts.value = data.posts || []
  } finally {
    loading.value = false
  }
})

const byDate = computed(() => {
  const m = {}
  for (const p of posts.value) {
    const d = (p.scheduled_at || p.created_at || '').slice(0, 10)
    if (!d) continue
    ;(m[d] ||= []).push(p)
  }
  return m
})

const now = new Date()
const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
const daysInMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate()
const firstWeekday = new Date(now.getFullYear(), now.getMonth(), 1).getDay()
const monthLabel = now.toLocaleString('default', { month: 'long', year: 'numeric' })
const scheduled = computed(() => posts.value.filter(p => p.status === 'scheduled'))
const thisWeek = computed(() => {
  const out = []
  for (let i = 0; i < 7; i++) {
    const d = new Date()
    d.setDate(d.getDate() - d.getDay() + i)
    const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    out.push({ key, label: d.toLocaleDateString('en-US', { weekday: 'short' }), n: d.getDate(), items: byDate.value[key] || [] })
  }
  return out
})

const statusColor = s =>
  ({ published: cssVar('--ok'), scheduled: cssVar('--info'), partial: cssVar('--warn'),
     failed: cssVar('--err'), draft: cssVar('--fg-faint') })[s] || cssVar('--fg-faint')

function dateKey(d) {
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
}
</script>

<template>
  <div>
    <div class="page-head" style="margin-bottom:18px">
      <div>
        <div class="eyebrow">Plan</div>
        <h1>Calendar</h1>
        <p>{{ monthLabel }} — what\u2019s already out, what\u2019s queued, and where the gaps are. Scheduled posts go out on the cron run.</p>
      </div>
      <div class="page-actions">
        <span class="badge scheduled">{{ scheduled.length }} in the queue</span>
        <router-link to="/create" class="btn primary"><Plus />New Post</router-link>
      </div>
    </div>

    <div class="panel" style="margin-bottom:16px">
      <div class="panel-head">
        <h3>This week at a glance</h3>
        <span class="sub">{{ posts.length }} posts this month</span>
      </div>
      <div class="panel-body">
        <div class="grid" style="grid-template-columns:repeat(7,minmax(0,1fr));gap:10px">
          <div v-for="d in thisWeek" :key="d.key" class="metric" :style="d.key === today ? { borderColor: 'var(--brand)' } : {}">
            <div class="m-l">{{ d.label }} {{ d.n }}</div>
            <div class="m-v" style="font-size:15px">{{ d.items.length ? `${d.items.length} post${d.items.length > 1 ? 's' : ''}` : '—' }}</div>
            <div class="m-s truncate">{{ d.items[0]?.content?.slice(0, 34) || 'Nothing planned' }}</div>
          </div>
        </div>
      </div>
    </div>

    <div class="panel" style="margin-bottom:16px">
      <div class="panel-head">
        <h3><CalendarDays style="width:14px;height:14px;display:inline;vertical-align:-2px" /> {{ monthLabel }}</h3>
        <span class="sub">Today is highlighted</span>
      </div>
      <div class="panel-body">
        <div class="cal-week-head">
          <span v-for="w in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']" :key="w">{{ w }}</span>
        </div>
        <div class="cal-grid">
          <div v-for="i in firstWeekday" :key="'blank' + i" class="cal-day blank" />
          <div
            v-for="d in daysInMonth"
            :key="d"
            class="cal-day"
            :class="{ today: dateKey(d) === today }"
          >
            <span class="cal-num" :class="{ has: byDate[dateKey(d)]?.length }">{{ d }}</span>
            <div
              v-for="p in (byDate[dateKey(d)] || []).slice(0, 3)"
              :key="p.id"
              class="cal-ev"
              :style="{ borderLeftColor: statusColor(p.status) }"
            >
              <div class="t">{{ p.content }}</div>
              <div class="row g1" style="margin-top:2px">
                <span class="badge" :class="p.status" style="font-size:9px;padding:1px 4px">{{ p.status }}</span>
                <span class="faint truncate" style="font-size:9.5px">{{ p.platforms || '—' }}</span>
              </div>
            </div>
            <div v-if="(byDate[dateKey(d)] || []).length > 3" class="cal-more">
              +{{ byDate[dateKey(d)].length - 3 }} more
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h3><ListChecks style="width:14px;height:14px;display:inline;vertical-align:-2px" /> Waiting to go out</h3>
        <span class="sub">{{ scheduled.length }} queued</span>
      </div>
      <div v-if="loading" class="panel-body faint">Loading…</div>
      <div v-else-if="!scheduled.length" class="panel-body" style="text-align:center;padding:36px">
        <div style="font-weight:700">Queue is empty</div>
        <p class="human-note" style="margin-top:4px">Write something in the <router-link to="/create" class="link">composer</router-link> and hit Schedule — it\u2019ll wait here until cron picks it up.</p>
      </div>
      <div v-else class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Runs at</th><th>Content</th><th>Channels</th><th>Status</th></tr>
          </thead>
          <tbody>
            <tr v-for="p in scheduled" :key="p.id">
              <td class="mono nowrap muted">{{ p.scheduled_at }}</td>
              <td class="primary-cell truncate" style="max-width:480px">{{ p.content }}</td>
              <td><span class="badge outline">{{ p.platforms }}</span></td>
              <td><span class="badge scheduled">queued</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
