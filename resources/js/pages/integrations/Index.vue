<script setup lang="ts">
import { ref, reactive, onMounted, nextTick, watch, computed } from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import { message, Popconfirm, Space } from 'ant-design-vue'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin from '@fullcalendar/interaction'
import listPlugin from '@fullcalendar/list'
import { PlusOutlined } from '@ant-design/icons-vue'
import { type BreadcrumbItem } from '@/types'
import AppLayout from '@/layouts/AppLayout.vue'

/**
 * 🔧 Google API credentials (replace with your own or pass via props/.env)
 * NOTE: you must add http://localhost:xxxx (your dev origin) to OAuth authorized origins in Google Cloud.
 */
const GOOGLE_CLIENT_ID = '1061288445293-isu2t7gl3256g5825d7a6k941alshl2i.apps.googleusercontent.com'
const GOOGLE_API_KEY = 'AIzaSyCNDRzrfwUyoz9W5P9ksd8VQPnvIuu1V0M'
const GOOGLE_SCOPES = 'https://www.googleapis.com/auth/calendar.readonly'

// ---- Types ----
type EventItem = {
  id?: number | string
  title: string
  description?: string | null
  all_day: boolean
  start: string // ISO
  end?: string | null // ISO
  color?: string | null
  location?: string | null
  meta?: Record<string, any> | null
}

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Calendar', href: 'calendar' },
]

// 1) Add a small helper for consistent formatting
const ISO_FMT = 'YYYY-MM-DDTHH:mm:ssZ'
const toISO = (d: Date | string) => dayjs(d).format(ISO_FMT)

// helper to refetch calendar's events callback
const refetch = () => calendarRef.value?.getApi()?.refetchEvents()

const calendarRef = ref()
const events = ref<EventItem[]>([])
const loading = ref(false)

// Drawer/Modal state
const isOpen = ref(false)
const isEdit = ref(false)
const formRef = ref()
const form = reactive<EventItem>({
  title: '',
  description: '',
  all_day: false,
  start: '',
  end: '',
  color: '#1677ff',
  location: '',
  meta: {}
})

// =========================
// Google Calendar integration
// =========================
// eslint-disable-next-line @typescript-eslint/no-explicit-any
declare const gapi: any
// eslint-disable-next-line @typescript-eslint/no-explicit-any
declare const google: any

const gReady = ref(false)              // both libraries loaded
const gSignedIn = ref(false)           // has a token
let gTokenClient: any | null = null
let gapiInited = false
let gisInited = false

const authorizeLabel = computed(() => (gSignedIn.value ? 'Refresh' : 'Authorize'))

function loadScript(src: string, attrs: Record<string, string> = {}) {
  return new Promise<void>((resolve, reject) => {
    const existing = Array.from(document.scripts).find(s => s.src === src)
    if (existing) {
      if ((existing as any).dataset.loaded === '1') return resolve()
      existing.addEventListener('load', () => resolve())
      existing.addEventListener('error', () => reject(new Error(`Failed to load ${src}`)))
      return
    }
    const el = document.createElement('script')
    el.src = src
    el.async = true
    el.defer = true
    Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v))
    el.addEventListener('load', () => { (el as any).dataset.loaded = '1'; resolve() })
    el.addEventListener('error', () => reject(new Error(`Failed to load ${src}`)))
    document.head.appendChild(el)
  })
}

async function gapiLoaded() {
  await new Promise<void>((resolve) => {
    gapi.load('client', async () => {
      await gapi.client.init({
        apiKey: GOOGLE_API_KEY,
        discoveryDocs: ['https://www.googleapis.com/discovery/v1/apis/calendar/v3/rest'],
      })
      gapiInited = true
      resolve()
    })
  })
  maybeEnableButtons()
}

function gisLoaded() {
  gTokenClient = google.accounts.oauth2.initTokenClient({
    client_id: GOOGLE_CLIENT_ID,
    scope: GOOGLE_SCOPES,
    callback: () => {}, // set later
  })
  gisInited = true
  maybeEnableButtons()
}

function maybeEnableButtons() {
  if (gapiInited && gisInited) gReady.value = true
}

