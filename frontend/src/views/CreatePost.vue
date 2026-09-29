<script setup>
import { ref, computed, onMounted } from 'vue'
import client, { getCsrf } from '../api/client'
import { Eye, Image as ImageIcon, CalendarClock, Send, Clock3, Save, Info, Paperclip, Check } from 'lucide-vue-next'

const LIMIT = 2200

const content = ref('')
const hashtags = ref('')
const scheduledAt = ref('')
const selected = ref([])
const mediaSel = ref([])
const platforms = ref([])
const media = ref([])
const result = ref(null)
const error = ref('')
const busy = ref(false)
const fileRef = ref(null)

const contentLen = computed(() => content.value.length)
const overLimit = computed(() => contentLen.value > LIMIT)
const hashtagCount = computed(() => (hashtags.value.match(/#/g) || []).length)
const remaining = computed(() => LIMIT - contentLen.value)
const readyToShip = computed(() => content.value.trim().length > 3 && selected.value.length > 0 && !overLimit.value)
const previewTags = computed(() => hashtags.value.trim() || '#INK #Social')

onMounted(async () => {
  const { data } = await client.get('/public/api/accounts.php?action=list')
  platforms.value = data.platforms || []
  const m = await client.get('/public/api/media.php?action=list')
  media.value = m.data?.media || []
})

function togglePlatform(slug) {
  const i = selected.value.indexOf(slug)
  if (i >= 0) selected.value.splice(i, 1)
  else selected.value.push(slug)
}

function toggleMedia(id) {
  const i = mediaSel.value.indexOf(id)
  if (i >= 0) mediaSel.value.splice(i, 1)
  else mediaSel.value.push(id)
}

function useTemplate(kind) {
  if (kind === 'launch') {
    content.value = 'Something new is on its way. We have been quietly building, and next week you finally get to see it.'
    hashtags.value = '#INK #Launch #RealTime'
  } else if (kind === 'update') {
    content.value = 'Small update, big difference: faster loading, smoother chats, fewer taps to get where you are going.'
    hashtags.value = '#Update #INK'
  } else {
    content.value = 'Behind the scenes today — the team testing, tweaking and making sure tomorrow\u2019s post lands right.'
    hashtags.value = '#BehindTheScenes #Team'
  }
}

async function submit(action) {
  error.value = ''
  result.value = null

  if (!content.value.trim()) { error.value = 'Give it a few words first — even a rough line works.'; return }
  if (overLimit.value) { error.value = `That's ${-remaining.value} characters over the Instagram cap (${LIMIT}). Trim a line or two.`; return }
  if (selected.value.length === 0 && action !== 'draft') {
    error.value = 'Pick at least one channel below. Drafts can skip this.'
    return
  }

  busy.value = true
  const fd = new FormData()
  fd.append('csrf_token', getCsrf())
  fd.append('content', content.value)
  fd.append('hashtags', hashtags.value)
  fd.append('action', action)
  if (scheduledAt.value) fd.append('scheduled_at', scheduledAt.value.replace('T', ' ') + ':00')
  selected.value.forEach(s => fd.append('platforms[]', s))
  if (mediaSel.value.length) fd.append('media_ids', JSON.stringify(mediaSel.value))
  if (fileRef.value?.files[0]) fd.append('media_file', fileRef.value.files[0])

  try {
    const { data } = await client.post('/public/api/posts.php?action=create', fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    if (!data.success) error.value = data.message || 'Could not create post'
    else {
      result.value = data
      if (action !== 'draft') {
        content.value = ''
        hashtags.value = ''
        selected.value = []
        mediaSel.value = []
        scheduledAt.value = ''
        if (fileRef.value) fileRef.value.value = ''
      }
    }
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <div class="page-head" style="margin-bottom:18px">
      <div>
        <div class="eyebrow">Write</div>
        <h1>Composer</h1>
        <p>One draft, every channel. Write it like you\u2019d say it — the preview on the right is what followers actually see.</p>
      </div>
      <span class="badge muted">Mock send — nothing leaves the building</span>
    </div>

    <div class="split">
      <div class="panel">
        <div class="panel-body">
          <div v-if="error" class="alert error" style="margin-bottom:12px"><Info />{{ error }}</div>

          <div v-if="result" class="alert success" style="margin-bottom:12px">
            <Check />{{ result.message }}
          </div>

          <div v-if="result?.publish_result" class="panel" style="margin-bottom:14px;background:var(--surface-2)">
            <div class="panel-head"><h3>What happened</h3><span class="sub">Post #{{ result.post_id }}</span></div>
            <div class="panel-body" style="padding-top:6px">
              <div v-for="r in result.publish_result.results" :key="r.platform" class="acct-row">
                <div class="grow" style="font-size:13px;font-weight:600">{{ r.platform_name }}</div>
                <span class="faint" style="font-size:12px">{{ r.error || `Ref ${r.external_id || '—'}` }}</span>
                <span class="badge" :class="r.status">{{ r.status }}</span>
              </div>
            </div>
          </div>

          <div class="row g1 wrap" style="margin-bottom:12px">
            <span class="faint" style="font-size:12px">Stuck? Start from:</span>
            <button class="btn sm" @click="useTemplate('launch')">Launch note</button>
            <button class="btn sm" @click="useTemplate('update')">Product update</button>
            <button class="btn sm" @click="useTemplate('bts')">Behind the scenes</button>
          </div>

          <div class="field">
            <label class="label" for="content">The post itself</label>
            <textarea
              id="content"
              v-model="content"
              rows="6"
              placeholder="e.g. Friday win: our community crossed 26k this week. Thank you for every comment, share and late-night bug report."
            />
            <div class="row-between" style="margin-top:5px">
              <span class="hint" style="margin:0">{{ contentLen }} / {{ LIMIT }} · {{ hashtagCount }} hashtags</span>
              <span class="hint tnum" style="margin:0" :style="overLimit ? { color: 'var(--err)', fontWeight: '700' } : {}">
                {{ overLimit ? `${-remaining} over — trim a line` : `${remaining} left` }}
              </span>
            </div>
          </div>

          <div class="field mt3">
            <label class="label" for="hashtags">Hashtags <span class="faint" style="font-weight:400">(optional, space them out)</span></label>
            <input id="hashtags" v-model="hashtags" placeholder="#INK #RealTime" />
          </div>

          <div class="field mt3">
            <label class="label">Where should it go?</label>
            <div class="row wrap g1">
              <button
                v-for="p in platforms"
                :key="p.id"
                type="button"
                class="chip"
                :class="{ on: selected.includes(p.slug) }"
                @click="togglePlatform(p.slug)"
              >
                <Check v-if="selected.includes(p.slug)" style="width:13px;height:13px" />
                <span v-else class="chip-dot" :style="{ background: p.color || '#5d6b8f' }" />
                {{ p.name }}
              </button>
              <span v-if="!platforms.length" class="hint">No channels connected yet — check Accounts.</span>
            </div>
            <p class="hint">{{ selected.length ? `${selected.length} selected — looking good.` : 'Drafts can skip this; publishing needs at least one.' }}</p>
          </div>

          <div class="grid c2 mt3">
            <div class="field">
              <label class="label"><Paperclip style="width:13px;height:13px;display:inline;vertical-align:-2px" /> Attach something new</label>
              <input ref="fileRef" type="file" accept="image/*,video/*" />
              <p class="hint">Under 10 MB. We check the actual file, not the extension.</p>
            </div>
            <div class="field">
              <label class="label"><CalendarClock style="width:13px;height:13px;display:inline;vertical-align:-2px" /> Or send it later</label>
              <input v-model="scheduledAt" type="datetime-local" />
              <p class="hint">Empty means now. Scheduled ones wait for the cron run.</p>
            </div>
          </div>

          <div v-if="media.length" class="field mt3">
            <label class="label">Reuse from the library</label>
            <div class="row wrap g1">
              <button
                v-for="m in media.slice(0, 8)"
                :key="m.id"
                type="button"
                class="chip"
                :class="{ on: mediaSel.includes(m.id) }"
                @click="toggleMedia(m.id)"
              >
                <ImageIcon style="width:12px;height:12px" />
                {{ (m.original_name || '').slice(0, 18) }}
              </button>
            </div>
          </div>

          <div class="row g2 wrap mt4" style="padding-top:14px;border-top:1px solid var(--border-soft)">
            <button class="btn" :disabled="busy" @click="submit('draft')"><Save />Keep as draft</button>
            <button class="btn" :disabled="busy" @click="submit('scheduled')"><Clock3 />Schedule</button>
            <button class="btn primary" :disabled="busy || !readyToShip" :title="readyToShip ? 'Send it' : 'Write a few words and pick a channel first'" @click="submit('publish')">
              <Send />{{ busy ? 'Sending…' : 'Publish now' }}
            </button>
          </div>
        </div>
      </div>

      <div class="stack g2">
        <div class="panel">
          <div class="panel-head">
            <h3><Eye style="width:14px;height:14px;display:inline;vertical-align:-2px" /> How it\u2019ll look</h3>
            <span class="sub">{{ selected.length ? selected.join(' · ') : 'Pick a channel' }}</span>
          </div>
          <div class="panel-body">
            <div class="row g2">
              <div class="avatar" style="width:34px;height:34px;flex-basis:34px">1T</div>
              <div class="grow" style="line-height:1.35">
                <div style="font-size:13.5px;font-weight:700">1TechLink <span class="faint" style="font-weight:400">· just now</span></div>
                <div class="faint" style="font-size:12px">{{ selected.length ? 'Shown on ' + selected.join(', ') : 'Channel preview' }}</div>
              </div>
            </div>
            <p style="margin-top:12px;font-size:13.5px;line-height:1.65;white-space:pre-wrap">{{ content || 'Nothing written yet — your words land here the moment you type.' }}</p>
            <p style="margin-top:6px;font-size:13px;color:#3b82f6">{{ previewTags }}</p>
            <div class="row g3 faint" style="font-size:12px;margin-top:12px;padding-top:10px;border-top:1px solid var(--border-soft)">
              <span>♡ Like</span><span>Comment</span><span>Share</span>
            </div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-body">
            <div class="eyebrow" style="margin-bottom:8px">Good to know</div>
            <p class="human-note">Instagram cuts captions at <b>2,200 characters</b>. Scheduled posts wait for <b>cron</b> — usually within 5 minutes. And yes, mock mode <b>fails on purpose</b> sometimes, so you can practise the retry flow.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
