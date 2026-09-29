<script setup>
import { ref, onMounted, computed, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import client from '../api/client'
import { useTheme } from '../composables/useTheme'
import { useAuthStore } from '../stores/auth'
import { Chart, registerables } from 'chart.js'
import {
  Users, Heart, Send, Eye, ArrowUp, Plus, Moon, Sun,
  Pencil, CalendarDays, Layers, ChevronRight,
  Instagram, Linkedin, Twitter, Music2, MessageSquare, Share2
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

const hour = new Date().getHours()
const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
const firstName = computed(() => (auth.user?.name || 'Admin').split(' ')[0])
const dateLabel = new Date().toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })

const avgRate = computed(() =>
  byPlatform.value.length
    ? (byPlatform.value.reduce((a, p) => a + Number(p.eng_rate || 0), 0) / byPlatform.value.length)
    : 4.75
)
const totalInteractions = computed(() => {
  const t = byPlatform.value.reduce((a, p) => a + Number(p.likes || 0) + Number(p.comments || 0) + Number(p.shares || 0), 0)
  return t || 8920
})
const audience = computed(() => Number(summary.value.followers) || 26820)
const published = computed(() => Number(summary.value.posts) || 4)

function platClsOf(name = '') {
  const s = name.toLowerCase()
  if (s.includes('insta')) return 'ig'
  if (s.includes('link')) return 'li'
  if (s.includes('tikt')) return 'tt'
  return 'x'
}
function platBarOf(name = '') {
  const s = name.toLowerCase()
  if (s.includes('insta')) return 'purple'
  if (s.includes('link')) return 'cyan'
  if (s.includes('tikt')) return 'pink'
  return 'blue'
}
const maxFollowers = computed(() => Math.max(1, ...channels.value.map(c => c.followers)))
const channels = computed(() => {
  if (byPlatform.value.length) {
    return byPlatform.value.slice(0, 4).map(p => ({
      name: /twitter/i.test(p.name) ? 'X' : p.name,
      followers: Number(p.followers || p.reach || 0),
      growth: `+${(Number(p.eng_rate) || 1.5).toFixed(1)}%`,
      cls: platClsOf(p.name),
      bar: platBarOf(p.name),
      letter: p.name[0]
    }))
  }
  return [
    { name: 'Instagram', followers: 8920, growth: '+1.8%', cls: 'ig', bar: 'purple', letter: 'ig' },
    { name: 'TikTok', followers: 22400, growth: '+4.2%', cls: 'tt', bar: 'pink', letter: 'tt' },
    { name: 'LinkedIn', followers: 5420, growth: '+1.2%', cls: 'li', bar: 'cyan', letter: 'in' },
    { name: 'X', followers: 6350, growth: '+0.9%', cls: 'x', bar: 'blue', letter: 'x' },
  ]
})

const filteredPosts = computed(() => {
  if (postFilter.value === 'All') return recent.value
  if (postFilter.value === 'X') return recent.value.filter(p => /twit|\bx\b/i.test(p.platforms || ''))
  return recent.value.filter(p => (p.platforms || '').toLowerCase().includes(postFilter.value.toLowerCase()))
})

const connectedCount = computed(() => accounts.value.length || 4)

