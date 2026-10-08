import { describe, expect, it } from 'vitest'
import { colorCelda, errorDe, formatoP, magnitud, sinError } from './evaluacion'

describe('presentación de resultados', () => {
  it('formatea p como en APA', () => {
    expect(formatoP(0.0283)).toBe('.028')
    expect(formatoP(0.0004)).toBe('< .001')
    expect(formatoP(null)).toBe('--')
  })

  it('clasifica el tamaño del efecto sin importar el signo', () => {
    expect(magnitud(0.1)).toBe('trivial')
    expect(magnitud(-0.41)).toBe('pequeño')
    expect(magnitud(0.6)).toBe('mediano')
    expect(magnitud(1.28)).toBe('grande')
  })

  it('separa un resultado de un error del servicio', () => {
    expect(sinError({ error: 'no respondió' })).toBeNull()
    expect(errorDe({ error: 'no respondió' })).toBe('no respondió')
    expect(sinError({ n: 3 })).toEqual({ n: 3 })
    expect(errorDe({ n: 3 })).toBeNull()
  })

  it('colorea el mapa de calor por desempeño', () => {
    expect(colorCelda(undefined, 'convencional')).toBe('bg-slate-100')
    expect(colorCelda({ estado: 'completada', fraccion: 1, intentos: 2, esfuerzo: 4 }, 'convencional')).toBe('bg-emerald-500')
    expect(colorCelda({ estado: 'en_progreso', fraccion: 0.25, intentos: 3, esfuerzo: 8 }, 'por_completar')).toBe('bg-rose-400')
    expect(colorCelda({ estado: 'completada', fraccion: null, intentos: 0, esfuerzo: 3 }, 'ejemplo_resuelto')).toBe('bg-sky-300')
  })
})
