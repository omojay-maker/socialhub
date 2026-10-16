<script setup>
import { ref, onMounted, computed, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import client from '../api/client'
import { useTheme } from '../composables/useTheme'
import { useAuthStore } from '../stores/auth'
import { Chart, registerables } from 'chart.js'
import {
  Users, Heart, Send, Eye, ArrowUp, ArrowDown, Plus, Moon, Sun,
  Pencil, CalendarDays, Layers, ChevronRight, Bell,
  Instagram, Linkedin, Music2, MessageSquare, Share2
} from 'lucide-vue-next'

Chart.register(...registerables)
Chart.defaults.font.family = "'Inter',system-ui,sans-serif"
Chart.defaults.font.size = 10.5

const router = useRouter()
const auth = useAuthStore()
const { cssVar, onChange, isDark, toggle } = useTheme()

const summary = ref({ followers: 0, posts: 0, scheduled: 0, engagement: 0, reach: 0, impressions: 0 })
const accounts = ref([])
const recent = ref([])
const byPlatform = ref([])
const notifications = ref([])
const unread = ref(0)
const loading = ref(true)
const range = ref('7D')
const postFilter = ref('All')
const trend = ref({ current: { interactions: 0, reach: 0, posts: 0, followers: 0 }, previous: { interactions: 0, reach: 0, posts: 0, followers: 0 } })
const daily = ref([])

const hour = new Date().getHours()
const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
const firstName = computed(() => (auth.user?.name || 'Admin').split(' ')[0])
const dateLabel = new Date().toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })

const avgRate = computed(() =>
  byPlatform.value.length
    ? (byPlatform.value.reduce((a, p) => a + Number(p.eng_rate || 0), 0) / byPlatform.value.length)
    : 0
)
const totalInteractions = computed(() =>
  byPlatform.value.reduce((a, p) => a + Number(p.likes || 0) + Number(p.comments || 0) + Number(p.shares || 0), 0)
)
const audience = computed(() => Number(summary.value.followers) || 0)
const published = computed(() => Number(summary.value.posts) || 0)

function platClsOf(name = '') {
  const s = name.toLowerCase()
  if (s.includes('insta')) return 'ig'
  if (s.includes('tikt')) return 'tt'
  return 'li'
}
function platBarOf(name = '') {
  const s = name.toLowerCase()
  if (s.includes('insta')) return 'purple'
  if (s.includes('tikt')) return 'pink'
  return 'cyan'
}
const maxFollowers = computed(() => Math.max(1, ...channels.value.map(c => c.followers)))
const channels = computed(() => {
  if (byPlatform.value.length) {
    return byPlatform.value.slice(0, 4).map(p => ({
      name: p.name,
      followers: Number(p.followers || p.reach || 0),
      growth: `+${(Number(p.eng_rate) || 1.5).toFixed(1)}%`,
      cls: platClsOf(p.name),
      bar: platBarOf(p.name),
      letter: p.name[0]
    }))
  }
  return []
})

const filteredPosts = computed(() => {
  if (postFilter.value === 'All') return recent.value
  return recent.value.filter(p => (p.platforms || '').toLowerCase().includes(postFilter.value.toLowerCase()))
})

const connectedCount = computed(() => accounts.value.length)

