<script setup>
import { ref, onMounted, computed } from 'vue'
import client, { BASE, getCsrf } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { RefreshCw, Unlink, ShieldCheck, Link2, Info, CheckCircle2, ExternalLink, Instagram, Linkedin, Twitter, Music2 } from 'lucide-vue-next'

const auth = useAuthStore()
const accounts = ref([])
const platforms = ref([])
const form = ref({ platform_id: '', account_name: '', username: '' })
const msg = ref('')
const err = ref('')
const busy = ref(false)
const syncing = ref(null)
const disconnecting = ref(null)
const connecting = ref(null)

const liveMode = ref(false)

const configured = computed(() => platforms.value.filter((p) => p.is_configured).length)
const setupNeeded = computed(() => platforms.value.filter((p) => !p.is_configured))
const totalFollowers = computed(() => accounts.value.reduce((a, x) => a + Number(x.followers || 0), 0))

async function load() {
  const [{ data: list }, { data: status }] = await Promise.all([
    client.get('/public/api/accounts.php?action=list'),
    client.get('/public/api/oauth.php?action=status'),
  ])
  accounts.value = list.accounts || []
  platforms.value = status.platforms || list.platforms || []
  liveMode.value = status.mode === 'live'
  if (status.flash) {
    if (status.flash.type === 'success') msg.value = status.flash.message
    else err.value = status.flash.message
  }
}

function connectReal(p) {
  err.value = ''
  msg.value = ''
  connecting.value = p.slug
  window.location.assign(
    `${BASE}/public/api/oauth.php?action=start&platform=${encodeURIComponent(p.slug)}`
  )
}

function platCls(slug = '') {
  slug = (slug || '').toLowerCase()
  if (slug.includes('insta')) return 'ig'
  if (slug.includes('link')) return 'li'
  if (slug.includes('tikt')) return 'tt'
  return 'x'
}
function platIcon(slug = '') {
  slug = (slug || '').toLowerCase()
  if (slug.includes('insta')) return Instagram
  if (slug.includes('link')) return Linkedin
  if (slug.includes('tikt')) return Music2
  return Twitter
}
function displayName(a) {
  const n = a.platform_name || a.name || ''
  return /twitter/i.test(n) ? 'X' : n
}

async function connect() {
  err.value = ''
  msg.value = ''
  if (!form.value.platform_id || !form.value.account_name || !form.value.username) {
    err.value = 'Fill in all three fields — platform, display name, handle.'
    return
  }
  busy.value = true
  try {
    const fd = new FormData()
    fd.append('csrf_token', getCsrf())
    fd.append('platform_id', form.value.platform_id)
    fd.append('account_name', form.value.account_name)
    fd.append('username', form.value.username)
    const { data } = await client.post('/public/api/accounts.php?action=create', fd)
    if (!data.success) err.value = data.message
    else {
      msg.value = data.message
      form.value = { platform_id: '', account_name: '', username: '' }
      await load()
    }
  } finally {
    busy.value = false
  }
}

async function del(id) {
  if (!confirm('Disconnect this account? Publishing to it stops immediately.')) return
  disconnecting.value = id
  err.value = ''
  try {
    const { data } = await client.post(`/public/api/oauth.php?action=disconnect&id=${id}`, { csrf_token: getCsrf() })
    if (data.success) {
      msg.value = data.message
      await load()
    } else err.value = data.message
  } finally {
    disconnecting.value = null
  }
}

