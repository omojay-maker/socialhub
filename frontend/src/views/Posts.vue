<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import client, { getCsrf } from '../api/client'
import { Search, Trash2, Eye, RotateCcw, X, FileText, Instagram, Linkedin, Twitter, Music2, Plus } from 'lucide-vue-next'

const route = useRoute()
const posts = ref([])
const q = ref(route.query.q || '')
const status = ref(route.query.status || '')
const platform = ref('')
const detail = ref(null)
const loading = ref(true)
const busy = ref(false)

async function load() {
  loading.value = true
  const params = new URLSearchParams({ action: 'list' })
  if (status.value) params.set('status', status.value)
  if (q.value) params.set('q', q.value)
  if (platform.value) params.set('platform', platform.value)
  const { data } = await client.get('/public/api/posts.php?' + params.toString())
  posts.value = data.posts || []
  loading.value = false
}

async function view(id) {
  const { data } = await client.get('/public/api/post_detail.php?id=' + id)
  if (data.success) detail.value = data.post
}

function closeDetail() {
  detail.value = null
}

async function del(id) {
  if (!confirm('Delete this post? Publications are not removed from platforms.')) return
  await client.post('/public/api/posts.php?action=delete&id=' + id, { csrf_token: getCsrf() })
  if (detail.value?.id === id) detail.value = null
  await load()
}

async function retry(postId, slug) {
  busy.value = true
  try {
    const { data } = await client.post('/public/api/publish.php', {
      post_id: postId, platform: slug, csrf_token: getCsrf()
    })
    if (!data.success) alert(data.message || 'Retry failed')
    await view(postId)
    await load()
  } finally {
    busy.value = false
  }
}

function platIcon(s = '') {
  s = s.toLowerCase()
  if (s.includes('insta')) return Instagram
  if (s.includes('link')) return Linkedin
  if (s.includes('tikt')) return Music2
  return Twitter
}
function platCls(s = '') {
  s = s.toLowerCase()
  if (s.includes('insta')) return 'ig'
  if (s.includes('link')) return 'li'
  if (s.includes('tikt')) return 'tt'
  return 'x'
}
function shortDate(s) {
  if (!s) return '—'
  const d = new Date(s.replace(' ', 'T'))
  if (isNaN(d)) return s.slice(0, 16)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' · ' +
    d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}
function statusNote(p) {
  if (p.status === 'scheduled') return `Goes out ${shortDate(p.scheduled_at)}`
  if (p.status === 'published') return `Went out ${shortDate(p.published_at || p.created_at)}`
  if (p.status === 'partial') return 'Some channels still need a retry'
  if (p.status === 'failed') return 'Didn\u2019t go out — retry below'
  return `Last touched ${shortDate(p.created_at)}`
}

onMounted(load)

let first = true
watch(() => route.query.q, v => {
  if (v === undefined) return
  q.value = v || ''
  if (!first) load()
})
watch(() => route.query.status, v => {
  if (v === undefined) return
  status.value = v || ''
  if (!first) load()
})
watch([q, status], () => {
  if (first) { first = false; return }
  load()
})
</script>

