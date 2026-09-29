<script setup>
import { ref, onMounted, computed } from 'vue'
import client, { getCsrf } from '../api/client'
import { UserPlus, Trash2, Info, Check, ShieldCheck } from 'lucide-vue-next'

const users = ref([])
const form = ref({ name: '', email: '', password: '', role: 'content_manager' })
const msg = ref('')
const err = ref('')
const busy = ref(false)

async function load() {
  const { data } = await client.get('/public/api/users.php?action=list')
  users.value = data.users || []
}

async function create() {
  err.value = ''
  msg.value = ''
  if (!form.value.name || !form.value.email || !form.value.password) {
    err.value = 'Name, email and a starting password — all three.'
    return
  }
  busy.value = true
  try {
    const { data } = await client.post('/public/api/users.php', { ...form.value, csrf_token: getCsrf() })
    if (!data.success) err.value = data.message
    else {
      msg.value = `${form.value.name} can sign in now.`
      form.value = { name: '', email: '', password: '', role: 'content_manager' }
      await load()
    }
  } catch (e) {
    err.value = e.response?.data?.message || e.message
  } finally {
    busy.value = false
  }
}

async function del(id) {
  if (!confirm('Remove this person from the workspace?')) return
  const { data } = await client.post('/public/api/users.php?action=delete&id=' + id, { csrf_token: getCsrf() })
  if (data.success) await load()
  else err.value = data.message
}

const admins = computed(() => users.value.filter(u => u.role === 'admin').length)

function initials(name = '?') {
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}
function lastSeen(u) {
  if (!u.last_login_at) return 'never signed in'
  const d = new Date(u.last_login_at.replace(' ', 'T'))
  if (isNaN(d)) return u.last_login_at.slice(0, 10)
  const h = (Date.now() - d.getTime()) / 36e5
  if (h < 24) return `active ${Math.max(1, Math.round(h))}h ago`
  return `active ${d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-head" style="margin-bottom:18px">
      <div>
        <div class="eyebrow">Workspace</div>
        <h1>Team</h1>
        <p>{{ users.length ? `${users.length} people · ${admins} admin${admins === 1 ? '' : 's'}. Managers can write and schedule; only admins touch accounts and teammates.` : 'Just you so far. Bring someone in below.' }}</p>
      </div>
      <span class="badge outline">Admins only</span>
    </div>

    <div v-if="err" class="alert error" style="margin-bottom:12px"><Info />{{ err }}</div>
    <div v-if="msg" class="alert success" style="margin-bottom:12px"><Check />{{ msg }}</div>

    <div class="panel" style="margin-bottom:16px">
      <div class="panel-head">
        <div><h3>Invite someone</h3><div class="sub">They get an email + password login right away</div></div>
      </div>
      <div class="panel-body">
        <div class="grid" style="grid-template-columns:1fr 1.4fr 1fr 1fr auto;align-items:end">
          <div class="field">
            <label class="label" for="un">Full name</label>
            <input id="un" v-model="form.name" placeholder="Adaeze Okafor" />
          </div>
          <div class="field">
            <label class="label" for="ue">Work email</label>
            <input id="ue" v-model="form.email" type="email" placeholder="adaeze@1techlink.com" />
          </div>
          <div class="field">
            <label class="label" for="up">Starting password</label>
            <input id="up" v-model="form.password" type="password" placeholder="Something they\u2019ll change" />
          </div>
          <div class="field">
            <label class="label" for="ur">Role</label>
            <select id="ur" v-model="form.role">
              <option value="content_manager">Content manager</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <button class="btn primary" :disabled="busy" @click="create">
            <UserPlus />{{ busy ? '…' : 'Invite' }}
          </button>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3>Everyone here</h3><span class="sub">{{ users.length }} total</span></div>
      <div v-if="!users.length" class="panel-body" style="text-align:center;padding:36px">
        <div style="font-weight:700">Nobody yet</div>
        <p class="human-note">Your first invite takes about 20 seconds.</p>
      </div>
      <div v-else class="list">
        <div v-for="u in users" :key="u.id" class="list-row">
          <span class="inline-ava">{{ initials(u.name) }}</span>
          <div class="grow">
            <div class="row wrap g2">
              <span style="font-weight:650">{{ u.name }}</span>
              <span class="badge" :class="u.role === 'admin' ? 'brand' : 'muted'">{{ u.role === 'admin' ? 'admin' : 'manager' }}</span>
              <span class="badge" :class="u.is_active ? 'published' : 'failed'">{{ u.is_active ? 'active' : 'disabled' }}</span>
            </div>
            <div class="faint" style="font-size:12px;margin-top:2px">{{ u.email }} · {{ lastSeen(u) }} · joined {{ (u.created_at || '').slice(0, 10) }}</div>
          </div>
          <button class="btn sm danger" @click="del(u.id)"><Trash2 />Remove</button>
        </div>
      </div>
      <div class="card-foot row g1">
        <ShieldCheck style="width:13px;height:13px" />
        Admins can remove anyone except the last admin standing.
      </div>
    </div>
  </div>
</template>