async function ensureAuth(): Promise<boolean> {
  if (!gTokenClient) return false
  return new Promise((resolve) => {
    gTokenClient!.callback = (resp: any) => {
      if (resp?.error) {
        message.error(String(resp.error))
        return resolve(false)
      }
      gSignedIn.value = true
      resolve(true)
    }
    if (gapi.client.getToken() === null) {
      gTokenClient!.requestAccessToken({ prompt: 'consent' })
    } else {
      gTokenClient!.requestAccessToken({ prompt: '' })
    }
  })
}

function signOutGoogle() {
  const token = gapi?.client?.getToken?.()
  if (token) {
    google.accounts.oauth2.revoke(token.access_token)
    gapi.client.setToken(null)
  }
  gSignedIn.value = false
}

// Fetch Google events for the visible range (handles pagination)
async function fetchGoogleEventsForRange(timeMinISO: string, timeMaxISO: string) {
  const items: any[] = []
  let pageToken: string | undefined
  do {
    // eslint-disable-next-line no-await-in-loop
    const resp = await gapi.client.calendar.events.list({
      calendarId: 'primary',
      timeMin: timeMinISO,
      timeMax: timeMaxISO,
      showDeleted: false,
      singleEvents: true,
      maxResults: 2500,
      orderBy: 'startTime',
      pageToken,
    })
    items.push(...(resp.result.items ?? []))
    pageToken = resp.result.nextPageToken
  } while (pageToken)
  return items
}

function mapGoogleToEventItem(ge: any): EventItem {
  const start = ge.start?.dateTime || ge.start?.date // date for all-day
  const end = ge.end?.dateTime || ge.end?.date
  const allDay = Boolean(ge.start?.date && !ge.start?.dateTime)
  return {
    id: `gcal_${ge.id}`,
    title: ge.summary || '(no title)',
    description: ge.description || null,
    all_day: allDay,
    start: allDay ? dayjs(start).format('YYYY-MM-DD') : toISO(start),
    end: end ? (allDay ? dayjs(end).format('YYYY-MM-DD') : toISO(end)) : null,
    color: '#722ed1', // Lavender for Google-synced events
    location: ge.location || null,
    meta: {
      source: 'google',
      htmlLink: ge.htmlLink,
      attendees: ge.attendees,
      organizer: ge.organizer,
      raw: ge,
    },
  }
}

async function syncGoogleCalendar()
{
  const api = calendarRef.value?.getApi()
  const start = api?.view?.activeStart
  const end = api?.view?.activeEnd
  if (!start || !end) return

  const ok = await ensureAuth()
  if (!ok) return

  try {
    loading.value = true
    const gItems = await fetchGoogleEventsForRange(start.toISOString(), end.toISOString())
    const mapped = gItems.map(mapGoogleToEventItem)

    // Merge strategy: replace existing Google-sourced events in this range, keep local ones
    const isGoogle = (e: EventItem) => String(e.id).startsWith('gcal_')
    const inRange = (e: EventItem) => {
      const s = dayjs(e.start)
      const st = dayjs(start)
      const en = dayjs(end)
      return s.isAfter(st.subtract(1, 'minute')) && s.isBefore(en.add(1, 'minute'))
    }

    events.value = [
      ...events.value.filter(e => !(isGoogle(e) && inRange(e))),
      ...mapped,
    ]

    message.success(`Synced ${mapped.length} Google events to this view`)
    refetch()
  } catch (err: any) {
    console.error(err)
    message.error(err?.message || 'Failed to sync Google Calendar')
  } finally {
    loading.value = false
  }
}