<template>
  <div>
    <div class="page-head" style="margin-bottom:18px">
      <div>
        <div class="eyebrow">Library</div>
        <h1>Posts</h1>
        <p>Everything written, scheduled and shipped — drafts included. Pick one to see exactly what each channel did.</p>
      </div>
      <div class="page-actions">
        <router-link to="/create" class="btn primary"><Plus />New Post</router-link>
      </div>
    </div>

    <div class="split">
      <div>
        <div class="panel" style="margin-bottom:16px">
          <div class="panel-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <div class="topbar-search grow" style="min-width:220px;width:auto">
              <Search />
              <input v-model="q" type="search" placeholder="Try “launch”, “#INK”, or a teammate’s name…" @keyup.enter="load" />
            </div>
            <select v-model="status" style="width:142px" @change="load">
              <option value="">All statuses</option>
              <option value="draft">Draft</option>
              <option value="scheduled">Scheduled</option>
              <option value="published">Published</option>
              <option value="partial">Needs retry</option>
              <option value="failed">Failed</option>
            </select>
            <select v-model="platform" style="width:142px" @change="load">
              <option value="">All channels</option>
              <option value="instagram">Instagram</option>
              <option value="tiktok">TikTok</option>
              <option value="linkedin">LinkedIn</option>
              <option value="twitter">X</option>
            </select>
            <button
              v-if="q || status || platform"
              class="btn ghost sm"
              @click="q = ''; status = ''; platform = ''; load()"
            >
              <X />Clear
            </button>
            <span class="faint" style="font-size:12px;margin-left:auto">{{ posts.length }} shown</span>
          </div>
        </div>

        <div v-if="loading" class="stack g2">
          <div v-for="i in 3" :key="i" class="card skeleton" style="height:128px" />
        </div>

        <div v-else-if="!posts.length" class="panel">
          <div class="panel-body" style="text-align:center;padding:44px 24px">
            <FileText style="width:26px;height:26px;margin:0 auto 10px;color:var(--fg-faint)" />
            <div style="font-weight:700">Nothing here matches</div>
            <p class="human-note" style="margin-top:4px">Loosen a filter, or <router-link to="/create" class="link">write something new</router-link> — drafts land here too.</p>
          </div>
        </div>

        <div v-else class="panel feed">
          <div
            v-for="p in posts"
            :key="p.id"
            class="feed-item"
            style="cursor:pointer"
            :style="detail?.id === p.id ? { background: 'rgba(29,111,242,.06)' } : {}"
            @click="view(p.id)"
          >
            <div class="feed-thumb" style="width:72px;height:72px;flex-basis:72px;font-size:15px">
              {{ (p.content || '?').trim()[0]?.toUpperCase() }}
            </div>
            <div class="feed-main">
              <div class="feed-meta">
                <span class="plat-ico" :class="platCls(p.platforms)" style="width:22px;height:22px;border-radius:7px">
                  <component :is="platIcon(p.platforms)" style="width:12px;height:12px" />
                </span>
                {{ p.platforms || 'No channel yet' }}
                <span class="badge" :class="p.status" style="margin-left:2px">{{ p.status === 'partial' ? 'needs retry' : p.status }}</span>
              </div>
              <div class="feed-text truncate" style="white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ p.content }}</div>
              <div class="feed-date">{{ statusNote(p) }} · by {{ p.author_name }}</div>
            </div>
            <div class="feed-side">
              <span class="dots">•••</span>
              <div class="row g1">
                <button class="btn sm" @click.stop="view(p.id)"><Eye />Open</button>
                <button class="btn sm danger" @click.stop="del(p.id)"><Trash2 /></button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- detail rail -->
      <div class="panel" style="position:sticky;top:0">
        <div class="panel-head">
          <h3>{{ detail ? `Post #${detail.id}` : 'Details' }}</h3>
          <button v-if="detail" class="icon-btn" @click="closeDetail"><X /></button>
        </div>
        <div v-if="!detail" class="panel-body">
          <p class="human-note">Select a post on the left. You\u2019ll see the exact wording plus <b>what each channel did</b> — and a retry button wherever one failed.</p>
          <div class="kv" style="margin-top:12px"><span>Tip</span><span>Scheduled posts go out via cron</span></div>
          <div class="kv"><span>Deletes</span><span>Only remove the local copy</span></div>
        </div>
        <div v-else class="panel-body">
          <span class="badge" :class="detail.status">{{ detail.status }}</span>
          <p style="font-size:13.5px;line-height:1.65;margin-top:10px;white-space:pre-wrap">{{ detail.content }}</p>
          <div v-if="detail.hashtags" style="font-size:13px;color:#3b82f6;margin-top:6px">{{ detail.hashtags }}</div>
          <div class="kv" style="margin-top:12px"><span>Author</span><span>{{ detail.author_name }}</span></div>
          <div class="kv"><span>Created</span><span>{{ (detail.created_at || '').slice(0, 16) }}</span></div>
          <div v-if="detail.published_at" class="kv"><span>Published</span><span>{{ (detail.published_at || '').slice(0, 16) }}</span></div>
          <div class="eyebrow" style="margin:16px 0 8px">Per-channel result</div>
          <div v-for="pub in detail.publications" :key="pub.id" class="acct-row">
            <span class="plat-ico" :class="platCls(pub.platform_name)" style="width:28px;height:28px">
              <component :is="platIcon(pub.platform_name)" style="width:14px;height:14px" />
            </span>
            <div class="grow">
              <div style="font-size:13px;font-weight:600">{{ pub.platform_name }}</div>
              <div class="faint" style="font-size:11.5px">{{ pub.error_message || 'Delivered cleanly' }}</div>
            </div>
            <span class="badge" :class="pub.status">{{ pub.status }}</span>
          </div>
          <div v-if="!detail.publications?.length" class="human-note" style="margin-top:8px">No channel attempts recorded yet — it\u2019s probably still a draft.</div>
          <div v-for="pub in (detail.publications || []).filter(x => x.status === 'failed')" :key="'r'+pub.id" class="row mt2">
            <button class="btn sm primary block" :disabled="busy" @click="retry(detail.id, pub.slug)">
              <RotateCcw />Retry {{ pub.platform_name }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
