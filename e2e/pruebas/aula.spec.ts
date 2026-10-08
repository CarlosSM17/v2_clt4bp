import { expect, test } from '@playwright/test'
import { CURSO, TAREA, programa, requerida } from './entorno'

// Recorrido del estudiante con los tres servicios reales: Laravel, la cola y Piston.
test('un estudiante ejecuta y envía código en una tarea', async ({ page }) => {
    await page.goto('/login')
    await page.locator('#email').fill(process.env.E2E_EMAIL ?? 'ensayo-001@ensayo.invalid')
    await page.locator('#password').fill(requerida('E2E_PASSWORD'))
    await page.locator('form button[type="submit"]').click()
    await expect(page).not.toHaveURL(/\/login/)

    await page.goto(`/aula/${CURSO()}`)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    await page.goto(`/aula/${CURSO()}/tareas/${TAREA()}`)
    const { codigo, salida } = programa()
    const editor = page.locator('.cm-content')
    await editor.click()
    await page.keyboard.press('ControlOrMeta+A')
    await page.keyboard.insertText(codigo) // sin autocierre de llaves: el texto entra tal cual

    await page.getByRole('button', { name: 'Ejecutar' }).click()
    await expect(page.locator('pre.bg-black')).toContainText(salida)

    // Enviar pasa por la cola: el resultado llega cuando el trabajador califica
    await page.getByRole('button', { name: 'Enviar' }).click()
    await expect(page.getByText(/casos correctos|No compila/)).toBeVisible({ timeout: 60_000 })
})

test('la página del aula trae la política de contenido', async ({ page }) => {
    const respuesta = await page.goto('/login')
    const csp = respuesta?.headers()['content-security-policy'] ?? ''
    expect(csp).toContain("frame-ancestors 'none'")
    expect(csp).toMatch(/script-src 'self' 'nonce-/)
})
