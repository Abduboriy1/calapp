<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, computed } from 'vue'

type CurrentWeather = {
  temperature_2m: number
  apparent_temperature: number
  is_day: number
  weather_code: number
  time: string
}

type DailyWeather = {
  time: string[]
  weather_code: number[]
  temperature_2m_max: number[]
  temperature_2m_min: number[]
  precipitation_sum: number[]
}

type Loc = { city: string; region: string; countryCode: string; lat: number; lon: number }

const loading = ref(true)
const error = ref<string | null>(null)
const loc = ref<Loc | null>(null)
const current = ref<CurrentWeather | null>(null)
const daily = ref<DailyWeather | null>(null)
const isFlipped = ref(false)

// CLOCK
const now = ref(new Date())
const tick = () => (now.value = new Date())
let timer: number | undefined

// PM or AM
const isPM = computed(() => now.value.getHours() >= 12)

// "Mon 27"
const dateLine = computed(() =>
  new Intl.DateTimeFormat(undefined, { weekday: 'short', day: '2-digit' }).format(now.value)
)

// "13:23" (24h like iOS lock screen). Change to h12 for 12-hour.
const timeMain = computed(() =>
  new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  })
    .format(now.value)
    .replace(/\u202F/g, ' ') // remove thin spaces some locales add
)

// Small seconds (optional)
const seconds = computed(() =>
  new Intl.DateTimeFormat(undefined, { second: '2-digit' }).format(now.value)
)

// WEATHER
const weatherLabel = (code: number) => {
  if ([0].includes(code)) return { label: 'Clear', icon: '☀️' }
  if ([1, 2, 3].includes(code)) return { label: 'Partly cloudy', icon: '🌤️' }
  if ([45, 48].includes(code)) return { label: 'Foggy', icon: '🌫️' }
  if ([51, 53, 55, 56, 57].includes(code)) return { label: 'Drizzle', icon: '🌦️' }
  if ([61, 63, 65, 66, 67, 80, 81, 82].includes(code)) return { label: 'Rain', icon: '🌧️' }
  if ([71, 73, 75, 77, 85, 86].includes(code)) return { label: 'Snow', icon: '❄️' }
  if ([95, 96, 99].includes(code)) return { label: 'Thunderstorm', icon: '⛈️' }
  return { label: '—', icon: '🌍' }
}

// Format daily forecast
const forecastDays = computed(() => {
  if (!daily.value) return []
  
  return daily.value.time.slice(0, 5).map((date, i) => {
    const dayDate = new Date(date)
    const isToday = dayDate.toDateString() === now.value.toDateString()
    const dayName = isToday 
      ? 'Today' 
      : new Intl.DateTimeFormat(undefined, { weekday: 'short' }).format(dayDate)
    
    return {
      day: dayName,
      date: new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(dayDate),
      weather: weatherLabel(daily.value!.weather_code[i]),
      high: Math.round(daily.value!.temperature_2m_max[i]),
      low: Math.round(daily.value!.temperature_2m_min[i]),
      precipitation: daily.value!.precipitation_sum[i]
    }
  })
})

async function reverseGeocode(lat: number, lon: number): Promise<Loc> {
  const u = `https://api.bigdatacloud.net/data/reverse-geocode-client?latitude=${lat}&longitude=${lon}&localityLanguage=en`
  const r = await fetch(u)
  const j = await r.json()
  return {
    city: j.city || j.locality || j.principalSubdivision || 'Your area',
    region: j.principalSubdivision || '',
    countryCode: j.countryCode || '',
    lat,
    lon,
  }
}

async function fetchWeather(lat: number, lon: number) {
  const url = new URL('https://api.open-meteo.com/v1/forecast')
  url.searchParams.set('latitude', String(lat))
  url.searchParams.set('longitude', String(lon))
  url.searchParams.set('current', 'temperature_2m,apparent_temperature,is_day,weather_code')
  url.searchParams.set('daily', 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum')
  url.searchParams.set('timezone', 'auto')
  url.searchParams.set('temperature_unit', 'fahrenheit')
  url.searchParams.set('wind_speed_unit', 'mph')
  url.searchParams.set('precipitation_unit', 'inch')
  url.searchParams.set('forecast_days', '7')
  
  const res = await fetch(url.toString())
  if (!res.ok) throw new Error('Weather fetch failed')
  const data = await res.json()
  
  current.value = {
    temperature_2m: data.current.temperature_2m,
    apparent_temperature: data.current.apparent_temperature,
    is_day: data.current.is_day,
    weather_code: data.current.weather_code,
    time: data.current.time,
  }
  
  daily.value = {
    time: data.daily.time,
    weather_code: data.daily.weather_code,
    temperature_2m_max: data.daily.temperature_2m_max,
    temperature_2m_min: data.daily.temperature_2m_min,
    precipitation_sum: data.daily.precipitation_sum
  }
}

function getLocationAndLoad() {
  loading.value = true
  error.value = null
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const { latitude: lat, longitude: lon } = pos.coords
      reverseGeocode(lat, lon)
        .then((l) => (loc.value = l))
        .then(() => fetchWeather(lat, lon))
        .catch((e) => (error.value = e?.message || 'Failed to load weather'))
        .finally(() => (loading.value = false))
    },
    (err) => {
      error.value = err?.message || 'Location permission denied'
      loading.value = false
    },
    { maximumAge: 300_000, timeout: 15_000 }
  )
}

