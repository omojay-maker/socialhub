<script setup>
import { ref, onMounted, computed } from 'vue'
import client, { getCsrf } from '../api/client'
import { CheckCheck, Bell, Check, MessageSquare, AtSign, UserPlus, Heart, Send, TriangleAlert, Instagram, Linkedin, Twitter, Music2 } from 'lucide-vue-next'

const notifications = ref([])
const platform = ref('')
const type = ref('')
const loading = ref(true)

const unread = computed(() => notifications.value.filter(n => !n.is_read).length)

async function load() {
  loading.value = true
  const p = new URLSearchParams()
  if (platform.value) p.set('platform', platform.value)
  if (type.value) p.set('type', type.value)
  const { data } = await client.get('/public/api/notifications.php?' + p.toString())
  notifications.value = data.notifications || []
  loading.value = false
}

async function markRead(id) {
  await client.post('/public/api/notifications.php?action=read&id=' + id, { csrf_token: getCsrf() })
  await load()
}

async function markAll() {
  await client.post('/public/api/notifications.php?action=read_all', { csrf_token: getCsrf() })
  await load()
}

function typeIcon(t = '') {
  t = t.toLowerCase()
  if (t.includes('comment')) return MessageSquare
  if (t.includes('mention')) return AtSign
  if (t.includes('follow')) return UserPlus
  if (t.includes('reaction') || t.includes('like')) return Heart
  if (t.includes('message')) return Send
  if (t.includes('alert')) return TriangleAlert
  return Bell
}
function platIcon(name = '') {
  if (/insta/i.test(name)) return Instagram
  if (/link/i.test(name)) return Linkedin
  if (/tikt/i.test(name)) return Music2
  return Twitter
}
function when(s) {
  if (!s) return ''
  const d = new Date(s.replace(' ', 'T'))
  if (isNaN(d)) return s.slice(0, 16)
  const h = (Date.now() - d.getTime()) / 36e5
  if (h < 1) return 'just now'
  if (h < 24) return `${Math.round(h)}h ago`
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-head" style="margin-bottom:18px">
      <div>
        <div class="eyebrow">Inbox</div>
        <h1>Notifications</h1>
        <p>{{ unread ? `${unread} waiting for you — oldest first is usually the move.` : 'All caught up. New comments, mentions and follows land here.' }}</p>
      </div>
      <div class="page-actions">
        <span v-if="unread" class="badge partial">{{ unread }} unread</span>
        <span v-else class="badge published">Inbox zero</span>
        <button class="btn" :disabled="!unread" @click="markAll"><CheckCheck />Mark all read</button>
      </div>
    </div>

    <div class="panel" style="margin-bottom:16px">
      <div class="panel-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <select v-model="platform" style="width:160px" @change="load">
          <option value="">Every channel</option>
          <option value="instagram">Instagram</option>
          <option value="tiktok">TikTok</option>
          <option value="linkedin">LinkedIn</option>
          <option value="twitter">X</option>
        </select>
        <select v-model="type" style="width:160px" @change="load">
          <option value="">Every kind</option>
          <option value="comment">Replies</option>
          <option value="mention">Mentions</option>
          <option value="follower">New followers</option>
          <option value="reaction">Reactions</option>
          <option value="message">Messages</option>
          <option value="share">Shares</option>
          <option value="alert">Alerts</option>
        </select>
        <span class="faint" style="font-size:12px;margin-left:auto">{{ notifications.length }} shown</span>
      </div>
    </div>

    <div class="panel">
      <div v-if="loading" class="panel-body faint">Pulling the latest…</div>
      <div v-else-if="!notifications.length" class="panel-body" style="text-align:center;padding:44px 24px">
        <Bell style="width:26px;height:26px;margin:0 auto 10px;color:var(--fg-faint)" />
        <div style="font-weight:700">Quiet for now</div>
        <p class="human-note" style="margin-top:4px">Nothing matches these filters. Posting more often tends to fix that.</p>
      </div>
      <div v-else class="list">
        <div
          v-for="n in notifications"
          :key="n.id"
          class="list-row"
          :class="{ unread: !n.is_read }"
        >
          <span class="activity-ico"><component :is="typeIcon(n.type)" /></span>
          <div class="grow">
            <div class="row wrap g2">
              <span style="font-size:13.5px;font-weight:650">{{ n.title }}</span>
              <span class="faint" style="font-size:11.5px;display:inline-flex;align-items:center;gap:4px">
                <component :is="platIcon(n.platform_name)" style="width:12px;height:12px" />{{ n.platform_name }} · {{ n.type }}
              </span>
              <span v-if="!n.is_read" class="badge partial">new</span>
              <span class="faint nowrap" style="font-size:12px;margin-left:auto">{{ when(n.created_at) }}</span>
            </div>
            <p class="muted" style="font-size:13px;margin-top:3px;line-height:1.55">{{ n.message }}</p>
          </div>
          <div style="flex-shrink:0">
            <button v-if="!n.is_read" class="btn sm" @click="markRead(n.id)"><Check />Done</button>
            <span v-else class="row g1 faint" style="font-size:12px"><Check style="width:12px;height:12px" />Seen</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
