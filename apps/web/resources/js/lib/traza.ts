/**
 * Trazas de código: visualización paso a paso de un programa (línea por ejecutar, variables y salida).
 * Mismo formato que produce el agente con el trazador (services/agent/app/trazador.py, services/trazador) y mismo
 * archivo en la consola (apps/desktop/src/renderer/src/lib/traza.ts): si cambias uno, cambia el otro.
 *
 *   titulo: Suma de un arreglo
 *   lenguaje: c
 *   entrada: 3 10 20 30
 *   aviso: se muestran los primeros 300 pasos        (opcional, lo escribe el trazador)
 *   ---
 *   <el código, tal cual>
 *   ---
 *   {"l":3,"f":"main","v":[["n","3"]],"s":""}         (un paso por línea, calculado al ejecutar el programa)
 *
 * También se leen pasos escritos a mano («línea | variables | salida | nota») de trazas anteriores.
 * El DOM se construye con textContent: el contenido nunca se interpreta como HTML.
 */

/** Una variable en el diagrama de memoria (la calcula el trazador con gdb: services/trazador/traza_gdb.py). */
export interface CeldaMemoria {
    n: string; // nombre
    d: number; // dirección
    t: 'valor' | 'arreglo' | 'puntero';
    x?: string; // valor (escalar)
    e?: string[]; // elementos (arreglo)
    tam?: number; // tamaño de un elemento (arreglo)
    a?: number; // dirección a la que apunta (puntero)
}

/** Un marco de la pila: la función y sus variables. */
export interface MarcoMemoria {
    f: string;
    v: CeldaMemoria[];
}

export interface PasoTraza {
    linea: number;
    variables: [string, string][];
    salida: string;
    nota: string;
    fin: boolean;
    memoria: MarcoMemoria[];
}

export interface Traza {
    titulo: string;
    entrada: string;
    avisos: string[];
    codigo: string[];
    pasos: PasoTraza[];
}

interface PasoCalculado {
    l: number;
    f?: string;
    v?: [string, string][];
    s?: string;
    fin?: boolean;
    m?: MarcoMemoria[];
}

function notaDe(p: PasoCalculado): string {
    if (p.fin) return 'Fin del programa.';
    return p.f && p.f !== 'main' ? `Dentro de ${p.f}().` : '';
}

export function leerTraza(texto: string): Traza | string {
    const partes = texto.split(/^\s*---\s*$/m);
    if (partes.length === 2)
        return 'Esta traza aún no tiene pasos: en la consola, «Calcular pasos» los obtiene.';
    if (partes.length !== 3)
        return 'La traza debe tener encabezado, código y pasos separados por «---».';
    const [encabezado, codigo, pasos] = partes;
    const t: Traza = {
        titulo: '',
        entrada: '',
        avisos: [],
        codigo: codigo.replace(/^\n+|\n+$/g, '').split('\n'),
        pasos: [],
    };
    for (const linea of encabezado.split('\n')) {
        const [clave, ...resto] = linea.split(':');
        const valor = resto.join(':').trim();
        const nombre = clave.trim().toLowerCase();
        if (nombre === 'titulo') t.titulo = valor;
        if (nombre === 'entrada') t.entrada = valor.replace(/\\n/g, '\n');
        if (nombre === 'aviso') t.avisos.push(valor);
    }
    for (const linea of pasos.split('\n').filter((l) => l.trim())) {
        if (linea.trim().startsWith('{')) {
            let p: PasoCalculado;
            try {
                p = JSON.parse(linea) as PasoCalculado;
            } catch {
                return `Paso inválido: «${linea.trim().slice(0, 60)}».`;
            }
            if (!Number.isInteger(p.l) || p.l < 1 || p.l > t.codigo.length)
                return `Paso fuera del código: línea ${p.l}.`;
            t.pasos.push({
                linea: p.l,
                variables: p.v ?? [],
                salida: p.s ?? '',
                nota: notaDe(p),
                fin: !!p.fin,
                memoria: p.m ?? [],
            });
            continue;
        }
        // Formato escrito a mano: línea | variables | salida | nota
        const campos = linea.split('|').map((c) => c.trim());
        const numero = Number(campos[0]);
        if (
            !Number.isInteger(numero) ||
            numero < 1 ||
            numero > t.codigo.length
        ) {
            return `Paso inválido: «${linea.trim()}».`;
        }
        const variables = (campos[1] ?? '')
            .split(',')
            .map((p) => p.trim())
            .filter(Boolean)
            .map((p): [string, string] => {
                const i = p.indexOf('=');
                return i < 0
                    ? [p, '']
                    : [p.slice(0, i).trim(), p.slice(i + 1).trim()];
            });
        t.pasos.push({
            linea: numero,
            variables,
            salida: (campos[2] ?? '').replace(/\\n/g, '\n'),
            nota: campos.slice(3).join(' | '),
            fin: false,
            memoria: [],
        });
    }
    return t.pasos.length ? t : 'La traza no tiene pasos.';
}

