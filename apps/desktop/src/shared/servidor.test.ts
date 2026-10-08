import { describe, expect, it } from 'vitest'
import { nombrePorServidor, normalizarApi } from './servidor'

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

describe('datos locales por plataforma', () => {
  const compilada = 'http://localhost:8000/api/v1'

  it('con la dirección compilada, los archivos de siempre; con otra, uno propio por host', () => {
    expect(nombrePorServidor('clt4bp.db', compilada, compilada)).toBe('clt4bp.db')
    expect(
      nombrePorServidor(
        'clt4bp.db',
        'https://web-production-34938.up.railway.app/api/v1',
        compilada
      )
    ).toBe('clt4bp-web-production-34938.up.railway.app.db')
    expect(nombrePorServidor('sesion.bin', 'http://192.168.1.5:8000/api/v1', compilada)).toBe(
      'sesion-192.168.1.5_8000.bin'
    )
  })
})
