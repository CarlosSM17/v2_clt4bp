import { describe, expect, it } from 'vitest'
import { normalizarApi } from './servidor'

describe('dirección de la plataforma', () => {
  it('acepta la dirección con o sin /api/v1 y sin importar la barra final', () => {
    expect(normalizarApi('https://clt4bp.up.railway.app')).toBe(
      'https://clt4bp.up.railway.app/api/v1'
    )
    expect(normalizarApi(' https://clt4bp.up.railway.app/api/v1/ ')).toBe(
      'https://clt4bp.up.railway.app/api/v1'
    )
    expect(normalizarApi('http://localhost:8000')).toBe('http://localhost:8000/api/v1')
  })

  it('rechaza lo que no es una URL http(s)', () => {
    expect(normalizarApi('')).toBeNull()
    expect(normalizarApi(undefined)).toBeNull()
    expect(normalizarApi('clt4bp.up.railway.app')).toBeNull()
    expect(normalizarApi('file:///etc/passwd')).toBeNull()
  })
})
