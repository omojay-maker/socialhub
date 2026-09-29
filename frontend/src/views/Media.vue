<script setup>
import { ref, onMounted, computed } from 'vue'
import client, { getCsrf } from '../api/client'
import { Upload, Trash2, Video, Images, Info, ShieldCheck, Check } from 'lucide-vue-next'

const media = ref([])
const msg = ref('')
const err = ref('')
const busy = ref(false)
const fileRef = ref(null)
const filter = ref('all')

async function load() {
  const { data } = await client.get('/public/api/media.php?action=list')
  media.value = data.media || []
}

async function upload() {
  err.value = ''
  msg.value = ''
  const f = fileRef.value?.files[0]
  if (!f) { err.value = 'Pick a file first — then hit Upload.'; return }
  busy.value = true
  try {
    const fd = new FormData()
    fd.append('csrf_token', getCsrf())
    fd.append('file', f)
    const { data } = await client.post('/public/api/media.php', fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    if (!data.success) err.value = data.message
    else {
      msg.value = `${f.name} is in the library.`
      await load()
      if (fileRef.value) fileRef.value.value = ''
    }
  } catch (e) {
    err.value = e.response?.data?.message || e.message
  } finally {
    busy.value = false
  }
}

async function del(id) {
  if (!confirm('Delete this file? Posts already using it keep their copy.')) return
  const { data } = await client.post('/public/api/media.php?action=delete&id=' + id, { csrf_token: getCsrf() })
  if (!data.success) err.value = data.message
  await load()
}

onMounted(load)

const shown = computed(() => {
  if (filter.value === 'images') return media.value.filter(m => m.file_type?.startsWith('image'))
  if (filter.value === 'video') return media.value.filter(m => !m.file_type?.startsWith('image'))
  return media.value
})

function kb(n) {
  return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB'
}

function onImgError(ev) {
  ev.target.style.visibility = 'hidden'
}
</script>

<template>
  <div>
    <div class="page-head" style="margin-bottom:18px">
      <div>
        <div class="eyebrow">Assets</div>
        <h1>Media library</h1>
        <p>Upload once, reuse in any post. We check the file itself — renaming a .exe to .jpg won\u2019t fool anyone.</p>
      </div>
      <div class="page-actions">
        <div class="range-tabs">
          <button v-for="t in [['all','All'],['images','Images'],['video','Video']]" :key="t[0]" :class="{ on: filter === t[0] }" @click="filter = t[0]">{{ t[1] }}</button>
        </div>
        <span class="badge muted">{{ media.length }} files</span>
      </div>
    </div>

    <div v-if="err" class="alert error" style="margin-bottom:12px"><Info />{{ err }}</div>
    <div v-if="msg" class="alert success" style="margin-bottom:12px"><Check />{{ msg }}</div>

    <div class="panel" style="margin-bottom:16px">
      <div class="panel-body row g3 wrap" style="align-items:flex-end">
        <div class="field grow" style="min-width:240px">
          <label class="label" for="file">Drop a file in</label>
          <input id="file" ref="fileRef" type="file" accept="image/*,video/*" />
        </div>
        <button class="btn primary" :disabled="busy" @click="upload">
          <Upload />{{ busy ? 'Uploading…' : 'Upload' }}
        </button>
      </div>
      <div class="panel-body" style="padding-top:0">
        <p class="hint" style="margin:0">10 MB max · jpg, png, gif, webp · mp4, mov, avi, webm</p>
      </div>
    </div>

    <div v-if="!shown.length" class="panel">
      <div class="panel-body" style="text-align:center;padding:44px 24px">
        <Images style="width:26px;height:26px;margin:0 auto 10px;color:var(--fg-faint)" />
        <div style="font-weight:700">{{ media.length ? 'Nothing in this tab' : 'Library is empty' }}</div>
        <p class="human-note" style="margin-top:4px">{{ media.length ? 'Try another filter.' : 'Upload a team photo, a logo lockup, or that screenshot you keep reusing.' }}</p>
      </div>
    </div>

    <div v-else class="grid c4">
      <div v-for="m in shown" :key="m.id" class="panel">
        <div class="panel-body" style="padding:12px">
          <div class="media-thumb">
            <img
              v-if="m.file_type?.startsWith('image')"
              :src="m.url"
              :alt="m.original_name"
              loading="lazy"
              @error="onImgError"
            />
            <div v-else class="stack g1" style="align-items:center;color:var(--fg-faint)">
              <Video style="width:18px;height:18px" />
              <span class="truncate" style="font-size:11.5px;max-width:100%">{{ m.original_name }}</span>
            </div>
          </div>
          <div class="mt2">
            <div class="truncate" style="font-size:13px;font-weight:600" :title="m.original_name">{{ m.original_name }}</div>
            <div class="faint" style="font-size:11.5px">{{ m.file_type }} · {{ kb(m.file_size) }}</div>
            <div class="faint truncate" style="font-size:11.5px">{{ m.uploader }} · {{ (m.created_at || '').slice(0, 10) }}</div>
          </div>
          <button class="btn sm danger block mt2" @click="del(m.id)"><Trash2 />Remove</button>
        </div>
      </div>
    </div>

    <div class="row g1 faint mt3" style="font-size:12px">
      <ShieldCheck style="width:13px;height:13px" />
      Stored outside the web root, served through checked paths only.
    </div>
  </div>
</template>
