import { expect, test } from '@playwright/test'
import { CURSO, requerida } from './entorno'

// La API de la consola con el verificador CLT4BP: Laravel + Piston (soluciones) + agente (reglas).
test('el verificador responde con su semáforo', async ({ request }) => {
    const r = await request.post(`/api/v1/courses/${CURSO()}/design/verify`, {
        headers: { Authorization: `Bearer ${requerida('E2E_TOKEN')}`, Accept: 'application/json' }
    })
    expect(r.status()).toBe(200)
    const { data } = await r.json()
    expect(typeof data.errores).toBe('number')
    expect(typeof data.semaforo).toBe('object')
})

test('sin token la API responde 401', async ({ request }) => {
    const r = await request.get('/api/v1/auth/me', { headers: { Accept: 'application/json' } })
    expect(r.status()).toBe(401)
})