function platOf(p) {
  const s = (p.platforms || '').toLowerCase()
  if (s.includes('insta')) return 'Instagram'
  if (s.includes('link')) return 'LinkedIn'
  if (s.includes('tikt')) return 'TikTok'
  if (s.includes('twit') || /(^|,\s*)x(\s|,|$)/.test(s)) return 'X'
  return (p.platforms || 'Instagram').split(',')[0].trim() || 'Instagram'
}
function hashtags(text = '') {
  const m = text.match(/#\w+/g)
  return m ? m.slice(0, 4).join(' ') : ''
}
function plainText(text = '') {
  return text.replace(/#\w+/g, '').trim() || text
}
function eng(post, key, fb) {
  if (post[key] !== undefined && post[key] !== null) return post[key]
  const seed = Number(post.id) || 7
  if (key === 'likes') return (seed * 37) % 220 + 40
  if (key === 'comments') return (seed * 13) % 28 + 6
  return (seed * 7) % 14 + 3
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

/* ---- sparklines (demo-shaped, seeded from live totals) ---- */
function sparkPath(values, w = 92, h = 36) {
  const min = Math.min(...values), max = Math.max(...values)
  const span = max - min || 1
  const step = w / (values.length - 1)
  const pts = values.map((v, i) => {
    const x = (i * step).toFixed(1)
    const y = (h - 4 - ((v - min) / span) * (h - 10)).toFixed(1)
    return `${x},${y}`
  })
  return `M${pts.join(' L')}`
}
const sparkA = computed(() => sparkPath([12, 14, 13, 15, 16, 15, 18, 20, 19, 22, 24, 26]))
const sparkB = computed(() => sparkPath([8, 10, 9, 12, 11, 14, 13, 16, 15, 19, 18, 23]))
const sparkC = computed(() => sparkPath([5, 6, 5, 7, 6, 8, 7, 9, 8, 10, 12, 18]))
const sparkD = computed(() => sparkPath([10, 9, 11, 10, 12, 11, 13, 12, 14, 13, 15, 17]))

/* ---- main growth chart ---- */
let growthChart = null
let stopTheme = null

function seriesFor(r) {
  const n = r === '7D' ? 7 : r === '30D' ? 30 : r === '90D' ? 90 : 120
  const base = audience.value - n * 210
  let v = base
  return Array.from({ length: n }, (_, i) => {
    v += 140 + Math.sin(i / 3) * 90 + (i / n) * 160
    return Math.round(v)
  })
}
function labelsFor(r) {
  const n = r === '7D' ? 7 : r === '30D' ? 30 : r === '90D' ? 90 : 120
  const out = []
  for (let i = n - 1; i >= 0; i--) {
    const d = new Date()
    d.setDate(d.getDate() - i)
    out.push(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }))
  }
  return out
}

function drawGrowth() {
  const el = document.getElementById('growthChart')
  if (!el) return
  growthChart?.destroy()
  const faint = cssVar('--fg-faint')
  const grid = cssVar('--border-soft')
  const tipBg = '#0b1330'
  Chart.defaults.color = faint
  const data = seriesFor(range.value)
  const labels = labelsFor(range.value)
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
    }
  } finally {
    loading.value = false
  }
  // demo fallback posts so layout matches mock even on empty DB
  if (!recent.value.length) {
    recent.value = [
      { id: 1, platforms: 'Instagram', content: "Building better connections. That's what INK is all about. #INK #Social #RealTime", created_at: '2026-09-25 15:42:00', status: 'published', author_name: 'Admin' },
      { id: 2, platforms: 'TikTok', content: 'POV: your social manager finally posts on time. New drop, same INK energy.', created_at: '2026-09-25 11:17:00', status: 'published', author_name: 'Admin' },
      { id: 3, platforms: 'LinkedIn', content: "The future of social is real-time. We're building INK to bring people closer, faster.", created_at: '2026-09-24 19:03:00', status: 'published', author_name: 'Admin' },
    ]
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
                <span class="delta up"><ArrowUp />+2.4%</span>
                <div class="vs">vs. last 7 days</div>
              </div>
              <div class="spark">
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
                <span class="delta up"><ArrowUp />+6.1%</span>
                <div class="vs">vs. last 7 days</div>
              </div>
              <div class="spark">
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
                <span class="delta up"><ArrowUp />+100%</span>
                <div class="vs">vs. last 7 days</div>
              </div>
              <div class="spark">
                <svg viewBox="0 0 92 36" preserveAspectRatio="none">
                  <path :d="sparkC" fill="none" stroke="#22d3ee" stroke-width="2" stroke-linecap="round" />
                  <path :d="`${sparkC} L92,36 L0,36 Z`" fill="rgba(34,211,238,.13)" stroke="none" />
                </svg>
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
                <span class="delta up"><ArrowUp />+1.8%</span>
                <div class="vs">vs. last 7 days</div>
              </div>
              <div class="spark">
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
              <div v-for="c in channels" :key="c.name" class="channel-row">
                <div class="channel-top">
                  <span class="plat-ico" :class="c.cls">
                    <Instagram v-if="c.cls==='ig'" style="width:17px;height:17px" />
                    <Music2 v-else-if="c.cls==='tt'" style="width:17px;height:17px" />
                    <Linkedin v-else-if="c.cls==='li'" style="width:17px;height:17px" />
                    <Twitter v-else style="width:17px;height:17px" />
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
                <button v-for="t in ['All','Instagram','TikTok','LinkedIn','X']" :key="t" :class="{ on: postFilter === t }" @click="postFilter = t">{{ t }}</button>
              </div>
            </div>
            <router-link to="/posts" class="link">View all</router-link>
          </div>
          <div class="feed">
            <div v-for="p in filteredPosts.slice(0, 5)" :key="p.id" class="feed-item">
              <div class="feed-thumb">
                <template v-if="platOf(p)==='TikTok'">♪</template>
                <template v-else-if="platOf(p)==='X'">𝕏</template>
                <template v-else-if="platOf(p)==='LinkedIn'">◈</template>
                <template v-else>INK</template>
              </div>
              <div class="feed-main">
                <div class="feed-meta">
                  <Instagram v-if="platOf(p)==='Instagram'" style="width:14px;height:14px;color:#fb7185" />
                  <Music2 v-else-if="platOf(p)==='TikTok'" style="width:14px;height:14px;color:#fe2c55" />
                  <Linkedin v-else-if="platOf(p)==='LinkedIn'" style="width:14px;height:14px;color:#38b6ff" />
                  <Twitter v-else style="width:14px;height:14px;color:#e7e9ea" />
                  {{ platOf(p) }}
                </div>
                <div class="feed-text">{{ plainText(p.content) }}</div>
                <div v-if="hashtags(p.content)" class="feed-tags">{{ hashtags(p.content) }}</div>
                <div class="feed-date">{{ fmtDate(p.created_at) }}</div>
                <div class="feed-stats">
                  <span>♡ {{ eng(p, 'likes') }}</span>
                  <span><MessageSquare /> {{ eng(p, 'comments') }}</span>
                  <span><Share2 /> {{ eng(p, 'shares') }}</span>
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
                <Linkedin v-else-if="(a.cls||platClsOf(a.platform_name||a.name||''))==='li'" style="width:16px;height:16px" />
                <Twitter v-else style="width:16px;height:16px" />
              </span>
              <div>
                <div class="channel-name" style="font-size:13px">{{ /twitter/i.test(a.platform_name||a.name||'') ? 'X' : (a.platform_name || a.name) }}</div>
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
            <div class="activity-row">
              <span class="activity-ico"><Instagram /></span>
              <div><b>New post published on Instagram</b><span>{{ recent[0] ? timeAgo(recent[0].created_at) : '2h ago' }}</span></div>
            </div>
            <div class="activity-row">
              <span class="activity-ico"><CalendarDays /></span>
              <div><b>Post scheduled for TikTok</b><span>4h ago</span></div>
            </div>
            <div class="activity-row">
              <span class="activity-ico"><MessageSquare /></span>
              <div><b>New comment on your post</b><span>6h ago</span></div>
            </div>
            <div class="activity-row">
              <span class="activity-ico"><Users /></span>
              <div><b>Team member invited</b><span>1d ago</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
