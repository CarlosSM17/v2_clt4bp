"""Orquestador: genera → convierte → valida → corrige (hasta max_intentos) → entrega."""

import time
from typing import Any

import httpx

from app.conocimiento import VERSION, bloques_sistema
from app.config import Ajustes
from app.ejecutor import Ejecutor
from app.plantillas import PLANTILLAS, mensaje_usuario, tema_clase
from app.plantillas import modelos as m
from app.proveedor.base import ProveedorLLM, ProveedorNoDisponible, Respuesta, SalidaInvalida, Uso
from app.solicitud import ElementoPropuesto, ResultadoGeneracion, SolicitudGeneracion, UsoRegistro, Validacion
from app.etapas import (
    agregar_trazas_ejemplos, alinear_nivel, asignar_rutas, calcular_salidas, codigo_dado, marcar_huecos, procedimental_del_tema,
    getline_para_texto, limpiar_items, programas_completos, reparar_codigo,
    repite_existente, secciones_del_tema, subsolicitud, subsolicitudes_clase, tareas_distintas,
)
from app.bloques_md import asegurar_salidas_elementos, completar_bloques, ejecutable
from app.multimedia import diagrama_por_omision, normalizar_markdown
from app.trazador import ClienteTrazador
from app.validacion import no_es_programa, validar


class PlantillaDesconocida(ValueError):
    pass


def retroalimentacion(problemas: list[Validacion], elementos: list[ElementoPropuesto]) -> str:
    """Los uid finales los pone el orquestador; al modelo se le habla por títulos."""
    titulos = {e.contenido.get("uid"): e.contenido.get("titulo") or e.contenido.get("codigo") for e in elementos}
    lineas = [f"- {titulos.get(p.elemento_uid) or p.elemento_uid or 'General'}: {p.detalle}" for p in problemas]
    return (
        "Tu propuesta tiene estos problemas. Corrígelos y devuelve la propuesta completa con el mismo esquema:\n"
        + "\n".join(lineas)
    )


