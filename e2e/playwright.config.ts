import { defineConfig, devices } from '@playwright/test'

/**
 * Pruebas de extremo a extremo contra un servidor real (el de producción ANTES del piloto, o tu entorno local).
 * Variables: E2E_URL, E2E_CURSO, E2E_TAREA, E2E_PASSWORD, E2E_TOKEN (y, opcional, E2E_LENGUAJE=c|python).
 */
export default defineConfig({
  testDir: './pruebas',
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  retries: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.E2E_URL ?? 'http://localhost:8000',
    locale: 'es-MX',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure'
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }]
})