onMounted(() => {
  getLocationAndLoad()
  tick()
  timer = window.setInterval(tick, 1000)
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})

const refresh = () => getLocationAndLoad()

const handleCardClick = (event: MouseEvent) => {
  // Don't flip if clicking the refresh button
  const target = event.target as HTMLElement
  if (target.closest('button')) return
  
  isFlipped.value = !isFlipped.value
}
</script>

<template>
  <div class="flip-container h-full w-full overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
    <div 
      class="flip-card h-full w-full cursor-pointer"
      :class="{ flipped: isFlipped }"
      @click="handleCardClick"
    >
      <!-- FRONT SIDE (original design) -->
      <div class="flip-card-front relative h-full w-full">
        <!-- dreamy gradient -->
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-br from-indigo-500/20 via-fuchsia-500/15 to-emerald-500/20" />

        <!-- TOP: date + "no events" like iPhone -->
        <div class="relative px-5 pt-4 flex items-center justify-between text-[13px] font-medium">
          <div class="flex items-center gap-2 text-pink-300/90">
            <span>{{ dateLine }}</span>
            <span class="inline-flex items-center gap-1 rounded-md bg-pink-300/15 px-2 py-0.5">
              <span class="i-lucide-calendar w-[14px] h-[14px]" aria-hidden="true">📅</span>
              <span>No events today</span>
            </span>
          </div>

          <div class="truncate text-right text-pink-300/90">
            <span v-if="loc">{{ loc.city }}<span v-if="loc.region">, {{ loc.region }}</span></span>
            <span v-else>Detecting…</span>
          </div>
        </div>

        <!-- CENTER: big lock-screen clock -->
        <div class="absolute inset-0 grid place-items-center">
          <div class="relative">
            <!-- outline layer -->
            <div class="lock-number stroke-only select-none">{{ timeMain }}</div>
            <!-- fill/glow layer -->
            <div class="lock-number fill-only select-none">{{ timeMain }}</div>

            <!-- tiny seconds, iOS-ish placement -->
            <div class="absolute -right-8 top-1/2 -translate-y-1/2 text-[18px] font-semibold text-pink-300/90 tracking-tight">
              {{ seconds }}
            </div>
          </div>
        </div>

        <!-- BOTTOM: compact weather footer -->
        <div class="absolute inset-x-0 bottom-0 px-5 pb-4">
          <div class="flex items-center justify-between">
            <div v-if="current" class="flex items-center gap-2 text-xs">
              <span class="text-5xl leading-none font-semibold text-foreground/90">
                {{ Math.round(current.temperature_2m) }}°
              </span>
              <span class="text-foreground/70">
                {{ weatherLabel(current.weather_code).label }} · Feels {{ Math.round(current.apparent_temperature) }}°
              </span>
            </div>
            <div v-else class="text-xs text-muted-foreground">
              {{ error ? 'Check location permissions' : 'Loading weather…' }}
            </div>

            <button
              class="rounded-lg border px-2 py-1 text-xs text-foreground/80 hover:bg-white/40 dark:hover:bg-white/5 cursor-pointer"
              @click="refresh"
              :disabled="loading"
              title="Refresh"
            >
              ↻
            </button>
          </div>
        </div>

        <!-- Loading overlay -->
        <div v-if="loading" class="absolute inset-0 grid place-items-center bg-background/30 backdrop-blur-sm">
          <div class="animate-pulse rounded-xl border px-4 py-2 text-xs text-muted-foreground">Fetching weather…</div>
        </div>
      </div>

      <!-- BACK SIDE (forecast table) -->
      <div class="flip-card-back relative h-full w-full">
        <!-- dreamy gradient -->
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-br from-emerald-500/20 via-indigo-500/15 to-fuchsia-500/20" />

        <!-- Header -->
        <div class="relative px-5 pt-4 pb-2 flex items-center justify-between text-[13px] font-medium">
          <div class="text-pink-300/90">
            <span class="text-base font-semibold">7-Day Forecast</span>
          </div>
          <div class="truncate text-right text-pink-300/90">
            <span v-if="loc">{{ loc.city }}<span v-if="loc.region">, {{ loc.region }}</span></span>
          </div>
        </div>

        <!-- Forecast Table -->
        <div class="relative px-5 py-2 h-full">
          <div v-if="daily && forecastDays.length" class="space-y-3">
            <div 
              v-for="day in forecastDays" 
              :key="day.day"
              class="flex items-center justify-between py-2 px-3 rounded-lg bg-white/10 dark:bg-white/5 backdrop-blur-sm"
            >
              <!-- Day & Date -->
              <div class="flex-1 min-w-0">
                <div class="text-sm font-medium text-foreground/90">{{ day.day }}</div>
                <div class="text-xs text-muted-foreground">{{ day.date }}</div>
              </div>

              <!-- Weather Icon & Description -->
              <div class="flex items-center gap-2 flex-1 justify-center">
                <span class="text-lg">{{ day.weather.icon }}</span>
                <span class="text-xs text-foreground/80 hidden sm:inline">{{ day.weather.label }}</span>
              </div>

              <!-- Precipitation -->
              <div class="text-xs text-muted-foreground text-center min-w-[40px]">
                <div v-if="day.precipitation > 0">💧 {{ day.precipitation.toFixed(1) }}"</div>
                <div v-else class="opacity-50">—</div>
              </div>

              <!-- Temperature Range -->
              <div class="flex items-center gap-2 text-right min-w-[60px]">
                <span class="text-sm font-semibold text-foreground/90">{{ day.high }}°</span>
                <span class="text-sm text-muted-foreground">{{ day.low }}°</span>
              </div>
            </div>
          </div>
          
          <div v-else class="flex items-center justify-center h-32 text-muted-foreground text-sm">
            {{ error ? 'Unable to load forecast' : 'Loading forecast...' }}
          </div>
        </div>

        <!-- Footer with current weather and refresh button -->
        <div class="absolute inset-x-0 bottom-0 px-5 pb-4">
          <div class="flex items-center justify-between bg-white/10 dark:bg-white/5 rounded-lg px-3 py-2 backdrop-blur-sm">
            <div v-if="current" class="flex items-center gap-3 text-xs">
              <span class="text-2xl font-semibold text-foreground/90">{{ Math.round(current.temperature_2m) }}°</span>
              <div>
                <div class="text-foreground/80">{{ weatherLabel(current.weather_code).label }}</div>
                <div class="text-muted-foreground">Feels {{ Math.round(current.apparent_temperature) }}°</div>
              </div>
            </div>
            
            <button
              class="rounded-lg border px-2 py-1 text-xs text-foreground/80 hover:bg-white/40 dark:hover:bg-white/5 cursor-pointer"
              @click="refresh"
              :disabled="loading"
              title="Refresh"
            >
              ↻
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Use a free, iOS-adjacent rounded font.
   Add this @import once in your global CSS if you prefer. */
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap');

