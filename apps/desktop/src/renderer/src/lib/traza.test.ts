import { describe, expect, it } from 'vitest'
import { destinoDe, leerTraza, type MarcoMemoria, type Traza } from './traza'

const TRAZA = `titulo: Suma de n números
entrada: 3 10 20 30
---
#include <stdio.h>
int main(void) {
    int n, x, s = 0;
    for (int i = 0; i < n; i++) { s += x; }
    printf("%d\\n", s);
}
---
3 | s=0 | | s empieza en cero
4 | i=0, x=10, s=10 | | primera vuelta
5 | s=60 | 60\\n | se imprime el total | y termina`

describe('leerTraza', () => {
  it('lee encabezado, código y pasos', () => {
    const t = leerTraza(TRAZA) as Traza
    expect(t.titulo).toBe('Suma de n números')
    expect(t.entrada).toBe('3 10 20 30')
    expect(t.codigo).toHaveLength(6)
    expect(t.codigo[4]).toBe('    printf("%d\\n", s);') // el código queda tal cual
    expect(t.pasos.map((p) => p.linea)).toEqual([3, 4, 5])
    expect(t.pasos[1].variables).toEqual([
      ['i', '0'],
      ['x', '10'],
      ['s', '10']
    ])
    expect(t.pasos[2].salida).toBe('60\n')
    expect(t.pasos[2].nota).toBe('se imprime el total | y termina')
  })

  it('lee los pasos calculados por el trazador, sus avisos y el fin', () => {
    const calculada = [
      'titulo: Promedio',
      'lenguaje: cpp',
      'aviso: el programa terminó con la señal SIGSEGV',
      '---',
      'int f(int *a) {',
      '  return a[0];',
      '}',
      '---',
      '{"l":2,"f":"f","v":[["a","0x10 → {10, 20}"]],"s":""}',
      '{"l":2,"f":"f","v":[],"s":"10\\n","fin":true}'
    ].join('\n')
    const t = leerTraza(calculada) as Traza
    expect(t.avisos).toEqual(['el programa terminó con la señal SIGSEGV'])
    expect(t.pasos[0]).toEqual({
      linea: 2,
      variables: [['a', '0x10 → {10, 20}']],
      salida: '',
      nota: 'Dentro de f().',
      fin: false,
      memoria: [] // sin «m» (trazas de Python o anteriores): sin diagrama de memoria
    })
    expect(t.pasos[1]).toMatchObject({ salida: '10\n', fin: true, nota: 'Fin del programa.' })
  })

  it('sin pasos calculados, dice cómo obtenerlos', () => {
    expect(leerTraza('titulo: x\n---\nint main(void) {}')).toMatch(/Calcular pasos/)
  })

  it('explica qué está mal en vez de fallar', () => {
    expect(leerTraza('5 | i=0')).toMatch(/separados por «---»/)
    expect(leerTraza(TRAZA.replace('5 | s=60', '99 | s=60'))).toMatch(/Paso inválido: «99 \| s=60/)
    expect(leerTraza(TRAZA.split('3 | s=0')[0])).toMatch(/no tiene pasos/)
  })
})

describe('destinoDe', () => {
  // main tiene arr (3 int desde 1000) y x (en 2000); la función actual recibió un puntero
  const memoria: MarcoMemoria[] = [
    {
      f: 'main',
      v: [
        { n: 'arr', d: 1000, t: 'arreglo', tam: 4, e: ['10', '20', '30'] },
        { n: 'x', d: 2000, t: 'valor', x: '5' }
      ]
    },
    { f: 'promedio', v: [{ n: 'p', d: 3000, t: 'puntero', a: 1004 }] }
  ]

  it('resuelve elementos de arreglo y variables, en el mismo marco o en otro', () => {
    expect(destinoDe(memoria, 1, 1004)).toEqual({ clave: '0/arr/1', texto: 'main · arr[1]' })
    expect(destinoDe(memoria, 0, 1000)).toEqual({ clave: '0/arr/0', texto: 'arr[0]' })
    expect(destinoDe(memoria, 1, 2000)).toEqual({ clave: '0/x', texto: 'main · x' })
  })

  it('NULL, direcciones fuera de las variables y a mitad de un elemento', () => {
    expect(destinoDe(memoria, 1, 0)?.texto).toBe('NULL')
    expect(destinoDe(memoria, 1, 9999)).toBeNull()
    expect(destinoDe(memoria, 1, 1002)).toBeNull() // no cae en el inicio de un int
    expect(destinoDe(memoria, 1, 1012)).toBeNull() // justo después del arreglo
  })
})