function nodo<K extends keyof HTMLElementTagNameMap>(
    etiqueta: K,
    clase: string,
    texto?: string,
): HTMLElementTagNameMap[K] {
    const e = document.createElement(etiqueta);
    e.className = clase;
    if (texto !== undefined) e.textContent = texto;
    return e;
}

/**
 * A qué apunta una dirección dentro de la pila: «arr[1]», «main · arr[1]» si está en otro marco, «x», «NULL», o null
 * si no es la dirección de ninguna variable visible. `clave` identifica la celda destino para resaltarla.
 */
export function destinoDe(
    memoria: MarcoMemoria[],
    marcoActual: number,
    direccion: number,
): { clave: string; texto: string } | null {
    if (direccion === 0) return { clave: '', texto: 'NULL' };
    for (let i = 0; i < memoria.length; i++) {
        const prefijo = i === marcoActual ? '' : `${memoria[i].f} · `;
        for (const c of memoria[i].v) {
            if (c.t === 'arreglo' && c.e && c.tam) {
                const k = (direccion - c.d) / c.tam;
                if (Number.isInteger(k) && k >= 0 && k < c.e.length)
                    return {
                        clave: `${i}/${c.n}/${k}`,
                        texto: `${prefijo}${c.n}[${k}]`,
                    };
            } else if (c.t !== 'arreglo' && c.d === direccion) {
                return { clave: `${i}/${c.n}`, texto: `${prefijo}${c.n}` };
            }
        }
    }
    return null;
}

const COLORES = 4;
// El trazador escribe «?» en una variable declarada sin valor inicial, hasta que el programa le asigna uno
const SIN_VALOR =
    'Todavía sin valor: se declaró sin valor inicial y el programa aún no le asigna uno.';

