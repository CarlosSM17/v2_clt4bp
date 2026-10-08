"""Verificador CLT4BP: reglas que revisan el diseño de un curso antes de aprobarlo.

Cada regla es una función que recibe el Contexto y produce hallazgos. Para agregar una regla,
escribe la función con el decorador @regla y una prueba en tests/test_verificador.py.
"""

import re
from collections.abc import Callable, Iterator

from .contexto import AUTONOMIA, INTERACTIVIDAD, Contexto, similitud
from .modelos import Hallazgo, Informe, Nivel, SolicitudVerificacion

Regla = Callable[[Contexto], Iterator[Hallazgo]]
REGLAS: dict[str, tuple[Nivel, str, Regla]] = {}


def regla(clave: str, nivel: Nivel, descripcion: str) -> Callable[[Regla], Regla]:
    def registrar(f: Regla) -> Regla:
        REGLAS[clave] = (nivel, descripcion, f)
        return f

    return registrar


def h(clave: str, uid: str, mensaje: str, grupo: str | None = None) -> Hallazgo:
    return Hallazgo(regla=clave, nivel=REGLAS[clave][0], elemento_uid=uid, mensaje=mensaje, grupo=grupo)


def nombre_grupo(ctx: Contexto, clave: str | None) -> str:
    if clave is None:
        return "la versión base"
    return next((g["nombre"] for g in ctx.grupos if g["clave"] == clave), clave)


# ---------- Estructura 4C/ID ----------


@regla("clase_sin_tareas", "error", "Toda clase de tareas tiene al menos una tarea.")
def clase_sin_tareas(ctx: Contexto) -> Iterator[Hallazgo]:
    for c in ctx.clases:
        if not ctx.tareas_de(c["uid"]):
            yield h("clase_sin_tareas", c["uid"], "La clase no tiene tareas.")


@regla("clase_sin_soporte", "error", "Toda clase de tareas tiene información de soporte.")
def clase_sin_soporte(ctx: Contexto) -> Iterator[Hallazgo]:
    con_soporte = {s["clase_uid"] for s in ctx.diseno["soporte"]}
    for c in ctx.clases:
        if c["uid"] not in con_soporte:
            yield h("clase_sin_soporte", c["uid"], "Falta la información de soporte («Antes de empezar») de la clase.")


@regla("referencia_rota", "error", "Todo elemento cuelga de un elemento que existe.")
def referencia_rota(ctx: Contexto) -> Iterator[Hallazgo]:
    clases = {c["uid"] for c in ctx.clases}
    tareas = {t["uid"] for t in ctx.tareas}
    for e in [*ctx.tareas, *ctx.diseno["soporte"]]:
        if e["clase_uid"] not in clases:
            yield h("referencia_rota", e["uid"], f"Apunta a la clase «{e['clase_uid']}», que no existe.")
    # Una ayuda cuelga de una tarea o, si es del tema (tarjeta, guía, errores, ejemplo, protocolo), de su clase
    for p in ctx.diseno["procedimental"]:
        tarea, clase = p.get("tarea_uid"), p.get("clase_uid")
        if bool(tarea) == bool(clase):
            yield h("referencia_rota", p["uid"], "La ayuda debe pertenecer a una tarea o a una clase (exactamente a una).")
        elif tarea and tarea not in tareas:
            yield h("referencia_rota", p["uid"], f"Apunta a la tarea «{tarea}», que no existe.")
        elif clase and clase not in clases:
            yield h("referencia_rota", p["uid"], f"Apunta a la clase «{clase}», que no existe.")
    grupos = {g["clave"] for g in ctx.grupos}
    for v in ctx.diseno["variantes"]:
        if v["elemento_uid"] not in ctx.uids:
            yield h("referencia_rota", v["uid"], "La variante apunta a un elemento que no existe.")
        elif v["grupo_clave"] not in grupos:
            yield h(
                "referencia_rota",
                v["elemento_uid"],
                f"Hay una variante para el grupo {v['grupo_clave']}, que ya no existe.",
            )


