<!-- resources/js/components/TodoBoard.vue -->
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import dayjs from 'dayjs'
import { useTodos } from '@/composables/useTodos'


const { todos, users, loading, error, fetchAll, create, update, remove, listToday, listLater, listDone, urgencyClasses } = useTodos()

// form state
const draft = ref({
  title: '',
  description: '',
  urgency: 3 as 1|2|3|4|5,
  status: 'todo' as 'todo'|'in_progress'|'done',
  due_at: '' as string | '',
  assignee_id: null as number | null
})

function resetDraft() {
  draft.value = { title:'', description:'', urgency:3, status:'todo', due_at:'', assignee_id:null }
}

const saving = ref(false)
const editingId = ref<number|null>(null)

const save = async () => {
  if (!draft.value.title.trim()) return
  saving.value = true
  try {
    await create({
      title: draft.value.title.trim(),
      description: draft.value.description || null,
      urgency: draft.value.urgency,
      status: draft.value.status,
      due_at: draft.value.due_at || null,
      assignee_id: draft.value.assignee_id
    })
    resetDraft()
  } finally { saving.value = false }
}

const beginEdit = (id:number) => { editingId.value = id }
const commitEdit = async (id:number, payload:any) => {
  await update(id, payload)
  editingId.value = null
}
const quickToggleDone = async (t:any) => {
  await update(t.id, { status: t.status === 'done' ? 'todo' : 'done' })
}
const del = async (id:number) => { await remove(id) }

onMounted(async () => {
  await fetchAll()
})
</script>