async function sync(id) {
  syncing.value = id
  msg.value = ''
  try {
    const { data } = await client.post('/public/api/accounts.php?action=sync&id=' + id, { csrf_token: getCsrf() })
    msg.value = data.message || 'Fresh numbers pulled in.'
    await load()
  } finally {
    syncing.value = null
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-head" style="margin-bottom:18px">
      <div>
        <div class="eyebrow">Channels</div>
        <h1>Accounts</h1>
        <p>{{ accounts.length ? `${accounts.length} connected · ${totalFollowers.toLocaleString()} followers between them.` : 'No channels connected yet — start with one below.' }} Authorization always happens on the platform\u2019s site; we never see passwords.</p>
      </div>
      <span class="badge" :class="liveMode ? 'published' : 'muted'">{{ liveMode ? 'Live' : 'Demo mode' }}</span>
    </div>

    <div v-if="err" class="alert error" style="margin-bottom:12px"><Info />{{ err }}</div>
    <div v-if="msg" class="alert success" style="margin-bottom:12px"><CheckCircle2 />{{ msg }}</div>

    <div v-if="!liveMode" class="panel" style="margin-bottom:16px;border-style:dashed">
      <div class="panel-body row g2" style="align-items:flex-start">
        <ShieldCheck style="width:17px;height:17px;color:var(--warn);flex-shrink:0;margin-top:2px" />
        <p class="human-note">You\u2019re in <b>demo mode</b> — sends are simulated. To go live, set <code>SOCIAL_MODE=live</code>, add client IDs/secrets, and follow <code>docs/oauth-setup.md</code>. Tokens are stored AES-256-GCM.</p>
      </div>
    </div>

    <div class="panel" style="margin-bottom:16px">
      <div class="panel-head">
        <div><h3>Connect a channel</h3><div class="sub">{{ configured }} of {{ platforms.length }} ready to authorize</div></div>
      </div>
      <div class="panel-body">
        <div class="grid c3">
          <button
            v-for="p in platforms"
            :key="p.id"
            class="qa-btn"
            style="padding:14px"
            :disabled="!p.can_connect"
            :title="p.is_configured ? `Connect ${p.name}` : `${p.name} needs setup first`"
            @click="connectReal(p)"
          >
            <span class="plat-ico" :class="platCls(p.slug)" style="width:32px;height:32px">
              <component :is="platIcon(p.slug)" style="width:15px;height:15px" />
            </span>
            <span style="text-align:left">
              <span style="display:block;font-weight:700">{{ p.name }}</span>
              <span class="faint" style="font-size:11.5px">{{ p.connected ? `${p.connected} linked` : 'Not linked yet' }}</span>
            </span>
            <CheckCircle2 v-if="p.connected" style="width:16px;height:16px;color:var(--ok);margin-left:auto" />
            <RefreshCw v-else-if="connecting === p.slug" style="width:15px;height:15px;margin-left:auto" class="spin" />
            <ExternalLink v-else-if="p.can_connect" style="width:15px;height:15px;color:var(--fg-faint);margin-left:auto" />
            <span v-else class="badge muted" style="margin-left:auto">setup</span>
          </button>
        </div>
        <p v-if="setupNeeded.length" class="hint" style="margin-top:10px">
          Still needs keys:
          <span v-for="(p, i) in setupNeeded" :key="p.id"><b>{{ p.name }}</b>{{ p.setup_hint ? ` (${p.setup_hint})` : '' }}{{ i < setupNeeded.length - 1 ? ' · ' : '' }}</span>
        </p>
      </div>
    </div>

    <div v-if="!accounts.length" class="panel">
      <div class="panel-body" style="text-align:center;padding:44px 24px">
        <Link2 style="width:26px;height:26px;margin:0 auto 10px;color:var(--fg-faint)" />
        <div style="font-weight:700">No channels yet</div>
        <p class="human-note" style="margin-top:4px">Connect Instagram, TikTok, LinkedIn or X above — then the composer, calendar and analytics all come alive.</p>
      </div>
    </div>

    <div v-else class="grid c3">
      <div v-for="a in accounts" :key="a.id" class="panel">
        <div class="panel-body">
          <div class="row-between">
            <span class="row g2">
              <span class="plat-ico" :class="platCls(a.slug)"><component :is="platIcon(a.slug)" style="width:15px;height:15px" /></span>
              <span style="font-weight:700">{{ a.platform_name }}</span>
            </span>
            <span class="status-txt"><span class="status-dot" />{{ a.connection_status }}</span>
          </div>
          <div style="margin-top:10px">
            <div class="truncate" style="font-weight:700">{{ a.account_name }}</div>
            <div class="faint truncate" style="font-size:12px">{{ a.username }} · {{ a.account_type }}</div>
          </div>
          <div v-if="a.last_error" class="alert error mt2" style="font-size:12px;padding:7px 9px">{{ a.last_error }}</div>
          <div class="metrics mt2">
            <div class="metric"><div class="m-l">Followers</div><div class="m-v tnum">{{ Number(a.followers || 0).toLocaleString() }}</div></div>
            <div class="metric"><div class="m-l">Posts</div><div class="m-v tnum">{{ a.posts_count ?? '—' }}</div></div>
            <div class="metric"><div class="m-l">Following</div><div class="m-v tnum">{{ a.following ?? '—' }}</div></div>
            <div class="metric"><div class="m-l">Eng.</div><div class="m-v tnum">{{ a.engagement_rate ?? '—' }}{{ a.engagement_rate != null ? '%' : '' }}</div></div>
          </div>
          <div class="row-between mt2">
            <span class="faint" style="font-size:11.5px">Synced {{ (a.last_synced_at || 'never').slice(0, 16) }}</span>
            <div class="row g1">
              <button class="btn sm" :disabled="syncing === a.id" @click="sync(a.id)">
                <RefreshCw :class="syncing === a.id ? 'spin' : ''" />{{ syncing === a.id ? '…' : 'Sync' }}
              </button>
              <button v-if="auth.isAdmin" class="btn sm danger" :disabled="disconnecting === a.id" @click="del(a.id)"><Unlink /></button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="liveMode && auth.isAdmin" class="panel" style="margin-top:16px">
      <div class="panel-head"><h3>Track something manually</h3><span class="sub">No API — list only</span></div>
      <div class="panel-body">
        <p class="human-note">For channels with no posting API. It\u2019ll show up for planning, but can\u2019t be published to.</p>
        <div class="grid mt3" style="grid-template-columns:1fr 1.4fr 1.1fr auto;align-items:end">
          <div class="field">
            <label class="label" for="pf">Platform</label>
            <select id="pf" v-model="form.platform_id">
              <option value="">Select…</option>
              <option v-for="p in platforms" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <div class="field">
            <label class="label" for="dn">Display name</label>
            <input id="dn" v-model="form.account_name" placeholder="1TechLink Official" />
          </div>
          <div class="field">
            <label class="label" for="un">Handle</label>
            <input id="un" v-model="form.username" placeholder="@1techlink" />
          </div>
          <button class="btn primary" :disabled="busy" @click="connect">{{ busy ? 'Adding…' : 'Add' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