function platOf(p) {
  const s = (p.platforms || '').toLowerCase()
  if (s.includes('insta')) return 'Instagram'
  if (s.includes('tikt')) return 'TikTok'
  if (s.includes('link')) return 'LinkedIn'
  return (p.platforms || 'Instagram').split(',')[0].trim() || 'Instagram'
}
function hashtags(text = '') {
  const m = text.match(/#\w+/g)
  return m ? m.slice(0, 4).join(' ') : ''
}
function plainText(text = '') {
  return text.replace(/#\w+/g, '').trim() || text
}
function eng(post, key) {
  // Real per-post engagement only: the list endpoint carries no counters,
  // so unknown renders as "—" instead of a made-up number.
  if (post[key] !== undefined && post[key] !== null) return post[key]
  return null
}
function fmtDate(s) {
  if (!s) return ''
  const d = new Date(s.replace(' ', 'T'))
  if (isNaN(d)) return s.slice(0, 16)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
    ' · ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}
function timeAgo(s) {
  if (!s) return ''
  const d = new Date(s.replace(' ', 'T'))
  if (isNaN(d)) return ''
  const h = Math.max(1, Math.round((Date.now() - d.getTime()) / 36e5))
  return h < 24 ? `${h}h ago` : `${Math.round(h / 24)}d ago`
}

/* ---- sparklines from the real daily series (hidden when too thin) ---- */
function sparkPath(values, w = 92, h = 36) {
  if (!values.length) return ''
  const min = Math.min(...values), max = Math.max(...values)
  const span = max - min || 1
  const step = values.length > 1 ? w / (values.length - 1) : 0
  const pts = values.map((v, i) => {
    const x = (i * step).toFixed(1)
    const y = (h - 4 - ((v - min) / span) * (h - 10)).toFixed(1)
    return `${x},${y}`
  })
  return `M${pts.join(' L')}`
}
const seriesVals = key => daily.value.map(d => Number(d[key] || 0))
const sparkA = computed(() => sparkPath(seriesVals('followers')))
const sparkB = computed(() => sparkPath(seriesVals('interactions')))
const sparkD = computed(() => sparkPath(seriesVals('reach')))

/* ---- honest deltas: null means "no history", never a made-up number ---- */
function deltaFor(key) {
  const cur = Number(trend.value.current?.[key] || 0)
  const prev = Number(trend.value.previous?.[key] || 0)
  if (prev > 0) {
    const pct = ((cur - prev) / prev) * 100
    return { text: `${pct >= 0 ? '+' : ''}${pct.toFixed(1)}%`, dir: pct >= 0 ? 'up' : 'down' }
  }
  if (cur > 0) return { text: 'new', dir: 'up' }
  return null
}
const deltaEngagement = computed(() => deltaFor('interactions'))
const deltaReach = computed(() => deltaFor('reach'))
const deltaPublished = computed(() => deltaFor('posts'))

/* ---- main growth chart ---- */
let growthChart = null
let stopTheme = null

function rangeDays() {
  return range.value === '7D' ? 7 : range.value === '30D' ? 30 : range.value === '90D' ? 90 : 365
}
function seriesFor() {
  return daily.value.slice(-rangeDays()).map(d => Number(d.followers || 0))
}
function labelsFor() {
  return daily.value.slice(-rangeDays()).map(d => {
    const dt = new Date(d.date + 'T12:00:00')
    return isNaN(dt) ? d.date : dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
  })
}

function drawGrowth() {
  const el = document.getElementById('growthChart')
  if (!el) return
  growthChart?.destroy()
  const faint = cssVar('--fg-faint')
  const grid = cssVar('--border-soft')
  const tipBg = '#0b1330'
  Chart.defaults.color = faint
  const data = seriesFor()
  const labels = labelsFor()
  const ctx = el.getContext('2d')
  const grad = ctx.createLinearGradient(0, 0, 0, 200)
  grad.addColorStop(0, 'rgba(59,130,246,.35)')
  grad.addColorStop(1, 'rgba(59,130,246,0)')
  growthChart = new Chart(el, {
    type: 'line',
    data: { labels, datasets: [{ data, borderColor: '#3b82f6', backgroundColor: grad, fill: true, tension: .42, pointRadius: 0, pointHoverRadius: 4, pointBackgroundColor: '#3b82f6', borderWidth: 2 }] },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: tipBg, titleColor: '#fff', bodyColor: '#c9d5f2',
          padding: 9, displayColors: false, cornerRadius: 8,
          callbacks: { label: c => ` ${Number(c.raw).toLocaleString()} followers` }
        }
      },
      scales: {
        x: { grid: { display: false }, border: { display: false }, ticks: { padding: 4, maxTicksLimit: 7 } },
        y: {
          grid: { color: grid }, border: { display: false },
          ticks: { padding: 6, maxTicksLimit: 5, callback: v => v >= 1000 ? `${Math.round(v / 1000)}K` : v }
        }
      }
    }
  })
}

function setRange(r) {
  range.value = r
  requestAnimationFrame(drawGrowth)
}