class Orquestador:
    def __init__(self, proveedor: ProveedorLLM, ejecutor: Ejecutor, ajustes: Ajustes, trazador: ClienteTrazador | None = None) -> None:
        self.proveedor, self.ejecutor, self.ajustes, self.trazador = proveedor, ejecutor, ajustes, trazador

    async def completar_trazas(self, elementos: list[ElementoPropuesto], lenguaje: str) -> list[Validacion]:
        """Sustituye los pasos de cada traza por los de la ejecución real. Si una traza no se puede ejecutar (no
        compila, falla), es un problema bloqueante: el modelo la corrige en el siguiente intento."""
        if self.trazador is None:
            return []
        problemas = []
        for e in elementos:
            for campo in ("cuerpo_md", "enunciado_md"):
                md = e.contenido.get(campo)
                if not isinstance(md, str) or "```traza" not in md:
                    continue
                try:
                    e.contenido[campo], errores = await self.trazador.completar_md(md, lenguaje)
                except ProveedorNoDisponible:
                    return problemas  # sin trazador, las trazas quedan sin calcular (la validación lo avisa)
                titulo = e.contenido.get("titulo") or e.contenido.get("uid")
                problemas += [
                    Validacion(nombre="traza_ejecutable", ok=False, elemento_uid=e.contenido.get("uid"),
                               detalle=f"{titulo}: la traza no se pudo ejecutar ({error}). Corrige su código o su entrada.")
                    for error in errores
                ]
        return problemas

    async def completar_bloques(self, elementos: list[ElementoPropuesto], lenguaje: str,
                                quitar_si_falla: bool = False) -> list[Validacion]:
        """Llena las salidas (```salida) y los mensajes del compilador (```compilador) ejecutando el código en Piston
        (app/bloques_md.py). Lo que no se puede llenar bloquea; tras el último intento se quita con aviso."""
        if self.ejecutor is None:
            return []
        problemas = []
        for e in elementos:
            for campo in ("cuerpo_md", "enunciado_md"):
                md = e.contenido.get(campo)
                if not isinstance(md, str) or ("```salida" not in md and "```compilador" not in md):
                    continue
                base = e.contenido.get("solucion") if e.tipo == "tarea" else None
                try:
                    e.contenido[campo], errores = await completar_bloques(md, lenguaje, self.ejecutor, quitar_si_falla, base)
                except httpx.HTTPError as error:
                    return problemas + [Validacion(nombre="bloque_ejecutable", ok=False, bloqueante=False,
                                                   detalle=f"No se pudo ejecutar el código de las salidas ({error.__class__.__name__}).")]
                titulo = e.contenido.get("titulo") or e.contenido.get("uid")
                problemas += [
                    Validacion(nombre="bloque_quitado" if quitar_si_falla else "bloque_ejecutable", ok=False,
                               bloqueante=not quitar_si_falla, elemento_uid=e.contenido.get("uid"),
                               detalle=f"{titulo}: {error}" + (" (el bloque se quitó)." if quitar_si_falla else "."))
                    for error in errores
                ]
        return problemas

    async def quitar_trazas_rotas(self, elementos: list[ElementoPropuesto], lenguaje: str) -> list[Validacion]:
        """Tras el último intento, una traza que no compila queda como código sin reproductor (aviso, no bloqueo):
        en una ayuda de «Programación I», `qwen3:4b` no la corrigió en tres intentos y bloqueaba toda la ayuda."""
        if self.trazador is None:
            return []
        avisos = []
        for e in elementos:
            for campo in ("cuerpo_md", "enunciado_md"):
                md = e.contenido.get(campo)
                if not isinstance(md, str) or "```traza" not in md:
                    continue
                try:
                    e.contenido[campo], errores = await self.trazador.completar_md(md, lenguaje, sin_traza_si_falla=True)
                except ProveedorNoDisponible:
                    return avisos
                titulo = e.contenido.get("titulo") or e.contenido.get("uid")
                avisos += [
                    Validacion(nombre="traza_quitada", ok=False, bloqueante=False, elemento_uid=e.contenido.get("uid"),
                               detalle=f"{titulo}: la traza no se pudo ejecutar ({error[:200]}) y quedó como código, sin "
                               "reproductor. Corrígela en la consola y usa «Calcular pasos».")
                    for error in errores
                ]
        return avisos

    async def programa_aparte(self, sol: SolicitudGeneracion, elementos: list[ElementoPropuesto],
                              validaciones: list[Validacion], modelo: str, uso: Uso) -> list[Validacion]:
        """`qwen3:4b` escribe los segmentos del protocolo (o el planteamiento del ejemplo isomórfico) pero no su programa,
        ni con la retroalimentación (corridas del 2026-10-06, tres intentos cada una). Se le pide aparte, con un esquema de
        dos campos, y se agrega al final con su salida calculada; luego se revisa de nuevo como pieza del tema (p. ej., que
        no sea la solución de una tarea). Si no compila, sigue bloqueado."""
        faltan = {v.elemento_uid for v in validaciones if v.nombre == "procedimental_completo" and "programa del" in v.detalle}
        for e in elementos:
            md = e.contenido.get("cuerpo_md") or ""
            if e.contenido.get("uid") not in faltan or self.ejecutor is None:
                continue
            protocolo = e.contenido.get("tipo") == "protocolo_verbal"
            pedido = (f"Este es «{e.contenido.get('titulo')}», "
                      + ("el guion de un protocolo verbal" if protocolo else "un ejemplo isomórfico")
                      + f" de un curso de {sol.curso.lenguaje}:\n\n{md}\n\nEscribe el programa completo en {sol.curso.lenguaje} "
                      "que resuelve su problema siguiendo el texto, y los datos de entrada con los que se ejecuta.")
            try:
                r = await self.proveedor.generar(list(bloques_sistema()), [{"role": "user", "content": pedido}], m.ProgramaExperto,
                                                 modelo, 3000)
            except (SalidaInvalida, ProveedorNoDisponible):
                continue
            uso.sumar(r.uso)
            lenguaje = sol.curso.lenguaje
            etiqueta = "Programa del experto" if protocolo else "Programa del ejemplo"
            if no_es_programa(r.objeto.programa, lenguaje, ""):
                continue  # devolvió el título o una explicación (2026-10-06), no un programa
            programa = ejecutable(lenguaje, r.objeto.programa.strip())
            # El programa y su salida se completan aparte: así una cerca mal cerrada del texto no los mezcla
            agregado = (f"**{etiqueta}:**\n\n```{lenguaje}\n{programa.strip()}\n```\n\n```salida\n"
                        + (f"entrada: {r.objeto.entrada.strip()}\n---\n" if r.objeto.entrada.strip() else "") + "```\n")
            agregado, errores = await completar_bloques(agregado, lenguaje, self.ejecutor)
            if errores:
                continue
            e.contenido["cuerpo_md"] = f"{md.rstrip()}\n\n{agregado}"
            uid = e.contenido["uid"]
            validaciones = [v for v in validaciones if not (v.nombre in ("procedimental_completo", "ejemplo_distinto") and v.elemento_uid == uid)]
            validaciones += [v for v in procedimental_del_tema(sol, [e]) if v.elemento_uid == uid]
            validaciones.append(Validacion(nombre="programa_aparte", ok=False, bloqueante=False, elemento_uid=uid,
                                           detalle=f"{e.contenido.get('titulo')}: el programa se pidió aparte; revisa que siga el texto."))
        return validaciones

    async def complemento(self, sub: SolicitudGeneracion, advertencias: list[str]) -> ResultadoGeneracion | None:
        """Una subsolicitud (T5 a T8, o el soporte de la clase completa): si el proveedor falla, la propuesta principal
        se entrega igual, con aviso; lo que falta se pide después desde el asistente."""
        try:
            return await self.generar(sub)
        except ProveedorNoDisponible as e:
            que = PLANTILLAS[sub.plantilla].titulo
            advertencias.append(f"No se pudo generar «{que}» ({e}): pídelo desde el asistente con la clase seleccionada.")
            return None

    async def generar(self, sol: SolicitudGeneracion) -> ResultadoGeneracion:
        plantilla = PLANTILLAS.get(sol.plantilla)
        if plantilla is None:
            raise PlantillaDesconocida(f"No existe la plantilla «{sol.plantilla}».")

        inicio = time.monotonic()
        modelo = self.ajustes.modelo(plantilla.nivel_modelo, sol.calidad)
        original = {"role": "user", "content": mensaje_usuario(plantilla, sol)}
        mensajes: list[dict[str, Any]] = [original]
        uso, usado = Uso(), modelo
        elementos: list[ElementoPropuesto] = []
        notas: dict[str, Any] = {}
        advertencias: list[str] = []
        validaciones: list[Validacion] = []
        # Cada corrección lleva solo la solicitud, la última respuesta válida y sus problemas: la conversación completa
        # llenaría el contexto de un modelo local al tercer intento (y con Claude cuesta tokens sin ayudar)
        ultima: tuple[str, str] | None = None

        intento = 0
        for intento in range(1, self.ajustes.max_intentos + 1):
            try:
                r: Respuesta = await self.proveedor.generar(
                    list(bloques_sistema()), mensajes, plantilla.salida, modelo, plantilla.max_tokens
                )
            except SalidaInvalida as e:
                uso.sumar(e.uso)
                # Los elementos del intento anterior se conservan: también sus validaciones, para no mostrarlos «limpios»
                validaciones = [v for v in validaciones if v.nombre != "esquema"] + [Validacion(nombre="esquema", ok=False, detalle=e.detalle)]
                if e.cortada:
                    breve = ("Tu intento anterior se cortó por longitud: responde completo, pero más conciso (enunciados y "
                             "justificaciones breves, y solo el número mínimo de elementos que pide la tarea).")
                    mensajes = ([original, {"role": "assistant", "content": ultima[0]}, {"role": "user", "content": f"{ultima[1]}\n\n{breve}"}]
                                if ultima else [{"role": "user", "content": f"{original['content']}\n\n{breve}"}])
                else:
                    mensajes = [original, {"role": "assistant", "content": e.texto or "(sin respuesta)"},
                                {"role": "user", "content": f"Tu respuesta no cumple el esquema: {e.detalle}. Devuélvela completa y corregida."}]
                continue

            uso.sumar(r.uso)
            usado = r.modelo or modelo
            elementos, notas = plantilla.convertir(r.objeto, sol)
            normalizar_markdown(elementos)
            reparar_codigo(elementos)
            asegurar_salidas_elementos(elementos)
            advertencias = list(getattr(r.objeto, "advertencias", []))
            # Las salidas esperadas salen de ejecutar la solución, no de lo que el modelo calculó de cabeza
            # Desde el segundo intento, un caso con el que la solución truena se quita (con aviso) en vez de bloquear
            nivel = alinear_nivel(elementos)  # antes de calcular salidas y huecos, que dependen del nivel
            programas_completos(elementos, sol.curso.lenguaje)
            codigo_dado(elementos)
            texto = getline_para_texto(elementos, sol.curso.lenguaje)
            salidas = nivel + texto + (await calcular_salidas(elementos, sol.curso.lenguaje, self.ejecutor, quitar_fallidos=intento >= 2)
                       if plantilla.probar_codigo else [])
            salidas += marcar_huecos(elementos, sol.curso.lenguaje)
            if isinstance(notas.get("items"), list):
                salidas += limpiar_items(notas["items"], sol.curso.lenguaje)
            if plantilla.clave == "clase_tareas":
                salidas += repite_existente(sol, elementos, tema_clase(sol)) + tareas_distintas(elementos)
            elif plantilla.clave == "tareas_complementarias":
                previas = [t.model_dump(mode="json") for t in sol.diseno.tareas if t.clase_uid == sol.alcance.get("clase_uid")]
                salidas += tareas_distintas(elementos, previas)
                salidas += asignar_rutas([ElementoPropuesto(tipo="tarea", contenido=dict(t)) for t in previas] + elementos, sol.grupos)
            elif plantilla.clave in ("info_procedimental", "guion_protocolo"):
                salidas += procedimental_del_tema(sol, elementos)
            salidas += secciones_del_tema(plantilla.clave, elementos)
            if intento >= 2:  # el modelo tuvo su oportunidad de poner su propio diagrama
                salidas += diagrama_por_omision(elementos)
            trazas = await self.completar_trazas(elementos, sol.curso.lenguaje)
            trazas += await self.completar_bloques(elementos, sol.curso.lenguaje)
            # Al generar, una clase sin soporte todavía no es un problema: el tema llega pieza por pieza (ADR 0007) y
            # el soporte puede venir después; la regla sí se aplica al aprobar y al publicar
            omitir = frozenset({"clase_sin_soporte"})
            validaciones = salidas + trazas + await validar(sol, elementos, notas, plantilla.probar_codigo, self.ejecutor, omitir)
            bloqueantes = [v for v in validaciones if not v.ok and v.bloqueante]
            if not bloqueantes:
                break
            ultima = (r.texto, retroalimentacion(bloqueantes, elementos))
            mensajes = [original, {"role": "assistant", "content": ultima[0]}, {"role": "user", "content": ultima[1]}]

        if any(not v.ok and v.bloqueante and v.nombre in ("traza_ejecutable", "bloque_ejecutable") for v in validaciones):
            # Tras el último intento, ni pasos ni salidas que inventó el modelo: las trazas que no corren quedan como
            # código y los bloques de salida que no se pueden calcular se quitan, aunque haya otros problemas pendientes
            quitadas = await self.quitar_trazas_rotas(elementos, sol.curso.lenguaje)
            quitadas += await self.completar_bloques(elementos, sol.curso.lenguaje, quitar_si_falla=True)
            sin_traza = {v.elemento_uid for v in quitadas}
            validaciones = [v for v in validaciones if v.nombre not in ("traza_ejecutable", "bloque_ejecutable")
                            and not (v.nombre == "traza_calculada" and v.elemento_uid in sin_traza)] + quitadas
        if plantilla.clave in ("guion_protocolo", "info_procedimental"):
            validaciones = await self.programa_aparte(sol, elementos, validaciones, modelo, uso)
        pendientes = [v for v in validaciones if not v.ok and v.bloqueante]
        if pendientes:
            advertencias.append(f"Quedaron {len(pendientes)} problemas sin resolver después de {intento} intentos: revísalos antes de aceptar.")

        if plantilla.clave == "clase_tareas" and elementos:
            # T5–T8 en una segunda solicitud, dentro de la misma propuesta; luego las rutas de las ocho tareas
            if self.ajustes.tareas_complementarias:
                if time.monotonic() - inicio > self.ajustes.presupuesto_clase_s:
                    advertencias.append("No dio tiempo de generar las tareas T5 a T8: pídelas desde el asistente con la clase seleccionada.")
                elif (sub := subsolicitud(sol, elementos, "tareas_complementarias")) is not None and (r2 := await self.complemento(sub, advertencias)):
                    elementos += r2.elementos
                    validaciones += [v for v in r2.validaciones if not (v.ok and v.nombre == "validaciones") and v.nombre != "rutas"]
                    advertencias += [f"Tareas T5 a T8: {a}" for a in r2.advertencias]
                    uso.sumar(Uso(r2.uso.entrada, r2.uso.salida, r2.uso.cache_escritura, r2.uso.cache_lectura, r2.uso.costo_usd))
            validaciones += asignar_rutas(elementos, sol.grupos)
            # Cada ejemplo resuelto con su ejecución paso a paso; si no se puede trazar, es aviso (no la escribió el modelo)
            if agregar_trazas_ejemplos(elementos):
                con_traza = {e.contenido["uid"] for e in elementos if "```traza" in (e.contenido.get("enunciado_md") or "")}
                validaciones = [v for v in validaciones if not (v.nombre == "ejemplo_con_traza" and v.elemento_uid in con_traza)]
                validaciones += [v.model_copy(update={"bloqueante": False}) for v in await self.completar_trazas(elementos, sol.curso.lenguaje)]
            if self.ajustes.clase_completa:
                for sub in subsolicitudes_clase(sol, elementos):
                    if time.monotonic() - inicio > self.ajustes.presupuesto_clase_s:
                        que = "la información de soporte" if sub.plantilla == "info_soporte" else "la información procedimental del tema"
                        advertencias.append(f"No dio tiempo de generar {que}: pídela desde el asistente con la clase o la tarea seleccionada.")
                        continue
                    if not (r2 := await self.complemento(sub, advertencias)):
                        continue
                    nombre = "Soporte" if sub.plantilla == "info_soporte" else "Procedimental"
                    elementos += r2.elementos
                    validaciones += [v for v in r2.validaciones if not (v.ok and v.nombre == "validaciones")]
                    advertencias += [f"{nombre}: {a}" for a in r2.advertencias]
                    uso.sumar(Uso(r2.uso.entrada, r2.uso.salida, r2.uso.cache_escritura, r2.uso.cache_lectura, r2.uso.costo_usd))

        if not validaciones:
            validaciones = [Validacion(nombre="validaciones", ok=True, detalle="Sin problemas.")]

        return ResultadoGeneracion(
            plantilla=plantilla.clave,
            elementos=elementos,
            notas=notas,
            advertencias=advertencias,
            validaciones=validaciones,
            intentos=intento,
            uso=UsoRegistro(**vars(uso)),
            modelo=usado,
            version_prompt=VERSION,
            duracion_ms=int((time.monotonic() - inicio) * 1000),
        )