@regla("objetivos", "advertencia", "Cada objetivo se trabaja en alguna clase y las clases citan objetivos que existen.")
def objetivos(ctx: Contexto) -> Iterator[Hallazgo]:
    codigos = {o["codigo"]: o["uid"] for o in ctx.diseno["objetivos"]}
    usados = {cod for c in ctx.clases for cod in c["objetivos"]}
    for cod, uid in codigos.items():
        if cod not in usados:
            yield h("objetivos", uid, f"Ninguna clase de tareas trabaja el objetivo {cod}.")
    for c in ctx.clases:
        for cod in c["objetivos"]:
            if cod not in codigos:
                yield h("objetivos", c["uid"], f"La clase cita el objetivo {cod}, que no existe.")


# ---------- Tareas ----------


@regla("tarea_sin_arcs", "error", "Toda tarea tiene sus cuatro estrategias ARCS.")
def tarea_sin_arcs(ctx: Contexto) -> Iterator[Hallazgo]:
    for t in ctx.tareas:
        vacios = [campo for campo, texto in t["arcs"].items() if not texto.strip()]
        if vacios:
            yield h("tarea_sin_arcs", t["uid"], f"Faltan estrategias ARCS: {', '.join(vacios)}.")


@regla("casos_de_prueba", "error", "Toda tarea tiene casos visibles; las convencionales, además, al menos uno oculto.")
def casos_de_prueba(ctx: Contexto) -> Iterator[Hallazgo]:
    for t in ctx.tareas:
        casos = t["casos_prueba"]
        if not any(not c["oculto"] for c in casos):
            yield h("casos_de_prueba", t["uid"], "Agrega al menos un caso de prueba visible para el estudiante.")
        if t["nivel_apoyo"] in ("convencional", "solucion_libre") and not any(c["oculto"] for c in casos):
            yield h(
                "casos_de_prueba",
                t["uid"],
                "Un problema sin apoyo necesita casos ocultos: si no, se puede «programar para los ejemplos».",
            )


@regla("codigo_verificado", "error", "La solución de referencia compila y pasa todos sus casos.")
def codigo_verificado(ctx: Contexto) -> Iterator[Hallazgo]:
    pendientes = [(t["uid"], t["uid"]) for t in ctx.tareas]
    pendientes += [
        (p["uid"], f"{p['uid']}#{i}") for p in ctx.diseno["practica_parcial"] for i in range(len(p["ejercicios"]))
    ]
    for uid, clave in pendientes:
        r = ctx.codigo.get(clave)
        if r is None:
            yield h("codigo_verificado", uid, "La solución no se ha ejecutado todavía.")
        elif r.error_compilacion:
            yield h("codigo_verificado", uid, f"La solución no compila: {r.error_compilacion[:200]}")
        elif r.aprobados < r.total:
            yield h("codigo_verificado", uid, f"La solución pasa {r.aprobados} de {r.total} casos." + (f" {r.detalle}" if r.detalle else ""))


def _normalizar(codigo: str) -> str:
    return re.sub(r"\s+", "", re.sub(r"/\*.*?\*/|//[^\n]*|#[^\n]*", "", codigo or "", flags=re.S))


@regla("apoyo_coherente", "error", "El código inicial corresponde al nivel de apoyo (huecos, sin entregar la solución).")
def apoyo_coherente(ctx: Contexto) -> Iterator[Hallazgo]:
    for vista in ctx.vistas():
        for c in ctx.clases:
            for t in ctx.tareas_de(c["uid"], vista):
                if vista and (t["uid"], vista) not in ctx.variantes:
                    continue  # sin variante: ya se revisó en la base
                nivel, inicial = t["nivel_apoyo"], t["codigo_inicial"]
                if nivel == "por_completar" and "HUECO" not in inicial:
                    yield h(
                        "apoyo_coherente",
                        t["uid"],
                        "Es «por completar» pero el código inicial no marca huecos (/* HUECO n: … */).",
                        vista,
                    )
                if nivel in ("por_completar", "convencional") and inicial.strip() and _normalizar(inicial) == _normalizar(t["solucion"]):
                    yield h(
                        "apoyo_coherente",
                        t["uid"],
                        "El código inicial es la solución completa: el estudiante no tendría nada que hacer.",
                        vista,
                    )
                if nivel == "ejemplo_resuelto" and not inicial.strip():
                    yield h(
                        "apoyo_coherente",
                        t["uid"],
                        "Un ejemplo resuelto debe mostrar la solución comentada en el código inicial.",
                        vista,
                    )