// =========================
// Local CRUD / server events
// =========================
const fetchEvents = async (range?: { startStr?: string; endStr?: string }) => {
  loading.value = true
  try {
    const { data } = await axios.get('/events', { params: range || {} })
    events.value = data
    refetch()
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  form.title = ''
  form.description = ''
  form.all_day = false
  form.start = ''
  form.end = ''
  form.color = '#1677ff'
  form.location = ''
  form.meta = {}
  isEdit.value = false
}

const openCreate = (startISO: string, endISO?: string) => {
  resetForm()
  form.start = startISO
  form.end = endISO || startISO
  isOpen.value = true
}

const openEdit = (e: any) => {
  resetForm()
  isEdit.value = true
  const ev: EventItem = e.event.extendedProps.full || {
    id: String(e.event.id),
    title: e.event.title,
    all_day: e.event.allDay,
    start: e.event.start?.toISOString() || '',
    end: e.event.end?.toISOString() || '',
    color: e.event.backgroundColor || '#1677ff',
  }
  Object.assign(form, ev)
  isOpen.value = true
}

const saveEvent = async () => {
  try {
    await formRef.value?.validate?.()
    if (isEdit.value && form.id && !String(form.id).startsWith('gcal_')) {
      const { data } = await axios.put(`/events/${form.id}`, form)
      const idx = events.value.findIndex(e => e.id === data.id)
      if (idx > -1) events.value[idx] = data
      message.success('Event updated')
    } else if (!isEdit.value) {
      const { data } = await axios.post('/events', form)
      events.value.push(data)
      message.success('Event created')
    } else {
      message.info('Google-synced events are read-only here.')
    }
    isOpen.value = false
  } catch (e) {/* validation or request error */}
}

const deleteEvent = async () => {
  if (!form.id) return
  if (String(form.id).startsWith('gcal_')) {
    message.info('Delete Google events in Google Calendar directly.')
    return
  }
  await axios.delete(`/events/${form.id}`)
  events.value = events.value.filter(e => e.id !== form.id)
  message.success('Event deleted')
  isOpen.value = false
}

// =========================
// FullCalendar options
// =========================
const calendarOptions = ref({
  height: '100%',
  eventDidMount(info: any) {
    const color = info.event.extendedProps?.full?.color || info.event.backgroundColor || undefined
    if (color) {
      info.el.style.backgroundColor = color
      info.el.style.borderColor = color
      info.el.style.color = '#fff'
      info.el.style.opacity = '0.85'
    }
  },
  timeZone: 'UTC',
  plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin, listPlugin],
  initialView: 'dayGridMonth',
  headerToolbar: {
    left: 'prev,next today gsync',
    center: 'title',
    right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
  },
  customButtons: {
    gsync: {
      text: 'Sync Google Calendar',
      click: async () => {
        if (!gReady.value) {
          message.loading('Loading Google libraries…', 1)
          return
        }
        await syncGoogleCalendar()
      }
    }
  },
  selectable: true,
  editable: true,
  selectMirror: true,
  displayEventTime: false,
  eventTimeFormat: { hour: '2-digit' as const, minute: '2-digit' as const },
  select: (info: any) => {
    const startISO = toISO(info.start)
    const endISO = info.end ? toISO(info.end) : startISO
    form.all_day = !!info.allDay || info.startStr.length === 10
    openCreate(endISO, endISO)
  },
  eventClick: openEdit,
  eventDrop: async (info: any) => {
    const id = String(info.event.id)
    if (id.startsWith('gcal_')) {
      info.revert()
      message.info('Google-synced events are read-only here.')
      return
    }
    const payload: Partial<EventItem> = {
      start: info.event.start?.toISOString(),
      end: info.event.end?.toISOString() || null,
      all_day: info.event.allDay
    }
    await axios.put(`/events/${id}` as any, payload)
    message.success('Event moved')
  },
  eventResize: async (info: any) => {
    const id = String(info.event.id)
    if (id.startsWith('gcal_')) {
      info.revert()
      message.info('Google-synced events are read-only here.')
      return
    }
    const payload: Partial<EventItem> = {
      start: info.event.start?.toISOString(),
      end: info.event.end?.toISOString() || null
    }
    await axios.put(`/events/${id}` as any, payload)
    message.success('Event resized')
  },
  datesSet: async (arg: any) => {
    await fetchEvents({ startStr: arg.startStr, endStr: arg.endStr })
  },
  events: (_fetchInfo: any, successCallback: any) => {
    successCallback(
      events.value.map(e => ({
        id: String(e.id),
        title: e.title,
        start: e.start,
        end: e.end || undefined,
        allDay: e.all_day,
        backgroundColor: e.color || undefined,
        borderColor: e.color || undefined,
        extendedProps: { full: e },
      }))
    )
  }
})

onMounted(async () => {
  await nextTick()
  // Init FullCalendar visible range fetch
  const api = calendarRef.value?.getApi()
  const startStr = api?.view?.activeStart?.toISOString()
  const endStr = api?.view?.activeEnd?.toISOString()
  await fetchEvents({ startStr, endStr })

  // Load Google libraries
  try {
    await loadScript('https://apis.google.com/js/api.js')
    await gapiLoaded()
    await loadScript('https://accounts.google.com/gsi/client')
    gisLoaded()
  } catch (e) {
    // Non-fatal for local calendar
    console.warn('Google scripts failed to load', e)
  }
})