<template>
  <div class="h-full grid grid-rows-[auto_1fr]">
    <!-- Header / New -->
    <div class="border-b p-4 flex items-center gap-3">
      <h2 class="text-lg font-semibold">To-Do</h2>
      <span v-if="loading" class="text-xs text-muted-foreground">Loading…</span>
      <span v-if="error" class="text-xs text-red-600">{{ error }}</span>
    </div>

    <div class="overflow-auto p-4 grid gap-6 md:grid-cols-3">
      <!-- New Todo card -->
      <div class="rounded-xl border p-4 bg-card/50 backdrop-blur-sm space-y-3">
        <div class="space-y-2">
          <input v-model="draft.title" class="w-full rounded-lg border px-3 py-2" placeholder="Title *" />
          <textarea v-model="draft.description" rows="3" class="w-full rounded-lg border px-3 py-2" placeholder="Description (optional)" />
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs text-muted-foreground">Urgency</label>
            <select v-model.number="draft.urgency" class="w-full rounded-lg border px-2 py-2">
              <option :value="1">1 — Not Urgent</option>
              <option :value="2">2</option>
              <option :value="3">3 — Normal</option>
              <option :value="4">4</option>
              <option :value="5">5 — Urgent</option>
            </select>
          </div>
          <div>
            <label class="text-xs text-muted-foreground">Status</label>
            <select v-model="draft.status" class="w-full rounded-lg border px-2 py-2">
              <option value="todo">To-Do</option>
              <option value="in_progress">In Progress</option>
              <option value="done">Done</option>
            </select>
          </div>

          <div>
            <label class="text-xs text-muted-foreground">Due</label>
            <input type="datetime-local" v-model="draft.due_at" class="w-full rounded-lg border px-2 py-2" />
          </div>
          <div>
            <label class="text-xs text-muted-foreground">Assignee</label>
            <select v-model.number="draft.assignee_id" class="w-full rounded-lg border px-2 py-2">
              <option :value="null">Unassigned</option>
              <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>
        </div>

        <button :disabled="saving || !draft.title.trim()" @click="save"
          class="rounded-lg px-3 py-2 border hover:bg-accent disabled:opacity-50">
          {{ saving ? 'Saving…' : 'Add Task' }}
        </button>
      </div>

      <!-- Today/Week -->
      <div class="space-y-3">
        <h3 class="text-sm font-semibold">Today / This Week</h3>
        <div v-if="listToday.length===0" class="text-xs text-muted-foreground">Nothing due soon.</div>
        <div v-for="t in listToday" :key="t.id"
             class="rounded-xl border p-3 flex items-start gap-3"
             :class="urgencyClasses[t.urgency]">
          <button @click="quickToggleDone(t)"
            class="shrink-0 mt-1 h-5 w-5 rounded border grid place-items-center">
            <span v-if="t.status==='done'">✓</span>
          </button>

          <div class="flex-1">
            <div class="flex items-center gap-2">
              <input v-if="editingId===t.id" class="border rounded px-2 py-1 w-full"
                     :value="t.title"
                     @change="commitEdit(t.id, { title: ($event.target as HTMLInputElement).value })"
                     @keydown.enter="commitEdit(t.id, { title: ($event.target as HTMLInputElement).value })"
                     @blur="editingId=null" />
              <div v-else class="font-medium cursor-text" @dblclick="beginEdit(t.id)">{{ t.title }}</div>

              <span class="text-[10px] px-1.5 py-0.5 rounded border">{{ t.urgency }}</span>
            </div>
            <div class="text-xs opacity-80">
              <span v-if="t.due_at">Due {{ dayjs(t.due_at).format('MMM D, HH:mm') }}</span>
              <span v-if="t.assignee"> · @{{ t.assignee.name }}</span>
            </div>

            <div v-if="t.description" class="mt-1 text-sm opacity-90">{{ t.description }}</div>

            <div class="mt-2 flex flex-wrap gap-2">
              <select :value="t.status"
                      @change="commitEdit(t.id, { status: ($event.target as HTMLSelectElement).value })"
                      class="rounded border px-2 py-1 text-xs">
                <option value="todo">To-Do</option>
                <option value="in_progress">In Progress</option>
                <option value="done">Done</option>
              </select>

              <select :value="t.assignee_id ?? ''"
                      @change="commitEdit(t.id, { assignee_id: Number(($event.target as HTMLSelectElement).value) || null })"
                      class="rounded border px-2 py-1 text-xs">
                <option value="">Unassigned</option>
                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
              </select>

              <select :value="t.urgency"
                      @change="commitEdit(t.id, { urgency: Number(($event.target as HTMLSelectElement).value) as 1|2|3|4|5 })"
                      class="rounded border px-2 py-1 text-xs">
                <option :value="1">1</option><option :value="2">2</option>
                <option :value="3">3</option><option :value="4">4</option>
                <option :value="5">5</option>
              </select>

              <input type="datetime-local"
                     :value="t.due_at ? dayjs(t.due_at).format('YYYY-MM-DDTHH:mm') : ''"
                     @change="commitEdit(t.id, { due_at: ($event.target as HTMLInputElement).value || null })"
                     class="rounded border px-2 py-1 text-xs" />

              <button @click="del(t.id)" class="rounded border px-2 py-1 text-xs hover:bg-black/5">Delete</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Later + Done -->
      <div class="space-y-3">
        <h3 class="text-sm font-semibold">Later</h3>
        <div v-if="listLater.length===0" class="text-xs text-muted-foreground">No later tasks.</div>
        <div v-for="t in listLater" :key="t.id"
             class="rounded-xl border p-3 flex items-start gap-3"
             :class="urgencyClasses[t.urgency]">
          <!-- same body as above (kept brief) -->
          <div class="flex-1">
            <div class="flex items-center gap-2">
              <div class="font-medium">{{ t.title }}</div>
              <span class="text-[10px] px-1.5 py-0.5 rounded border">{{ t.urgency }}</span>
            </div>
            <div class="text-xs opacity-80">
              <span v-if="t.due_at">Due {{ dayjs(t.due_at).format('MMM D, HH:mm') }}</span>
              <span v-if="t.assignee"> · @{{ t.assignee.name }}</span>
            </div>
          </div>
        </div>

        <h3 class="text-sm font-semibold mt-6">Done</h3>
        <div v-if="listDone.length===0" class="text-xs text-muted-foreground">No completed tasks yet.</div>
        <div v-for="t in listDone" :key="t.id"
             class="rounded-xl border p-3 flex items-start gap-3 opacity-70 line-through"
             :class="urgencyClasses[t.urgency]">
          <div class="flex-1">
            <div class="font-medium">{{ t.title }}</div>
            <div class="text-xs opacity-80">
              <span v-if="t.assignee">@{{ t.assignee.name }}</span>
            </div>
          </div>
        </div>
      </div>
    </div> <!-- /content -->
  </div>
</template>
