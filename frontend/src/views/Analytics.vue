<script setup>
import { ref, onMounted, computed, onBeforeUnmount } from 'vue'
import client from '../api/client'
import { useTheme } from '../composables/useTheme'
import { Chart, registerables } from 'chart.js'
import { TrendingUp, Eye, Users, Heart, ArrowUp, ArrowDown, Instagram, Linkedin, Music2 } from 'lucide-vue-next'

Chart.register(...registerables)
Chart.defaults.font.family = "'Inter',system-ui,sans-serif"
Chart.defaults.font.size = 10.5

const { cssVar, onChange } = useTheme()

const summary = ref({})
const byPlatform = ref([])
const timeline = ref([])
const trend = ref({ current: { interactions: 0, reach: 0, posts: 0 }, previous: { interactions: 0, reach: 0, posts: 0 } })
const daily = ref([])
const loading = ref(true)

const avgRate = computed(() =>
  byPlatform.value.length
    ? (byPlatform.value.reduce((a, p) => a + Number(p.eng_rate || 0), 0) / byPlatform.value.length).toFixed(2)
    : '0.00'
)
const totalReach = computed(() => byPlatform.value.reduce((a, p) => a + Number(p.reach || 0), 0))
const totalFollowers = computed(() => Number(summary.value.followers) || byPlatform.value.reduce((a, p) => a + Number(p.followers || 0), 0) || 0)
const leader = computed(() => [...byPlatform.value].sort((a, b) => Number(b.reach) - Number(a.reach))[0])
const maxReach = computed(() => Math.max(1, ...byPlatform.value.map(p => Number(p.reach))))

let c1 = null
let c2 = null
let stopTheme = null

function spark(values, color) {
  if (!values.length) return { line: '', color }
  const min = Math.min(...values), span = Math.max(...values) - min || 1
  const pts = values.map((v, i) => `${(i * 92 / Math.max(1, values.length - 1)).toFixed(1)},${(32 - ((v - min) / span) * 26).toFixed(1)}`).join(' L')
  return { line: `M${pts}`, color }
}
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
const deltaReach = computed(() => deltaFor('reach'))
const deltaEngagement = computed(() => deltaFor('interactions'))
const sparks = computed(() => {
  const col = k => daily.value.map(d => Number(d[k] || 0))
  const rate = daily.value.map(d => Number(d.reach) > 0 ? (Number(d.interactions) / Number(d.reach)) * 100 : 0)
  return [
    spark(col('followers'), '#3b82f6'),
    spark(col('reach'), '#a855f7'),
    spark(col('interactions'), '#22d3ee'),
    spark(rate, '#f59e0b'),
  ]
})

onMounted(async () => {
  try {
    const { data } = await client.get('/public/api/analytics.php')
    summary.value = data.summary || {}
    byPlatform.value = data.byPlatform || []
    timeline.value = data.timeline || []
    if (data.trend) trend.value = data.trend
    daily.value = data.daily || []
  } finally {
    loading.value = false
  }
  requestAnimationFrame(draw)
  stopTheme = onChange(() => requestAnimationFrame(draw))
})

onBeforeUnmount(() => {
  stopTheme?.()
  c1?.destroy()
  c2?.destroy()
})

function draw() {
  const faint = cssVar('--fg-faint')
  const grid = cssVar('--border-soft')
  const tipBg = '#0b1330'
  Chart.defaults.color = faint

  const tl = document.getElementById('timelineChart')
  if (tl) {
    c1?.destroy()
    const labels = timeline.value.map(t => t.date)
    const likes = timeline.value.map(t => Number(t.likes))
    const reach = timeline.value.map(t => Number(t.reach))
    const ctx = tl.getContext('2d')
    const grad = ctx.createLinearGradient(0, 0, 0, 210)
    grad.addColorStop(0, 'rgba(59,130,246,.3)')
    grad.addColorStop(1, 'rgba(59,130,246,0)')
    c1 = new Chart(tl, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'Likes', data: likes, borderColor: '#3b82f6', backgroundColor: grad, fill: true, tension: .4, pointRadius: 0, pointHoverRadius: 4, borderWidth: 2 },
          { label: 'Reach', data: reach, borderColor: '#22d3ee', backgroundColor: 'transparent', fill: false, tension: .4, pointRadius: 0, borderWidth: 1.5, borderDash: [4, 4], yAxisID: 'y1' }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', font: { size: 11 }, color: faint, padding: 14 } },
          tooltip: { backgroundColor: tipBg, titleColor: '#fff', bodyColor: '#c9d5f2', displayColors: false, cornerRadius: 8, padding: 9 }
        },
        scales: {
          x: { grid: { display: false }, border: { display: false }, ticks: { padding: 4, maxTicksLimit: 7 } },
          y: { grid: { color: grid }, border: { display: false }, ticks: { padding: 4, maxTicksLimit: 5 } },
          y1: { position: 'right', grid: { display: false }, border: { display: false }, ticks: { padding: 4, maxTicksLimit: 4 } }
        }
      }
    })
  }

  const pl = document.getElementById('platformChart')
  if (pl) {
    c2?.destroy()
    c2 = new Chart(pl, {
      type: 'doughnut',
      data: {
        labels: byPlatform.value.map(p => p.name),
        datasets: [{
          data: byPlatform.value.map(p => Number(p.reach)),
          backgroundColor: byPlatform.value.map(p => p.color || '#3b82f6'),
          borderWidth: 3, borderColor: cssVar('--surface'), hoverOffset: 5
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '64%',
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', font: { size: 11 }, color: faint, padding: 14 } },
          tooltip: { backgroundColor: tipBg, titleColor: '#fff', bodyColor: '#c9d5f2', displayColors: false, cornerRadius: 8, padding: 9 }
        }
      }
    })
  }
}