const newFromCurrentView = () => {
  const d = calendarRef.value?.getApi()?.getDate() ?? new Date()
  openCreate(toISO(dayjs(d).startOf('day').toDate()))
}

// Color options with latte/matcha palette + classics
const colorOptions = [
  { value: '#1677ff', label: 'Blue' },
  { value: '#52c41a', label: 'Matcha' },
  { value: '#faad14', label: 'Caramel' },
  { value: '#f5222d', label: 'Rose' },
  { value: '#722ed1', label: 'Lavender' },
  { value: '#b08d57', label: 'Oat' },
  { value: '#7cc4a4', label: 'Pistachio' },
]
</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="w-full h-full">
      <a-alert v-if="loading" type="info" show-icon message="Loading events..." class="mb-3 latte-alert" />

      <!-- Calendar card with Google auth chips -->
      <div class="h-full bg-white/95 shadow-[0_8px_30px_rgb(0,0,0,0.06)] ring-1 ring-latte-200/70 overflow-hidden">
        <!-- Top bar (optional: shows Google auth state) -->
        <div class="flex items-center justify-between px-3 py-2 border-b border-black/5">
          <div class="text-sm text-latte-500">FullCalendar</div>
          <div class="flex items-center gap-2">
            <a-tag v-if="gReady" color="success" class="rounded-full">Google Ready</a-tag>
            <a-tag v-else color="default" class="rounded-full">Google Loading…</a-tag>
            <a-button size="small" @click="gSignedIn ? signOutGoogle() : ensureAuth()">
              {{ gSignedIn ? 'Sign out' : authorizeLabel }}
            </a-button>
            <a-button type="primary" size="small" @click="syncGoogleCalendar" :disabled="!gReady">
              Sync Google Calendar
            </a-button>
          </div>
        </div>

        <FullCalendar ref="calendarRef" :options="calendarOptions" />
      </div>

      <!-- FAB for smaller screens -->
      <a-button class="fab md:hidden" type="primary" shape="round" :style="{ zIndex: 9999 }" @click="newFromCurrentView()">
        <template #icon>
          <PlusOutlined />
        </template>
        Add
      </a-button>

      <!-- Drawer/Modal -->
      <a-modal v-model:open="isOpen" :title="isEdit ? 'Edit Event' : 'New Evasdasdent'" :footer="null" destroy-on-close width="560px">
        <a-form ref="formRef" :model="form" layout="vertical">
          <a-form-item name="title" label="Title" :rules="[{ required: true, message: 'Title is required' }]"><a-input v-model:value="form.title" placeholder="e.g., Team Standup" /></a-form-item>
          <a-form-item label="Description" name="description"><a-textarea v-model:value="form.description" auto-size placeholder="Optional notes..." /></a-form-item>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <a-form-item label="All-day" name="all_day"><a-checkbox v-model:checked="form.all_day">All-day</a-checkbox></a-form-item>
            <a-form-item label="Color" name="color">
              <a-select v-model:value="form.color" :options="colorOptions" :dropdownStyle="{ padding: '6px' }">
                <template #option="{ value, label }">
                  <div class="flex items-center gap-2">
                    <span class="h-4 w-4 rounded-full border border-black/10" :style="{ background: value }"></span>
                    <span>{{ label }}</span>
                  </div>
                </template>
                <template #tagRender="{ value, label, closable, onClose }">
                  <a-tag :closable="closable" @close="onClose" class="rounded-full" :style="{ background: value, color: '#fff', border: 'none' }">{{ label }}</a-tag>
                </template>
              </a-select>
            </a-form-item>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <a-form-item label="Start" name="start" :rules="[{ required: true, message: 'Start is required' }]">
              <a-date-picker v-model:value="form.start" :show-time="!form.all_day" :value-format="form.all_day ? 'YYYY-MM-DD' : 'YYYY-MM-DDTHH:mm:ssZ'" style="width:100%" />
            </a-form-item>
            <a-form-item label="End" name="end">
              <a-date-picker v-model:value="form.end" :show-time="!form.all_day" :value-format="form.all_day ? 'YYYY-MM-DD' : 'YYYY-MM-DDTHH:mm:ssZ'" style="width:100%" />
            </a-form-item>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <a-form-item label="Location" name="location"><a-input v-model:value="form.location" placeholder="Optional" /></a-form-item>
            <a-form-item label="Tags (meta.tags)">
              <a-input :value="(form.meta?.tags || []).join(', ')" @update:value="(v: string) => { form.meta = { ...(form.meta || {}), tags: v.split(',').map(s => s.trim()).filter(Boolean) } }" placeholder="Comma separated" />
            </a-form-item>
          </div>

          <div class="flex justify-between mt-4">
            <Space>
              <a-button @click="isOpen = false">Cancel</a-button>
              <a-button type="primary" @click="saveEvent">{{ isEdit ? 'Save' : 'Create' }}</a-button>
            </Space>
            <Popconfirm v-if="isEdit" title="Delete this event?" ok-text="Delete" ok-type="danger" @confirm="deleteEvent">
              <a-button danger>Delete</a-button>
            </Popconfirm>
          </div>
        </a-form>
      </a-modal>
    </div>
  </AppLayout>
