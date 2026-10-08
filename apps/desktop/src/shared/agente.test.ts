import { describe, expect, it } from 'vitest'
import {
  accionesPara,
  aceptablesPorDefecto,
  itemAlBanco,
  PIEZAS_DEL_TEMA,
  type ItemGenerado,
  type ResultadoGeneracion
} from './agente'
import type { ElementoLocal } from './diseno'
import ejemplo from '../../../../packages/contracts/examples/diseno-curso/clase-recorridos.json'

const local = (tipo: ElementoLocal['tipo'], contenido: unknown): ElementoLocal =>
  ({
    uid: (contenido as { uid: string }).uid,
    tipo,
    padre_uid: null,
    orden: 1,
    contenido,
    estado: 'borrador',
    version: 1,
    sucio: false,
    errores: null,
    error_servidor: null
  }) as ElementoLocal

const elementos = [
  local('objetivo', ejemplo.objetivos[0]),
  local('clase', ejemplo.clases[0]),
  local('tarea', ejemplo.tareas[0])
]
const grupos = [{ clave: 'G1', nombre: 'Ruta A', nivel: 'basico' }]

describe('acciones del agente', () => {
  it('sin selección: objetivos, nueva clase, ítems, plan, resumen y preselección por grupo', () => {
    const a = accionesPara(null, elementos, grupos)
    expect(a.map((x) => x.plantilla)).toEqual([
      'objetivos',
      'clase_tareas',
      'clase_tareas',
      'items_evaluacion',
      'plan_implementacion',
      'resumen_grupo',
      'preseleccion'
    ])
    // Tema completo: la clase y sus tareas, marcadas para pedir el resto del tema al guardarlas
    expect(a[1]).toMatchObject({
      etiqueta: 'Generar tema completo',
      alcance: { objetivos: ['OB-1'], orden: 2, tema_completo: true }
    })
    expect(a[2].alcance).toEqual({ objetivos: ['OB-1'], orden: 2 })
    expect(a[6].alcance).toEqual({ grupo_clave: 'G1' })
  })

  it('con una clase o una tarea seleccionada, ofrece lo que corresponde a ese nivel', () => {
    expect(accionesPara(elementos[1], elementos, grupos).map((x) => x.plantilla)).toContain(
      'diferenciacion'
    )
    // Con una clase: completar el tema pide sus siete piezas; la evaluación lleva también los objetivos de la clase
    const completar = accionesPara(elementos[1], elementos, grupos)[1]
    expect(completar.piezas?.map((p) => p.plantilla)).toEqual(PIEZAS_DEL_TEMA)
    expect(completar.piezas?.at(-1)?.alcance).toEqual({ clase_uid: 'tc1', objetivos: ['OB-1'] })
    expect(completar.piezas?.[0].alcance).toEqual({ clase_uid: 'tc1' })
    // Y las tareas que siguen a las que ya tiene la clase (T5 a T8)
    expect(
      accionesPara(elementos[1], elementos, grupos).find(
        (x) => x.plantilla === 'tareas_complementarias'
      )?.alcance
    ).toEqual({ clase_uid: 'tc1' })
    expect(accionesPara(elementos[1], elementos, []).map((x) => x.plantilla)).not.toContain(
      'diferenciacion'
    )
    const deTarea = accionesPara(elementos[2], elementos, grupos)
    expect(deTarea.find((x) => x.plantilla === 'info_procedimental')?.alcance).toEqual({
      tarea_uid: 'tc1-t1'
    })
  })

  it('no marca por defecto lo que tiene problemas bloqueantes', () => {
    const r = {
      elementos: [
        { tipo: 'tarea', contenido: { uid: 'a' } },
        { tipo: 'tarea', contenido: { uid: 'b' } }
      ],
      validaciones: [{ nombre: 'x', ok: false, bloqueante: true, detalle: '', elemento_uid: 'b' }]
    } as unknown as ResultadoGeneracion
    expect([...aceptablesPorDefecto(r)]).toEqual(['a'])
    r.validaciones.push({
      nombre: 'contratos',
      ok: false,
      bloqueante: true,
      detalle: '',
      elemento_uid: null
    })
    expect(aceptablesPorDefecto(r).size).toBe(0)
  })
})

describe('ítems de la evaluación del tema al Banco de ítems', () => {
  const base: ItemGenerado = {
    tipo: 'opcion_multiple',
    nivel: 'recall',
    objetivo: 'OB-1',
    forma: 'A',
    enunciado_md: '¿Qué tipo guarda 23.7?',
    opciones: ['int', 'char', 'double'],
    correcta: 2,
    aceptadas: [],
    salida: '',
    lineas: [],
    codigo_inicial: '',
    solucion: '',
    casos_prueba: []
  }

  it('opción múltiple con su clave por letra', () => {
    expect(itemAlBanco(base, 'cpp')).toEqual({
      tipo: 'opcion_multiple',
      nivel: 'recall',
      objetivo: 'OB-1',
      enunciado: {
        md: '¿Qué tipo guarda 23.7?',
        opciones: [
          { id: 'a', texto: 'int' },
          { id: 'b', texto: 'char' },
          { id: 'c', texto: 'double' }
        ]
      },
      clave: { correcta: 'c' }
    })
  })

  it('la predicción de salida lleva el programa en el enunciado y la salida calculada como clave', () => {
    const generado = {
      ...base,
      tipo: 'prediccion_salida' as const,
      nivel: 'comprension' as const,
      enunciado_md: 'Predice la salida:',
      codigo_inicial: 'int main() {}',
      salida: '14 7 C'
    }
    const item = itemAlBanco(generado, 'cpp') as { enunciado: { md: string }; clave: unknown }
    expect(item.enunciado.md).toBe('Predice la salida:\n\n```cpp\nint main() {}\n```')
    expect(item.clave).toEqual({ salida: '14 7 C' })
  })

  it('un problema de programación es de práctica y lleva lenguaje, solución y casos', () => {
    const casos = [{ entrada: '1', salida_esperada: '1', oculto: false }]
    const generado = {
      ...base,
      tipo: 'programacion' as const,
      nivel: 'comprension' as const,
      solucion: 'int main(){}',
      casos_prueba: casos
    }
    expect(itemAlBanco(generado, 'c')).toMatchObject({
      nivel: 'practica',
      lenguaje: 'c',
      solucion: 'int main(){}',
      casos_prueba: casos
    })
  })
})
