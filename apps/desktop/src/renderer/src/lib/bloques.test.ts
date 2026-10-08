import { describe, expect, it } from 'vitest'
import { bloqueEspecial, claseRecuadro, leerSalida, separarNotas } from './bloques'

const escapar = (t: string): string =>
  t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const resaltar = (codigo: string): string => escapar(codigo)

describe('recuadros', () => {
  it('reconoce cada recuadro por su emoji', () => {
    expect(claseRecuadro('💡 Analogía · Las variables son casilleros')).toBe('analogia')
    expect(claseRecuadro('  ⚠️ ¡Cuidado! Una variable sin inicializar')).toBe('cuidado')
    expect(claseRecuadro('🙋 Actividad en el aula')).toBe('actividad')
    expect(claseRecuadro('Una cita normal')).toBeNull()
  })
})

describe('código anotado', () => {
  it('separa la nota de cada línea y deja intactas las demás', () => {
    const codigo =
      'int main()\n{\n    int edad = 17; //→ Crea la caja edad con 17\n    return 0;\n}\n'
    const lineas = separarNotas(codigo, 'cpp')!
    expect(lineas).toHaveLength(5)
    expect(lineas[2]).toEqual({ codigo: '    int edad = 17;', nota: 'Crea la caja edad con 17' })
    expect(lineas[3]).toEqual({ codigo: '    return 0;', nota: '' })
    expect(separarNotas('x = 1  #→ guarda 1', 'python')![0].nota).toBe('guarda 1')
    expect(separarNotas('int x = 1; // sin flecha', 'cpp')).toBeNull() // sin notas: bloque normal
  })

  it('arma una tabla con número, código y nota, todo escapado', () => {
    const html = bloqueEspecial('cpp', 'cout << x; //→ muestra <x>\n', resaltar, escapar)!
    expect(html).toContain('<td class="anotado-numero">1</td>')
    expect(html).toContain('<code>cout &lt;&lt; x;</code>')
    expect(html).toContain('<td class="anotado-nota">muestra &lt;x&gt;</td>')
  })
})

describe('salida, compilador y pseudocódigo', () => {
  it('lee la entrada tecleada y la salida', () => {
    expect(leerSalida('entrada: Luis\\n16 1.72\n---\nLuis tiene 16\n')).toEqual({
      entrada: 'Luis\n16 1.72',
      salida: 'Luis tiene 16'
    })
    expect(leerSalida('17\n')).toEqual({ entrada: '', salida: '17' })
    const html = bloqueEspecial('salida', 'entrada: 4\n---\n<8>\n', resaltar, escapar)!
    expect(html).toContain('▶ Salida en consola')
    expect(html).toContain('Entrada:</span> 4')
    expect(html).toContain('&lt;8&gt;')
  })

  it('muestra el mensaje del compilador y resalta las palabras de PSeInt', () => {
    expect(
      bloqueEspecial('compilador', "main.cpp:5:5: error: 'total'\n", resaltar, escapar)
    ).toContain('Mensaje del compilador (g++)')
    const pseint = bloqueEspecial(
      'pseint',
      'Algoritmo Ficha\n  Leer edad\nFinAlgoritmo\n',
      resaltar,
      escapar
    )!
    expect(pseint).toContain('<b class="pseint-clave">Algoritmo</b> Ficha')
    expect(pseint).toContain('<b class="pseint-clave">FinAlgoritmo</b>')
    expect(bloqueEspecial('cpp', 'int x;\n', resaltar, escapar)).toBeNull()
  })
})
