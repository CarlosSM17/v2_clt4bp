/**
 * Bloques del material con la forma del «Mapa de ruta CLT4BP» (ADR 0007): recuadros (analogía, ¡cuidado!…), código
 * con notas al lado de cada línea, salida en consola, mensaje del compilador y pseudocódigo PSeInt.
 *
 * Este archivo tiene un gemelo idéntico en la consola (apps/desktop/src/renderer/src/lib/bloques.ts): si cambias uno, cambia el
 * otro. Todo el texto pasa por `escapar` (viene del modelo o del instructor); DOMPurify sanea después el resultado.
 */

export type Escapar = (texto: string) => string;
export type Resaltar = (codigo: string, lenguaje: string) => string;

/** Recuadros: una cita que empieza con su emoji. El orden importa (⚠️ antes que ⚠). */
export const RECUADROS: [string, string][] = [
    ['💡', 'analogia'],
    ['❓', 'pregunta'],
    ['🎯', 'proposito'],
    ['⚠️', 'cuidado'],
    ['⚠', 'cuidado'],
    ['🙋', 'actividad'],
    ['🗺', 'resumen'],
    ['✎', 'ejemplo'],
    ['⌨', 'sintaxis'],
    ['☑', 'guia'],
    ['🐞', 'errores'],
    ['⇄', 'isomorfico'],
    ['👥', 'colaborativo'],
    ['🎙', 'protocolo'],
];

export function claseRecuadro(texto: string): string | null {
    const inicio = texto.trimStart();
    return RECUADROS.find(([emoji]) => inicio.startsWith(emoji))?.[1] ?? null;
}

/** Marca de nota al final de una línea de código: `//→ nota` en C y C++, `#→ nota` en Python. */
const NOTA: Record<string, RegExp> = {
    c: /\s*\/\/\s*→\s?(.*)$/,
    cpp: /\s*\/\/\s*→\s?(.*)$/,
    'c++': /\s*\/\/\s*→\s?(.*)$/,
    python: /\s*#\s*→\s?(.*)$/,
    py: /\s*#\s*→\s?(.*)$/,
};

export interface LineaAnotada {
    codigo: string;
    nota: string;
}

/** Separa el código de sus notas; null si ninguna línea lleva nota (entonces es un bloque de código normal). */
export function separarNotas(
    codigo: string,
    lenguaje: string,
): LineaAnotada[] | null {
    const marca = NOTA[lenguaje];
    if (!marca) return null;
    const lineas = codigo
        .replace(/\n$/, '')
        .split('\n')
        .map((linea) => {
            const m = marca.exec(linea);
            return m
                ? { codigo: linea.slice(0, m.index), nota: m[1].trim() }
                : { codigo: linea, nota: '' };
        });
    return lineas.some((l) => l.nota) ? lineas : null;
}

/** La entrada (lo que se teclea) y la salida de un bloque ```salida: «entrada: …» antes de «---». */
export function leerSalida(contenido: string): {
    entrada: string;
    salida: string;
} {
    const texto = contenido.replace(/\n$/, '');
    const partes = texto.split(/^---[ \t]*$/m);
    if (partes.length >= 2) {
        const m = /^\s*entrada:\s*(.*)$/im.exec(partes[0]);
        return {
            entrada: m ? m[1].trim().replace(/\\n/g, '\n') : '',
            salida: partes.slice(1).join('---').replace(/^\n/, ''),
        };
    }
    return { entrada: '', salida: texto };
}

const CLAVES_PSEINT =
    /\b(Algoritmo|FinAlgoritmo|Proceso|FinProceso|Definir|Como|Escribir|Leer|Si|Entonces|SiNo|FinSi|Mientras|FinMientras|Para|Hasta|Con Paso|FinPara|Hacer|Repetir|Segun|FinSegun|Entero|Real|Caracter|Cadena|Logico)\b/g;

/** El HTML de un bloque especial, o null si el bloque es de código normal. */
export function bloqueEspecial(
    lenguaje: string,
    contenido: string,
    resaltar: Resaltar,
    escapar: Escapar,
): string | null {
    if (lenguaje === 'salida') {
        const { entrada, salida } = leerSalida(contenido);
        const teclea = entrada
            ? `<pre class="consola-entrada"><span class="consola-rotulo">Entrada:</span> ${escapar(entrada)}</pre>`
            : '';
        return (
            `<div class="consola"><div class="consola-titulo">▶ Salida en consola</div>${teclea}` +
            `<pre class="consola-texto">${escapar(salida) || '(sin salida)'}</pre></div>`
        );
    }
    if (lenguaje === 'compilador') {
        return (
            `<div class="consola consola-error"><div class="consola-titulo">▶ Mensaje del compilador (g++)</div>` +
            `<pre class="consola-texto">${escapar(contenido.replace(/\n$/, ''))}</pre></div>`
        );
    }
    if (lenguaje === 'pseint') {
        const texto = escapar(contenido.replace(/\n$/, '')).replace(
            CLAVES_PSEINT,
            '<b class="pseint-clave">$1</b>',
        );
        return `<div class="pseint"><div class="pseint-titulo">PSEUDOCÓDIGO (PSEINT)</div><pre>${texto}</pre></div>`;
    }
    const lineas = separarNotas(contenido, lenguaje);
    if (!lineas) return null;
    const filas = lineas
        .map(
            (l, i) =>
                `<tr><td class="anotado-numero">${i + 1}</td>` +
                `<td class="anotado-codigo"><code>${resaltar(l.codigo, lenguaje) || ' '}</code></td>` +
                `<td class="anotado-nota">${escapar(l.nota)}</td></tr>`,
        )
        .join('');
    return `<div class="codigo-anotado"><table><tbody>${filas}</tbody></table></div>`;
}