@regla("ejemplo_sin_autoexplicacion", "info", "Los ejemplos resueltos piden auto-explicación.")
def ejemplo_sin_autoexplicacion(ctx: Contexto) -> Iterator[Hallazgo]:
    for t in ctx.tareas:
        if t["nivel_apoyo"] == "ejemplo_resuelto" and not t["pide_autoexplicacion"]:
            yield h(
                "ejemplo_sin_autoexplicacion",
                t["uid"],
                "Considera pedir auto-explicación: potencia el efecto del ejemplo resuelto.",
            )


# ---------- Secuencia (desvanecimiento, interactividad, variabilidad) ----------


@regla("desvanecimiento", "advertencia", "Dentro de una clase, el apoyo no aumenta de una tarea a la siguiente.")
def desvanecimiento(ctx: Contexto) -> Iterator[Hallazgo]:
    for vista in ctx.vistas():
        for c in ctx.clases:
            # La autoexplicación y la imaginación dan el código hecho, pero no son más apoyo: el estudiante lo explica o
            # lo imagina sin traza (T6 y T7 del mapa de ruta, después de la solución libre). El reto colaborativo (T8) es
            # un problema convencional en equipo que cierra la secuencia, tampoco más apoyo que la solución libre
            # (un primer ejemplo resuelto también puede pedir autoexplicación: ese sí cuenta)
            # El primer ejemplo de la clase completa: la Ruta B no ve el de T1 y su autoexplicación no lo sustituye
            primer_ejemplo = next((t["uid"] for t in ctx.tareas_de(c["uid"]) if t["nivel_apoyo"] == "ejemplo_resuelto"), None)
            tareas = [t for t in ctx.tareas_de(c["uid"], vista)
                      if not (t.get("pide_autoexplicacion") and t["nivel_apoyo"] == "ejemplo_resuelto" and t["uid"] != primer_ejemplo)
                      and not (t.get("colaborativa") and t["nivel_apoyo"] == "convencional")]
            for anterior, siguiente in zip(tareas, tareas[1:]):
                if AUTONOMIA[siguiente["nivel_apoyo"]] < AUTONOMIA[anterior["nivel_apoyo"]]:
                    yield h(
                        "desvanecimiento",
                        siguiente["uid"],
                        f"En {nombre_grupo(ctx, vista)}, «{siguiente['titulo']}» da más apoyo que la tarea anterior: la guía debe desvanecerse.",
                        vista,
                    )


@regla("inicio_con_apoyo", "advertencia", "Los grupos de nivel básico empiezan la primera clase con un ejemplo resuelto.")
def inicio_con_apoyo(ctx: Contexto) -> Iterator[Hallazgo]:
    if not ctx.clases:
        return
    primera = ctx.clases[0]
    basicos = [g["clave"] for g in ctx.grupos if g["nivel"] == "basico"] or ([None] if not ctx.grupos else [])
    for vista in basicos:
        tareas = ctx.tareas_de(primera["uid"], vista)
        if tareas and tareas[0]["nivel_apoyo"] != "ejemplo_resuelto":
            yield h(
                "inicio_con_apoyo",
                tareas[0]["uid"],
                f"{nombre_grupo(ctx, vista).capitalize()} empieza sin ejemplo resuelto.",
                vista,
            )


