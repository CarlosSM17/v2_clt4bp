"""Estadísticos de la revisión de resultados (paso 10). Se calculan con SciPy para coincidir con R y JASP.

Convenciones, las mismas que R (así el instructor puede comprobar cada cifra):
- t pareada: t.test(post, pre, paired = TRUE), con el IC del 95 % de la media de las diferencias.
- Wilcoxon: wilcox.test(post, pre, paired = TRUE). V = suma de los rangos positivos. Exacta si hay menos de
  50 diferencias distintas de cero, sin empates ni ceros; si no, normal con corrección de continuidad.
- Dos grupos: t.test(a, b) de Welch (var.equal = FALSE, el valor por defecto de R).
- ANCOVA: lm(post ~ pre + grupo) con sumas de cuadrados tipo II, como car::Anova(modelo, type = 2).
- Correlación: cor.test(x, y) para Pearson y cor.test(x, y, method = "spearman", exact = FALSE) para Spearman.
Los valores que no existen (p. ej., t con desviación cero) se devuelven como null, nunca como NaN.
"""

import math
from typing import Literal

import numpy as np
from pydantic import BaseModel, Field, model_validator
from scipy import stats

# ---------- Entradas ----------


class Serie(BaseModel):
    """Una medida antes y después, en el mismo orden de estudiantes (null = no presentó)."""

    pre: list[float | None]
    post: list[float | None]
    maximo: float | None = 100  # puntaje máximo; None = sin ganancia normalizada (p. ej., MSLQ)

    @model_validator(mode="after")
    def mismo_largo(self) -> "Serie":
        if len(self.pre) != len(self.post):
            raise ValueError("pre y post deben tener la misma longitud")
        return self


class SolicitudPrePost(BaseModel):
    series: dict[str, Serie] = Field(min_length=1, max_length=20)


class SolicitudDosGrupos(BaseModel):
    a: list[float] = Field(min_length=2)
    b: list[float] = Field(min_length=2)


class SolicitudAncova(BaseModel):
    post: list[float]
    pre: list[float]
    grupo: list[str]

    @model_validator(mode="after")
    def consistente(self) -> "SolicitudAncova":
        if not (len(self.post) == len(self.pre) == len(self.grupo)):
            raise ValueError("post, pre y grupo deben tener la misma longitud")
        if len(set(self.grupo)) < 2:
            raise ValueError("se necesitan al menos dos grupos")
        return self


class SolicitudCorrelacion(BaseModel):
    x: list[float] = Field(min_length=3)
    y: list[float] = Field(min_length=3)

    @model_validator(mode="after")
    def mismo_largo(self) -> "SolicitudCorrelacion":
        if len(self.x) != len(self.y):
            raise ValueError("x e y deben tener la misma longitud")
        return self


# ---------- Salidas ----------


class Descriptivos(BaseModel):
    n: int
    media: float | None
    de: float | None  # desviación estándar muestral (divide entre n - 1)
    mediana: float | None
    minimo: float | None
    maximo: float | None


class Prueba(BaseModel):
    metodo: str
    estadistico: float | None
    gl: float | None = None
    p: float | None


class PrePost(BaseModel):
    n: int  # pares completos
    excluidos: int  # estudiantes sin pre o sin post
    pre: Descriptivos
    post: Descriptivos
    diferencia: Descriptivos  # post - pre
    normalidad: Prueba | None  # Shapiro–Wilk de las diferencias
    t_pareada: Prueba | None
    ic95: tuple[float, float] | None  # de la media de las diferencias
    wilcoxon: Prueba | None
    d_z: float | None  # media(D) / de(D)
    g_hake: float | None  # (media post - media pre) / (máximo - media pre)
    g_individual: float | None  # promedio de las g de cada estudiante (sin quienes empezaron en el máximo)
    nivel_g: Literal["bajo", "medio", "alto"] | None
    prueba_sugerida: Literal["t", "wilcoxon"] | None


class DosGrupos(BaseModel):  # de la diferencia de medias (a - b) con la desviación combinada
    a: Descriptivos
    b: Descriptivos
    welch: Prueba
    ic95: tuple[float, float] | None
    d_cohen: float | None
    g_hedges: float | None


class Ancova(BaseModel):
    n: int
    gl_error: int  # n - grupos - 1
    grupo: Prueba  # F del efecto del grupo, ajustado por el pre
    covariable: Prueba  # F del pre
    eta2_parcial: float | None
    pendiente: float | None  # coeficiente común del pre
    medias_ajustadas: dict[str, float]
    pendientes_homogeneas: Prueba  # interacción pre × grupo; p < .05 = supuesto violado


