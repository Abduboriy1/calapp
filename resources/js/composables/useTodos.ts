// resources/js/composables/useTodos.ts
import { ref, computed } from 'vue'
import axios from 'axios'

export type Todo = {
  id: number
  title: string
  description?: string | null
  urgency: 1 | 2 | 3 | 4 | 5
  status: 'todo' | 'in_progress' | 'done'
  due_at?: string | null
  assignee_id?: number | null
  assignee?: { id: number; name: string } | null
  created_at?: string
  updated_at?: string
}
export type UserOpt = { id: number; name: string }

const urgencyClasses: Record<Todo['urgency'], string> = {
  1: 'bg-emerald-200/60 text-emerald-950 border-emerald-300',
  2: 'bg-lime-200/60 text-lime-950 border-lime-300',
  3: 'bg-amber-200/60 text-amber-950 border-amber-300',
  4: 'bg-orange-200/60 text-orange-950 border-orange-300',
  5: 'bg-red-200/60 text-red-950 border-red-300',
}

export function useTodos() {
  const todos = ref<Todo[]>([])
  const users = ref<UserOpt[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const fetchAll = async () => {
    loading.value = true
    try {
      axios.defaults.withCredentials = true;
      axios.defaults.withXSRFToken = true;
      const { data } = await axios.get('/api/todos')
      todos.value = data.todos
      users.value = data.users
      error.value = null
    } catch (e: any) {
      error.value = e?.message ?? 'Failed to load todos'
    } finally {
      loading.value = false
    }
  }

  // Optimistic create/update/delete
  const create = async (payload: Partial<Todo>) => {
    // optimistic
    const tempId = -Date.now()
    const optimistic: Todo = {
      id: tempId, title: payload.title ?? 'Untitled',
      description: payload.description ?? null,
      urgency: (payload.urgency as any) ?? 3,
      status: (payload.status as any) ?? 'todo',
      due_at: payload.due_at ?? null,
      assignee_id: payload.assignee_id ?? null,
      assignee: users.value.find(u => u.id === payload.assignee_id) ?? null,
    }
    todos.value.unshift(optimistic)
    try {
      const { data } = await axios.post('/api/todos', payload)
      const idx = todos.value.findIndex(t => t.id === tempId)
      if (idx > -1) todos.value[idx] = data.todo
      return data.todo as Todo
    } catch (e) {
      // rollback
      todos.value = todos.value.filter(t => t.id !== tempId)
      throw e
    }
  }

  const update = async (id: number, payload: Partial<Todo>) => {
    const idx = todos.value.findIndex(t => t.id === id)
    if (idx === -1) return
    const before = { ...todos.value[idx] }
    todos.value[idx] = { ...before, ...payload } as Todo
    try {
      const { data } = await axios.patch(`/api/todos/${id}`, payload)
      todos.value[idx] = data.todo
      return data.todo as Todo
    } catch (e) {
      todos.value[idx] = before // rollback
      throw e
    }
  }

  const remove = async (id: number) => {
    const before = [...todos.value]
    todos.value = todos.value.filter(t => t.id !== id)
    try {
      await axios.delete(`/api/todos/${id}`)
    } catch (e) {
      todos.value = before // rollback
      throw e
    }
  }

  const today = new Date()
  const startOfDay = new Date(today.getFullYear(), today.getMonth(), today.getDate()).getTime()
  const endOfWeek = (() => {
    const d = new Date(today)
    const day = d.getDay()
    const add = (7 - day) % 7
    d.setDate(d.getDate() + add)
    d.setHours(23, 59, 59, 999)
    return d.getTime()
  })()

  const listToday = computed(() => todos.value.filter(t => t.due_at ? new Date(t.due_at).getTime() >= startOfDay && new Date(t.due_at).getTime() <= endOfWeek : false))
  const listLater = computed(() => todos.value.filter(t => !t.due_at || new Date(t.due_at).getTime() > endOfWeek))
  const listDone = computed(() => todos.value.filter(t => t.status === 'done'))

  return { todos, users, loading, error, fetchAll, create, update, remove, listToday, listLater, listDone, urgencyClasses }
}