@regla("interactividad_creciente", "advertencia", "Las clases van de menor a mayor interactividad de elementos.")
def interactividad_creciente(ctx: Contexto) -> Iterator[Hallazgo]:
    for anterior, siguiente in zip(ctx.clases, ctx.clases[1:]):
        if INTERACTIVIDAD[siguiente["diseno"]["interactividad"]] < INTERACTIVIDAD[anterior["diseno"]["interactividad"]]:
            yield h(
                "interactividad_creciente",
                siguiente["uid"],
                "Esta clase tiene menos interactividad que la anterior: revisa el orden.",
            )


@regla("variabilidad", "advertencia", "Las tareas de una clase no son casi idénticas entre sí.")
def variabilidad(ctx: Contexto) -> Iterator[Hallazgo]:
    for c in ctx.clases:
        tareas = ctx.tareas_de(c["uid"])
        for i, a in enumerate(tareas):
            for b in tareas[i + 1 :]:
                if similitud(a["enunciado_md"], b["enunciado_md"]) >= 0.8:
                    yield h(
                        "variabilidad",
                        b["uid"],
                        f"El enunciado es casi igual al de «{a['titulo']}»: varía el escenario o los datos.",
                    )


# ---------- Metadatos de diseño y multimedia ----------


@regla("efecto_sin_explicar", "advertencia", "Cada efecto aplicado explica cómo se aplicó.")
def efecto_sin_explicar(ctx: Contexto) -> Iterator[Hallazgo]:
    for tipo in ("clases", "tareas", "soporte", "procedimental", "practica_parcial"):
        for e in ctx.diseno[tipo]:
            for ef in e["diseno"]["efectos"]:
                if not ef["como"].strip():
                    yield h("efecto_sin_explicar", e["uid"], f"Explica cómo se aplicó el efecto «{ef['id']}».")


@regla("video_sin_segmentar", "advertencia", "Los videos de más de 6 minutos tienen segmentos (información transitoria).")
def video_sin_segmentar(ctx: Contexto) -> Iterator[Hallazgo]:
    for m in ctx.diseno["medios"]:
        if m["tipo"] in ("video", "protocolo_verbal") and (m["duracion_s"] or 0) > 360 and len(m["segmentos"]) < 2:
            yield h(
                "video_sin_segmentar",
                m["uid"],
                "Video largo sin segmentos: márcalos para que el estudiante navegue por partes.",
            )


@regla("texto_redundante", "advertencia", "El texto no repite la narración del medio que acompaña (redundancia).")
def texto_redundante(ctx: Contexto) -> Iterator[Hallazgo]:
    transcripciones = {m["uid"]: m["transcripcion"] for m in ctx.diseno["medios"] if m["transcripcion"]}
    for tipo in ("soporte", "procedimental"):
        for e in ctx.diseno[tipo]:
            for uid in re.findall(r"media:([A-Za-z0-9_-]+)", e["cuerpo_md"]):
                if uid in transcripciones and similitud(e["cuerpo_md"], transcripciones[uid]) >= 0.7:
                    yield h(
                        "texto_redundante",
                        e["uid"],
                        "El texto repite casi todo lo que dice la narración del medio: deja uno de los dos.",
                    )


def verificar(solicitud: SolicitudVerificacion) -> Informe:
    ctx = Contexto.desde(solicitud)
    hallazgos = [x for _, _, f in REGLAS.values() for x in f(ctx)]
    semaforo: dict[str, str] = {uid: "verde" for uid in ctx.uids}
    for x in hallazgos:
        if x.nivel == "error":
            semaforo[x.elemento_uid] = "rojo"
        elif x.nivel == "advertencia" and semaforo.get(x.elemento_uid) != "rojo":
            semaforo[x.elemento_uid] = "amarillo"
    return Informe(
        hallazgos=hallazgos,
        errores=sum(x.nivel == "error" for x in hallazgos),
        advertencias=sum(x.nivel == "advertencia" for x in hallazgos),
        semaforo=semaforo,  # type: ignore[arg-type]
    )