.lock-number{
  font-family: 'Outfit', ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial, 'Noto Sans', 'Apple Color Emoji', 'Segoe UI Emoji';
  font-size: clamp(64px, 14vw, 148px);
  letter-spacing: -0.02em;
  line-height: 0.9;
  text-align: center;
  --pink: #ff8bd6;
  --pink-deep: #ff58c6;
}

/* iPhone-like outline + inner fill trick */
.lock-number.stroke-only{
  color: transparent;
  -webkit-text-stroke: clamp(4px, 0.7vw, 6px) var(--pink);
  filter: drop-shadow(0 0 10px rgba(255, 136, 214, 0.25));
}
.lock-number.fill-only{
  position: absolute;
  inset: 0;
  color: var(--pink);
  text-shadow:
    0 0 24px rgba(255, 136, 214, 0.25),
    0 0 2px rgba(255, 136, 214, 0.35);
  mix-blend-mode: screen; /* subtle glow */
  pointer-events: none;
}

/* Flip animation styles */
.flip-container {
  perspective: 1000px;
}

.flip-card {
  position: relative;
  transform-style: preserve-3d;
  transition: transform 0.6s cubic-bezier(0.4, 0.0, 0.2, 1);
}

.flip-card.flipped {
  transform: rotateY(180deg);
}

.flip-card-front,
.flip-card-back {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  backface-visibility: hidden;
  border-radius: inherit;
}

.flip-card-back {
  transform: rotateY(180deg);
}
</style>