</template>

<style scoped>
:root, :host {
  --latte-50: #faf8f6;
  --latte-100: #f4efe9;
  --latte-200: #ebe2d5;
  --latte-900: #2a2113;
  --matcha-100: #eaf7ef;
  --matcha-200: #d8f0e2;
}
.bg-latte-50 { background-color: var(--latte-50); }
.bg-latte-100 { background-color: var(--latte-100); }
.bg-latte-200 { background-color: var(--latte-200); }
.text-latte-900 { color: var(--latte-900); }
.text-latte-500 { color: #7b7367; }
.from-latte-200 { --tw-gradient-from: var(--latte-200); }
.via-latte-100 { --tw-gradient-stops: var(--tw-gradient-from), var(--latte-100), var(--tw-gradient-to, rgba(255, 255, 255, 0)); }
.bg-matcha-100 { background-color: var(--matcha-100); }
.border-matcha-200 { border-color: var(--matcha-200); }
.border-latte-200 { border-color: var(--latte-200); }
.ring-latte-200 { --tw-ring-color: var(--latte-200); }

/* Buttons */
.soft-btn { background: linear-gradient(180deg, #ffffff, #f7f4f0); border: 1px solid rgba(0, 0, 0, 0.06); box-shadow: 0 1px 0 rgba(255, 255, 255, .6) inset, 0 6px 16px rgba(0, 0, 0, .06); }
.soft-btn:hover { filter: brightness(0.98); }
.icon-btn { background: #fff; border: 1px solid rgba(0, 0, 0, 0.06); }

/* Floating action button (mobile) */
.fab { position: fixed; right: 16px; bottom: 16px; box-shadow: 0 10px 25px rgba(0, 0, 0, .12); pointer-events: auto; z-index: 9999; }

/* ---------- FullCalendar polish ---------- */
:deep(.fc) { background: transparent; z-index: 1; }
:deep(.fc .fc-toolbar.fc-header-toolbar) { padding: .5rem .75rem; margin: .5rem; border-bottom: 1px solid rgba(0, 0, 0, 0.05); }
:deep(.fc .fc-toolbar-title) { font-weight: 700; letter-spacing: 0.2px; color: #000; }
*{ font-family:Georgia, 'Times New Roman', Times, serif !important; }
:deep(.fc-theme-standard .fc-scrollgrid) { border: none; }
:deep(.fc-theme-standard td), :deep(.fc-theme-standard th) { border-color: rgba(0, 0, 0, 0.06); }
:deep(.fc .fc-daygrid-day-number) { font-weight: 600; color: #5a5246; padding: .35rem .5rem; }
:deep(.fc .fc-day-today) { color: #92774e !important; background: linear-gradient(180deg, rgba(68, 68, 68, 0.1), rgba(133, 133, 133, 0.312)); }
:deep(.fc-col-header-cell-cushion) { color: #92774e; }
:deep(.fc .fc-event) { border: 0 !important; border-radius: 5px !important; padding: 2px 10px !important; box-shadow: 0 2px 8px rgba(0, 0, 0, .06); font-weight: 600; }
:deep(.fc .fc-event .fc-event-title) { color: #fff; text-shadow: 0 1px 0 rgba(0, 0, 0, .18); }
:deep(.fc .fc-daygrid-event:hover) { transform: translateY(-1px); transition: transform 120ms ease; }
.latte-alert :deep(.ant-alert-message) { color: #5a5246; }
.calendar-shell { transition: all 160ms ease; }
</style>