/** El diagrama de memoria de un paso: un recuadro por función de la pila, arreglos en celdas y punteros con color. */
function dibujarMemoria(
    caja: HTMLElement,
    paso: PasoTraza,
    previo: PasoTraza | undefined,
): void {
    caja.replaceChildren();
    caja.hidden = paso.memoria.length === 0;
    if (caja.hidden) return;
    caja.append(
        nodo(
            'p',
            'traza-rotulo',
            'Memoria (la pila: cada función con sus variables)',
        ),
    );

    // Valores del paso anterior, para resaltar lo que cambió
    const antes = new Map<string, string>();
    for (const m of previo?.memoria ?? []) {
        for (const c of m.v) {
            if (c.t === 'arreglo')
                c.e?.forEach((x, k) => antes.set(`${m.f}/${c.n}/${k}`, x));
            else
                antes.set(
                    `${m.f}/${c.n}`,
                    c.t === 'puntero' ? String(c.a) : (c.x ?? ''),
                );
        }
    }

    // Cada puntero recibe un color; su destino se resalta con el mismo color y su nombre
    const destinos = new Map<string, { color: number; nombres: string[] }>();
    const deCadaPuntero = new Map<
        CeldaMemoria,
        { texto: string; color: number }
    >();
    let siguienteColor = 0;
    paso.memoria.forEach((m, i) => {
        for (const c of m.v) {
            if (c.t !== 'puntero' || c.a === undefined) continue;
            const d = destinoDe(paso.memoria, i, c.a);
            const color = siguienteColor++ % COLORES;
            deCadaPuntero.set(c, {
                texto: d ? `→ ${d.texto}` : `→ 0x${c.a.toString(16)}`,
                color,
            });
            if (d?.clave) {
                const previoDestino = destinos.get(d.clave);
                destinos.set(d.clave, {
                    color: previoDestino?.color ?? color,
                    nombres: [...(previoDestino?.nombres ?? []), c.n],
                });
            }
        }
    });

    const cuadro = (
        texto: string,
        clave: string,
        claveCambio: string,
        indice?: string,
    ): HTMLElement => {
        const envoltura = nodo('div', 'traza-celda-envoltura');
        const destino = destinos.get(clave);
        // El rótulo de arriba (punteros que llegan) y el índice de abajo siempre ocupan su lugar: así las celdas se alinean
        envoltura.append(
            destino
                ? nodo(
                      'span',
                      `traza-apuntada traza-color-${destino.color}`,
                      destino.nombres.join(', '),
                  )
                : nodo('span', 'traza-apuntada', ' '),
        );
        const celda = nodo('div', 'traza-celda');
        celda.textContent = texto;
        if (texto === '?') celda.title = SIN_VALOR;
        if (destino)
            celda.classList.add(
                'traza-destino',
                `traza-color-${destino.color}`,
            );
        if (previo && antes.get(claveCambio) !== texto)
            celda.classList.add('traza-celda-cambio');
        envoltura.append(celda);
        envoltura.append(nodo('span', 'traza-indice', indice ?? ' '));
        return envoltura;
    };

    const pila = nodo('div', 'traza-pila');
    paso.memoria.forEach((m, i) => {
        const marco = nodo(
            'div',
            i === paso.memoria.length - 1
                ? 'traza-marco traza-marco-actual'
                : 'traza-marco',
        );
        marco.append(nodo('p', 'traza-marco-nombre', `${m.f}()`));
        for (const c of m.v) {
            const variable = nodo('div', 'traza-var');
            variable.append(nodo('span', 'traza-var-nombre', c.n));
            const celdas = nodo('div', 'traza-celdas');
            if (c.t === 'arreglo') {
                c.e?.forEach((x, k) =>
                    celdas.append(
                        cuadro(
                            x,
                            `${i}/${c.n}/${k}`,
                            `${m.f}/${c.n}/${k}`,
                            String(k),
                        ),
                    ),
                );
            } else if (c.t === 'puntero') {
                const p = deCadaPuntero.get(c);
                const celda = cuadro(
                    p?.texto ?? 'NULL',
                    `${i}/${c.n}`,
                    `${m.f}/${c.n}`,
                );
                if (p)
                    celda
                        .querySelector('.traza-celda')
                        ?.classList.add(
                            'traza-puntero',
                            `traza-color-${p.color}`,
                        );
                // Para comparar con el paso anterior cuenta la dirección, no el texto
                if (previo && antes.get(`${m.f}/${c.n}`) === String(c.a))
                    celda
                        .querySelector('.traza-celda')
                        ?.classList.remove('traza-celda-cambio');
                celdas.append(celda);
            } else {
                celdas.append(
                    cuadro(c.x ?? '', `${i}/${c.n}`, `${m.f}/${c.n}`),
                );
            }
            variable.append(celdas);
            marco.append(variable);
        }
        pila.append(marco);
    });
    caja.append(pila);
}

