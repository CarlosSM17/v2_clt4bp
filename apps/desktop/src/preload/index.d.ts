import type { ApiConsola } from './index'

declare global {
  interface Window {
    consola: ApiConsola
  }
}

export {}
