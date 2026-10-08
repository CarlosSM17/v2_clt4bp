/** Datos del ensayo: se leen del entorno para no guardar credenciales en el repositorio. */
export function requerida(nombre: string): string {
    const valor = process.env[nombre]
    if (!valor) throw new Error(`Falta la variable de entorno ${nombre}`)
    return valor
}

export const CURSO = (): string => requerida('E2E_CURSO')
export const TAREA = (): string => requerida('E2E_TAREA')

/** Un programa que compila y saluda, en el lenguaje del curso de ensayo. */
export function programa(): { codigo: string; salida: string } {
    return process.env.E2E_LENGUAJE === 'python'
        ? { codigo: 'print("hola e2e")\n', salida: 'hola e2e' }
        : { codigo: '#include <stdio.h>\nint main(void) {\n    printf("hola e2e\\n");\n    return 0;\n}\n', salida: 'hola e2e' }
}