function platCls(name = '') {
  if (/insta/i.test(name)) return 'ig'
  if (/tikt/i.test(name)) return 'tt'
  return 'li'
}
</script>

<template>
  <div>
    <div class="page-head" style="margin-bottom:18px">
      <div>
        <div class="eyebrow">Measure</div>
        <h1>Analytics</h1>
        <p>Last 7 days across every channel{{ leader ? ` — ${leader.name} is pulling the most weight right now.` : '.' }}</p>
      </div>
      <span v-if="!byPlatform.length" class="badge muted">No data yet — connect an account or publish</span>
    </div>

    <div v-if="loading" class="stat-grid">
      <div v-for="i in 4" :key="i" class="card skeleton" style="height:150px" />
    </div>

    <template v-else>
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-top"><span class="stat-ico blue"><Users /></span><span class="stat-label">Followers</span></div>
          <div class="stat-value">{{ totalFollowers.toLocaleString() }}</div>
          <div class="stat-meta">
            <div><div class="vs">across connected accounts</div></div>
            <div v-if="sparks[0].line" class="spark"><svg viewBox="0 0 92 36" preserveAspectRatio="none"><path :d="sparks[0].line" fill="none" :stroke="sparks[0].color" stroke-width="2" stroke-linecap="round" /></svg></div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-top"><span class="stat-ico pink"><Eye /></span><span class="stat-label">Reach · 7d</span></div>
          <div class="stat-value">{{ totalReach.toLocaleString() }}</div>
          <div class="stat-meta">
            <div><span v-if="deltaReach" class="delta" :class="deltaReach.dir"><ArrowUp v-if="deltaReach.dir==='up'" /><ArrowDown v-else />{{ deltaReach.text }}</span><div class="vs">{{ deltaReach ? 'vs. prior 7 days' : 'no prior data' }} · {{ Number(summary.impressions || 0).toLocaleString() }} impressions</div></div>
            <div v-if="sparks[1].line" class="spark"><svg viewBox="0 0 92 36" preserveAspectRatio="none"><path :d="sparks[1].line" fill="none" :stroke="sparks[1].color" stroke-width="2" stroke-linecap="round" /></svg></div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-top"><span class="stat-ico cyan"><Heart /></span><span class="stat-label">Engagement</span></div>
          <div class="stat-value">{{ Number(summary.engagement || 0).toLocaleString() }}</div>
          <div class="stat-meta">
            <div><span v-if="deltaEngagement" class="delta" :class="deltaEngagement.dir"><ArrowUp v-if="deltaEngagement.dir==='up'" /><ArrowDown v-else />{{ deltaEngagement.text }}</span><div class="vs">{{ deltaEngagement ? 'vs. prior 7 days' : 'no prior data' }} · likes + replies + shares</div></div>
            <div v-if="sparks[2].line" class="spark"><svg viewBox="0 0 92 36" preserveAspectRatio="none"><path :d="sparks[2].line" fill="none" :stroke="sparks[2].color" stroke-width="2" stroke-linecap="round" /></svg></div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-top"><span class="stat-ico violet"><TrendingUp /></span><span class="stat-label">Avg. rate</span></div>
          <div class="stat-value">{{ avgRate }}%</div>
          <div class="stat-meta">
            <div><div class="vs">unweighted mean</div></div>
            <div v-if="sparks[3].line" class="spark"><svg viewBox="0 0 92 36" preserveAspectRatio="none"><path :d="sparks[3].line" fill="none" :stroke="sparks[3].color" stroke-width="2" stroke-linecap="round" /></svg></div>
          </div>
        </div>
      </div>

      <div class="grid c-2-1" style="grid-template-columns:minmax(0,1.9fr) minmax(0,1fr);margin-top:18px">
        <div class="panel">
          <div class="panel-head">
            <div><h3>Likes vs. reach</h3><div class="sub">Solid is love, dashed is eyeballs</div></div>
            <span class="sub">7 days</span>
          </div>
          <div class="panel-body"><div style="height:230px"><canvas id="timelineChart" /></div></div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Where reach comes from</h3><span class="sub">{{ totalReach.toLocaleString() }}</span></div>
          <div class="panel-body"><div style="height:230px"><canvas id="platformChart" /></div></div>
        </div>
      </div>

      <div class="panel" style="margin-top:18px">
        <div class="panel-head"><h3>Channel by channel</h3><span class="sub">Honest numbers, no vanity rounding</span></div>
        <div class="panel-body" style="padding-top:6px">
          <div v-for="p in byPlatform" :key="p.slug" class="channel-row">
            <div class="channel-top">
              <span class="plat-ico" :class="platCls(p.name)">
                <Instagram v-if="platCls(p.name)==='ig'" style="width:16px;height:16px" />
                <Music2 v-else-if="platCls(p.name)==='tt'" style="width:16px;height:16px" />
                <Linkedin v-else style="width:16px;height:16px" />
              </span>
              <div class="grow">
                <div class="row-between">
                  <span class="channel-name">{{ p.name }}</span>
                  <span class="tnum" style="font-size:12.5px;font-weight:700">{{ Number(p.reach).toLocaleString() }} reach · {{ Number(p.eng_rate || 0).toFixed(2) }}%</span>
                </div>
                <div class="channel-sub">{{ p.likes }} likes · {{ p.comments }} replies · {{ p.shares }} shares</div>
              </div>
            </div>
            <div class="bar blue"><i :style="{ width: Math.max(6, Math.round(Number(p.reach) / maxReach * 100)) + '%' }" /></div>
          </div>
          <div v-if="!byPlatform.length" class="human-note" style="padding:12px 0">No channel data yet — connect one under Accounts first.</div>
        </div>
      </div>
    </template>
  </div>
</template>