onMounted(async () => {
  try {
    const { data } = await client.get('/public/api/dashboard.php')
    if (data.success) {
      summary.value = data.summary || summary.value
      accounts.value = data.accounts || []
      recent.value = data.recentPosts || []
      byPlatform.value = data.byPlatform || []
      notifications.value = data.notifications || []
      unread.value = data.unread || 0
      if (data.trend) trend.value = data.trend
      daily.value = data.daily || []
    }
  } finally {
    loading.value = false
  }
  requestAnimationFrame(drawGrowth)
  stopTheme = onChange(() => requestAnimationFrame(drawGrowth))
})
onBeforeUnmount(() => { stopTheme?.(); growthChart?.destroy() })

function goCreate() { router.push('/create') }
</script>

<template>
  <div v-if="loading" class="stack g2">
    <div class="stat-grid">
      <div v-for="i in 4" :key="i" class="card skeleton" style="height:150px" />
    </div>
    <div class="dash-layout">
      <div class="card skeleton" style="height:340px" />
      <div class="card skeleton" style="height:340px" />
    </div>
  </div>

  <div v-else>
    <!-- greeting -->
    <div class="page-head" style="margin-bottom:18px">
      <div>
        <h1>{{ greeting }}, <span class="hl">{{ firstName }}</span> 👋</h1>
        <p>Here's what's happening across your social channels.</p>
      </div>
      <div class="page-actions">
        <span class="date-pill">{{ dateLabel }}</span>
        <button class="icon-btn theme-toggle" :title="isDark ? 'Light mode' : 'Dark mode'" @click="toggle">
          <Sun v-if="isDark" style="width:16px;height:16px" /><Moon v-else style="width:16px;height:16px" />
        </button>
        <button class="btn primary" @click="goCreate"><Plus />New Post</button>
      </div>
    </div>

    <div class="dash-layout">
      <!-- ============ MAIN ============ -->
      <div class="dash-main">
        <!-- stat cards -->
        <div class="stat-grid">
          <div class="stat-card">
            <div class="stat-top">
              <span class="stat-ico blue"><Users /></span>
              <span class="stat-label">Total Audience</span>
            </div>
            <div class="stat-value">{{ audience.toLocaleString() }}</div>
            <div class="stat-meta">
              <div>
                <div class="vs">across {{ accounts.length }} account{{ accounts.length === 1 ? '' : 's' }}</div>
              </div>
              <div v-if="sparkA" class="spark">
                <svg viewBox="0 0 92 36" preserveAspectRatio="none">
                  <path :d="sparkA" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" />
                  <path :d="`${sparkA} L92,36 L0,36 Z`" fill="rgba(59,130,246,.15)" stroke="none" />
                </svg>
              </div>
            </div>
          </div>

          <div class="stat-card">
            <div class="stat-top">
              <span class="stat-ico pink"><Heart /></span>
              <span class="stat-label">Engagement</span>
            </div>
            <div class="stat-value">{{ Number(totalInteractions).toLocaleString() }}</div>
            <div class="stat-meta">
              <div>
                <span v-if="deltaEngagement" class="delta" :class="deltaEngagement.dir"><ArrowUp v-if="deltaEngagement.dir==='up'" /><ArrowDown v-else />{{ deltaEngagement.text }}</span>
                <div class="vs">{{ deltaEngagement ? 'vs. prior 7 days' : 'no prior data' }}</div>
              </div>
              <div v-if="sparkB" class="spark">
                <svg viewBox="0 0 92 36" preserveAspectRatio="none">
                  <path :d="sparkB" fill="none" stroke="#a855f7" stroke-width="2" stroke-linecap="round" />
                  <path :d="`${sparkB} L92,36 L0,36 Z`" fill="rgba(168,85,247,.15)" stroke="none" />
                </svg>
              </div>
            </div>
          </div>

          <div class="stat-card">
            <div class="stat-top">
              <span class="stat-ico cyan"><Send /></span>
              <span class="stat-label">Published Posts</span>
            </div>
            <div class="stat-value">{{ published }}</div>
            <div class="stat-meta">
              <div>
                <span v-if="deltaPublished" class="delta" :class="deltaPublished.dir"><ArrowUp v-if="deltaPublished.dir==='up'" /><ArrowDown v-else />{{ deltaPublished.text }}</span>
                <div class="vs">{{ deltaPublished ? 'vs. prior 7 days' : 'no prior data' }}</div>
              </div>
            </div>
          </div>

          <div class="stat-card">
            <div class="stat-top">
              <span class="stat-ico violet"><Eye /></span>
              <span class="stat-label">Avg. Reach</span>
            </div>
            <div class="stat-value">{{ Number(avgRate).toFixed(2) }}%</div>
            <div class="stat-meta">
              <div>
                <span v-if="deltaReach" class="delta" :class="deltaReach.dir"><ArrowUp v-if="deltaReach.dir==='up'" /><ArrowDown v-else />{{ deltaReach.text }}</span>
                <div class="vs">{{ deltaReach ? 'reach vs. prior 7 days' : 'no prior data' }}</div>
              </div>
              <div v-if="sparkD" class="spark">
                <svg viewBox="0 0 92 36" preserveAspectRatio="none">
                  <path :d="sparkD" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" />
                  <path :d="`${sparkD} L92,36 L0,36 Z`" fill="rgba(245,158,11,.14)" stroke="none" />
                </svg>
              </div>
            </div>
          </div>
        </div>

        <!-- growth + channels -->
        <div class="grid c-2-1" style="grid-template-columns:minmax(0,1.9fr) minmax(0,1fr)">
          <div class="panel">
            <div class="panel-head">
              <div>
                <h3>Audience Growth</h3>
                <div class="sub">Total followers across all channels</div>
              </div>
              <div class="range-tabs">
                <button v-for="r in ['7D','30D','90D','1Y']" :key="r" :class="{ on: range === r }" @click="setRange(r)">{{ r }}</button>
              </div>
            </div>
            <div class="panel-body">
              <div style="height:220px"><canvas id="growthChart" /></div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-head">
              <h3>Channels</h3>
              <router-link to="/accounts" class="link">Manage ›</router-link>
            </div>
            <div class="panel-body">
              <div v-if="!channels.length" class="human-note" style="padding:8px 0 4px">No channel data yet — connect an account or publish a post.</div>
              <div v-for="c in channels" :key="c.name" class="channel-row">
                <div class="channel-top">
                  <span class="plat-ico" :class="c.cls">
                    <Instagram v-if="c.cls==='ig'" style="width:17px;height:17px" />
                    <Music2 v-else-if="c.cls==='tt'" style="width:17px;height:17px" />
                    <Linkedin v-else style="width:17px;height:17px" />
                  </span>
                  <div class="grow">
                    <div class="row-between">
                      <span class="channel-name">{{ c.name }}</span>
                      <span class="delta up">↑ {{ c.growth }}</span>
                    </div>
                    <div class="channel-sub">{{ Number(c.followers).toLocaleString() }} followers</div>
                  </div>
                </div>
                <div class="bar" :class="c.bar"><i :style="{ width: Math.max(8, Math.round(c.followers / maxFollowers * 100)) + '%' }" /></div>
              </div>
            </div>
          </div>
        </div>

        <!-- recent posts -->
        <div class="panel">
          <div class="panel-head" style="padding-bottom:10px">
            <div class="row g2">
              <h3>Recent Posts</h3>
              <div class="post-tabs">
                <button v-for="t in ['All','Instagram','TikTok','LinkedIn']" :key="t" :class="{ on: postFilter === t }" @click="postFilter = t">{{ t }}</button>
              </div>
            </div>
            <router-link to="/posts" class="link">View all</router-link>
          </div>
          <div class="feed">
            <div v-for="p in filteredPosts.slice(0, 5)" :key="p.id" class="feed-item">
              <div class="feed-thumb">
                <template v-if="platOf(p)==='TikTok'">♪</template>
                <template v-else-if="platOf(p)==='LinkedIn'">◈</template>
                <template v-else>{{ (platOf(p) || '?')[0] }}</template>
              </div>
              <div class="feed-main">
                <div class="feed-meta">
                  <Instagram v-if="platOf(p)==='Instagram'" style="width:14px;height:14px;color:#fb7185" />
                  <Music2 v-else-if="platOf(p)==='TikTok'" style="width:14px;height:14px;color:#fe2c55" />
                  <Linkedin v-else style="width:14px;height:14px;color:#38b6ff" />
                  {{ platOf(p) }}
                </div>
                <div class="feed-text">{{ plainText(p.content) }}</div>
                <div v-if="hashtags(p.content)" class="feed-tags">{{ hashtags(p.content) }}</div>
                <div class="feed-date">{{ fmtDate(p.created_at) }}</div>
                <div v-if="eng(p, 'likes') !== null" class="feed-stats">
                  <span>♡ {{ eng(p, 'likes') }}</span>
                  <span><MessageSquare /> {{ eng(p, 'comments') ?? '—' }}</span>
                  <span><Share2 /> {{ eng(p, 'shares') ?? '—' }}</span>
                </div>
              </div>
              <div class="feed-side">
                <span class="dots">•••</span>
                <div class="row g1">
                  <button class="boost-btn" @click="goCreate">Boost</button>
                  <span class="dots">•••</span>
                </div>
              </div>
            </div>
            <div v-if="!filteredPosts.length" class="empty" style="margin:16px;border:0">No posts for this filter.</div>
          </div>
        </div>
      </div>

      <!-- ============ SIDE RAIL ============ -->
      <div class="dash-side">
        <div class="panel">
          <div class="panel-head"><h3>Quick Actions</h3></div>
          <div class="qa-list">
            <router-link to="/create" class="qa-btn"><Pencil />Create a new post<ChevronRight class="chev" /></router-link>
            <router-link to="/create" class="qa-btn"><CalendarDays />Schedule post<ChevronRight class="chev" /></router-link>
            <router-link to="/calendar" class="qa-btn"><CalendarDays />View calendar<ChevronRight class="chev" /></router-link>
            <router-link to="/accounts" class="qa-btn"><Layers />Manage accounts<ChevronRight class="chev" /></router-link>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h3>Connected Accounts</h3>
            <span class="faint" style="font-size:11.5px">{{ connectedCount }} / {{ connectedCount }} connected</span>
          </div>
          <div class="panel-body" style="padding-top:4px">
            <div v-for="a in (accounts.length ? accounts.slice(0,4) : channels)" :key="a.id || a.name" class="acct-row">
              <span class="plat-ico" :class="a.cls || platClsOf(a.platform_name||a.name)">
                <Instagram v-if="(a.cls||platClsOf(a.platform_name||a.name||''))==='ig'" style="width:16px;height:16px" />
                <Music2 v-else-if="(a.cls||platClsOf(a.platform_name||a.name||''))==='tt'" style="width:16px;height:16px" />
                <Linkedin v-else style="width:16px;height:16px" />
              </span>
              <div>
                <div class="channel-name" style="font-size:13px">{{ a.platform_name || a.name }}</div>
                <div class="acct-handle">{{ a.username || '@1techlink' }}</div>
              </div>
              <span class="status-txt"><span class="status-dot" />Active</span>
            </div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h3>Recent Activity</h3>
            <router-link to="/logs" class="link">View all</router-link>
          </div>
          <div class="panel-body" style="padding-top:4px">
            <div v-if="!recent.length && !notifications.length" class="human-note" style="padding:8px 0">Nothing yet — publish or schedule a post to get this moving.</div>
            <div v-for="p in recent.slice(0, 3)" :key="'r'+p.id" class="activity-row">
              <span class="activity-ico"><Send style="width:15px;height:15px" /></span>
              <div><b class="truncate" style="max-width:220px">{{ (p.content || '').slice(0, 60) }}</b><span>{{ timeAgo(p.created_at) }} · {{ p.status }}</span></div>
            </div>
            <div v-for="n in notifications.slice(0, 2)" :key="'n'+n.id" class="activity-row">
              <span class="activity-ico"><Bell style="width:15px;height:15px" /></span>
              <div><b>{{ n.title }}</b><span>{{ timeAgo(n.created_at) }}</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
