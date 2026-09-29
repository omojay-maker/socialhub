import { ref, computed } from 'vue'

const KEY = 'sh_theme'
const listeners = new Set()

const state = ref(readInitial())

function readInitial() {
  const saved = localStorage.getItem(KEY)
  if (saved === 'light' || saved === 'dark') return saved
  return 'dark'
}

function apply(theme) {
  document.documentElement.setAttribute('data-theme', theme)
  const meta = document.querySelector('meta[name="theme-color"]')
  if (meta) meta.setAttribute('content', theme === 'dark' ? '#0e0a1c' : '#f4f2ff')
}

apply(state.value)

export function useTheme() {
  return {
    theme: state,
    isDark: computed(() => state.value === 'dark'),
    toggle() {
      state.value = state.value === 'dark' ? 'light' : 'dark'
      localStorage.setItem(KEY, state.value)
      apply(state.value)
      listeners.forEach((fn) => fn(state.value))
    },
    onChange(fn) {
      listeners.add(fn)
      return () => listeners.delete(fn)
    },
    cssVar(name) {
      return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
    }
  }
}