class Correlacion(BaseModel):
    n: int
    pearson: Prueba
    spearman: Prueba


# ---------- Utilidades ----------


def num(x: float | np.floating | None) -> float | None:
    """Número JSON válido con 8 cifras significativas (así un p de 1e-11 no se vuelve 0), o None si es NaN o infinito."""
    if x is None:
        return None
    x = float(x)
    return float(f"{x:.8g}") if math.isfinite(x) else None


def describir(valores: list[float] | np.ndarray) -> Descriptivos:
    x = np.asarray(valores, dtype=float)
    if x.size == 0:
        return Descriptivos(n=0, media=None, de=None, mediana=None, minimo=None, maximo=None)
    return Descriptivos(
        n=int(x.size), media=num(x.mean()), de=num(x.std(ddof=1)) if x.size > 1 else None,
        mediana=num(np.median(x)), minimo=num(x.min()), maximo=num(x.max()),
    )


def pares(pre: list[float | None], post: list[float | None]) -> tuple[np.ndarray, np.ndarray]:
    completos = [(a, b) for a, b in zip(pre, post, strict=True) if a is not None and b is not None]
    if not completos:
        return np.array([]), np.array([])
    a, b = zip(*completos, strict=True)
    return np.asarray(a, dtype=float), np.asarray(b, dtype=float)


# ---------- Pre/post de un solo grupo ----------


def wilcoxon_como_r(d: np.ndarray) -> Prueba | None:
    """wilcox.test(post, pre, paired = TRUE) de R: descarta ceros; V = suma de rangos positivos."""
    hubo_ceros = bool((d == 0).any())
    d = d[d != 0]
    n = d.size
    if n == 0:
        return None
    rangos = stats.rankdata(np.abs(d))
    v = float(rangos[d > 0].sum())
    empates = len(np.unique(rangos)) != n
    exacta = n < 50 and not empates and not hubo_ceros  # misma regla que R
    metodo = "exact" if exacta else "asymptotic"
    r = stats.wilcoxon(d, zero_method="wilcox", correction=not exacta, method=metodo)
    return Prueba(metodo=f"Wilcoxon de rangos con signo ({'exacta' if exacta else 'normal con corrección de continuidad'})",
                  estadistico=num(v), p=num(r.pvalue))


def pre_post(serie: Serie) -> PrePost:
    pre, post = pares(serie.pre, serie.post)
    n = int(pre.size)
    d = post - pre
    de_d = float(d.std(ddof=1)) if n > 1 else 0.0

    normalidad = t = ic = d_z = None
    if n >= 3 and np.ptp(d) > 0:
        w = stats.shapiro(d)
        normalidad = Prueba(metodo="Shapiro–Wilk", estadistico=num(w.statistic), p=num(w.pvalue))
    if n >= 2 and de_d > 0:
        r = stats.ttest_rel(post, pre)
        t = Prueba(metodo="t de Student para muestras relacionadas", estadistico=num(r.statistic), gl=float(n - 1), p=num(r.pvalue))
        lo, hi = r.confidence_interval(0.95)
        ic = (num(lo), num(hi))
        d_z = num(d.mean() / de_d)

    g_hake = g_ind = nivel = None
    if serie.maximo is not None and n > 0:
        media_pre = float(pre.mean())
        if media_pre < serie.maximo:
            g_hake = num((post.mean() - media_pre) / (serie.maximo - media_pre))
        margen = serie.maximo - pre
        validos = margen > 0
        if validos.any():
            g_ind = num(np.mean(d[validos] / margen[validos]))
        if g_hake is not None:
            # Hake (1998): baja < 0.3 · media < 0.7 · alta
            nivel = "bajo" if g_hake < 0.3 else "medio" if g_hake < 0.7 else "alto"

    sugerida = None
    if t is not None:
        sugerida = "t" if normalidad is None or (normalidad.p or 0) >= 0.05 else "wilcoxon"

    return PrePost(
        n=n, excluidos=len(serie.pre) - n,
        pre=describir(pre), post=describir(post), diferencia=describir(d),
        normalidad=normalidad, t_pareada=t, ic95=ic, wilcoxon=wilcoxon_como_r(d), d_z=d_z,
        g_hake=g_hake, g_individual=g_ind, nivel_g=nivel, prueba_sugerida=sugerida,
    )


# ---------- Dos grupos independientes (experimental contra control) ----------