/** Sustituye el contenido de `destino` por el reproductor de la traza. */
export function montarTraza(destino: HTMLElement, texto: string): void {
    const t = leerTraza(texto);
    destino.replaceChildren();
    if (typeof t === 'string') {
        destino.append(nodo('p', 'traza-error', t));
        return;
    }

    const raiz = nodo('div', 'traza');
    const cabecera = nodo('div', 'traza-cabecera');
    const titulo = nodo(
        'span',
        'traza-titulo',
        t.titulo || 'Traza del programa',
    );
    const contador = nodo('span', 'traza-contador');
    const botones = nodo('span', 'traza-botones');
    const [inicio, anterior, siguiente] = (
        ['⏮ Inicio', '◀ Anterior', 'Siguiente ▶'] as const
    ).map((r) => {
        const b = nodo('button', 'traza-boton', r);
        b.type = 'button';
        botones.append(b);
        return b;
    });
    cabecera.append(titulo, contador, botones);

    const codigo = nodo('pre', 'traza-codigo');
    const lineas = t.codigo.map((texto, i) => {
        const fila = nodo('div', 'traza-linea');
        fila.append(
            nodo('span', 'traza-numero', String(i + 1)),
            nodo('span', 'traza-texto', texto || ' '),
        );
        codigo.append(fila);
        return fila;
    });

    const lado = nodo('div', 'traza-lado');
    const nota = nodo('p', 'traza-nota');
    const tabla = nodo('table', 'traza-variables');
    const salida = nodo('pre', 'traza-salida');
    lado.append(
        nota,
        nodo('p', 'traza-rotulo', 'Variables'),
        tabla,
        nodo('p', 'traza-rotulo', 'Salida'),
        salida,
    );
    if (t.entrada)
        lado.prepend(
            nodo(
                'p',
                'traza-entrada',
                `Entrada: ${t.entrada.replace(/\n/g, ' ')}`,
            ),
        );

    const cuerpo = nodo('div', 'traza-cuerpo');
    cuerpo.append(codigo, lado);
    const leyenda = nodo(
        'p',
        'traza-leyenda',
        'La línea marcada es la que se ejecutará a continuación; las variables muestran su valor en ese momento ' +
            '(«?»: todavía sin valor).',
    );
    raiz.append(cabecera, leyenda);
    for (const aviso of t.avisos)
        raiz.append(nodo('p', 'traza-aviso', `⚠ ${aviso}`));
    raiz.append(cuerpo);
    const memoria = nodo('div', 'traza-memoria');
    raiz.append(memoria);
    destino.append(raiz);

    let actual = 0;
    const mostrar = (n: number): void => {
        actual = Math.max(0, Math.min(t.pasos.length - 1, n));
        const paso = t.pasos[actual];
        const previas = new Map(
            actual > 0 ? t.pasos[actual - 1].variables : [],
        );
        // Al terminar el programa ya no hay línea por ejecutar
        lineas.forEach((fila, i) =>
            fila.classList.toggle(
                'traza-actual',
                !paso.fin && i === paso.linea - 1,
            ),
        );
        // Solo se desplaza el recuadro del código (es position: relative), nunca la página
        const fila = lineas[paso.linea - 1];
        if (fila)
            codigo.scrollTop = Math.max(
                0,
                fila.offsetTop - codigo.clientHeight / 2,
            );
        contador.textContent = `Paso ${actual + 1} de ${t.pasos.length}`;
        nota.textContent = paso.nota;
        tabla.replaceChildren();
        for (const [nombre, valor] of paso.variables) {
            const fila = nodo(
                'tr',
                previas.get(nombre) === valor ? '' : 'traza-cambio',
            );
            const celda = nodo('td', 'traza-valor', valor);
            if (valor === '?') celda.title = SIN_VALOR;
            fila.append(nodo('td', 'traza-nombre', nombre), celda);
            tabla.append(fila);
        }
        salida.textContent =
            t.pasos
                .slice(0, actual + 1)
                .map((p) => p.salida)
                .join('') || '—';
        dibujarMemoria(memoria, paso, t.pasos[actual - 1]);
        inicio.disabled = anterior.disabled = actual === 0;
        siguiente.disabled = actual === t.pasos.length - 1;
    };
    inicio.addEventListener('click', () => mostrar(0));
    anterior.addEventListener('click', () => mostrar(actual - 1));
    siguiente.addEventListener('click', () => mostrar(actual + 1));
    mostrar(0);
}