def dos_grupos(sol: SolicitudDosGrupos) -> DosGrupos:
    a, b = np.asarray(sol.a, dtype=float), np.asarray(sol.b, dtype=float)
    r = stats.ttest_ind(a, b, equal_var=False)
    lo, hi = r.confidence_interval(0.95)
    na, nb = a.size, b.size
    combinada = math.sqrt(((na - 1) * a.var(ddof=1) + (nb - 1) * b.var(ddof=1)) / (na + nb - 2))
    d = (a.mean() - b.mean()) / combinada if combinada > 0 else float("nan")
    return DosGrupos(
        a=describir(a), b=describir(b),
        welch=Prueba(metodo="t de Welch", estadistico=num(r.statistic), gl=num(r.df), p=num(r.pvalue)),
        ic95=(num(lo), num(hi)) if math.isfinite(lo) else None,
        d_cohen=num(d), g_hedges=num(d * (1 - 3 / (4 * (na + nb) - 9))),
    )


# ---------- ANCOVA: post ~ pre + grupo ----------


def _sce(y: np.ndarray, x: np.ndarray) -> tuple[float, np.ndarray]:
    """Suma de cuadrados del error y coeficientes por mínimos cuadrados."""
    beta, *_ = np.linalg.lstsq(x, y, rcond=None)
    residuos = y - x @ beta
    return float(residuos @ residuos), beta


def ancova(sol: SolicitudAncova) -> Ancova:
    y, pre = np.asarray(sol.post, dtype=float), np.asarray(sol.pre, dtype=float)
    niveles = sorted(set(sol.grupo))
    g = np.array(sol.grupo)
    n, k = y.size, len(niveles)
    uno = np.ones(n)
    dummies = np.column_stack([(g == nivel).astype(float) for nivel in niveles[1:]])  # el primero es la referencia

    sce_completo, beta = _sce(y, np.column_stack([uno, pre, dummies]))
    sce_sin_grupo, _ = _sce(y, np.column_stack([uno, pre]))
    sce_sin_pre, _ = _sce(y, np.column_stack([uno, dummies]))
    gl_error = n - k - 1

    def prueba_f(sc: float, gl: int, sce: float, gl_e: int, nombre: str) -> Prueba:
        f = (sc / gl) / (sce / gl_e) if sce > 0 and gl_e > 0 else float("nan")
        return Prueba(metodo=nombre, estadistico=num(f), gl=float(gl), p=num(stats.f.sf(f, gl, gl_e)))

    sc_grupo = sce_sin_grupo - sce_completo
    f_grupo = prueba_f(sc_grupo, k - 1, sce_completo, gl_error, "F del grupo ajustado por el pre-test")
    f_pre = prueba_f(sce_sin_pre - sce_completo, 1, sce_completo, gl_error, "F del pre-test")

    # Homogeneidad de pendientes: ¿mejora el modelo si cada grupo tiene su propia pendiente?
    interaccion = dummies * pre[:, None]
    sce_inter, _ = _sce(y, np.column_stack([uno, pre, dummies, interaccion]))
    gl_inter = n - 2 * k
    homogeneas = prueba_f(sce_completo - sce_inter, k - 1, sce_inter, gl_inter, "Interacción pre × grupo")

    pendiente = float(beta[1])
    media_pre = pre.mean()
    ajustadas = {nivel: num(y[g == nivel].mean() - pendiente * (pre[g == nivel].mean() - media_pre)) for nivel in niveles}
    return Ancova(
        n=n, gl_error=gl_error, grupo=f_grupo, covariable=f_pre,
        eta2_parcial=num(sc_grupo / (sc_grupo + sce_completo)) if sc_grupo + sce_completo > 0 else None,
        pendiente=num(pendiente), medias_ajustadas=ajustadas, pendientes_homogeneas=homogeneas,
    )


# ---------- Correlación (p. ej., eficiencia instruccional contra ganancia) ----------


def correlacion(sol: SolicitudCorrelacion) -> Correlacion:
    x, y = np.asarray(sol.x, dtype=float), np.asarray(sol.y, dtype=float)
    if np.ptp(x) == 0 or np.ptp(y) == 0:
        vacia = Prueba(metodo="sin variación", estadistico=None, p=None)
        return Correlacion(n=int(x.size), pearson=vacia, spearman=vacia)
    p = stats.pearsonr(x, y)
    s = stats.spearmanr(x, y)
    return Correlacion(
        n=int(x.size),
        pearson=Prueba(metodo="r de Pearson", estadistico=num(p.statistic), gl=float(x.size - 2), p=num(p.pvalue)),
        spearman=Prueba(metodo="rho de Spearman", estadistico=num(s.statistic), p=num(s.pvalue)),
    )
