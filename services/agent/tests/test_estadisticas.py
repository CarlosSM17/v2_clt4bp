"""Los valores esperados salen de R (ejemplos de su documentación) y de statsmodels; ver docs/validacion/estadisticas.R."""

import math

import pytest
from fastapi.testclient import TestClient

from app.config import Ajustes, ajustes
from app.estadisticas import (
    Serie, SolicitudAncova, SolicitudCorrelacion, SolicitudDosGrupos, ancova, correlacion, dos_grupos, pre_post,
)
from app.main import app

# Datos «sleep» de R: horas extra de sueño con dos fármacos en los mismos 10 pacientes
SLEEP_1 = [0.7, -1.6, -0.2, -1.2, -0.1, 3.4, 3.7, 0.8, 0.0, 2.0]
SLEEP_2 = [1.9, 0.8, 1.1, 0.1, -0.1, 4.4, 5.5, 1.6, 4.6, 3.4]


def aprox(x: float | None, esperado: float, tol: float = 1e-4) -> bool:
    return x is not None and math.isclose(x, esperado, abs_tol=tol)


def test_t_pareada_como_r():
    # R: t.test(SLEEP_1, SLEEP_2, paired = TRUE) → t = -4.0621, df = 9, p = 0.002833, IC [-2.4599, -0.7001]
    r = pre_post(Serie(pre=SLEEP_2, post=SLEEP_1, maximo=None))
    assert r.n == 10 and r.t_pareada.gl == 9
    assert aprox(r.t_pareada.estadistico, -4.0621) and aprox(r.t_pareada.p, 0.002833, 1e-6)
    assert aprox(r.ic95[0], -2.459886) and aprox(r.ic95[1], -0.700114)
    assert aprox(r.d_z, -1.284558)
    assert r.g_hake is None  # sin máximo no hay ganancia normalizada


def test_wilcoxon_con_empates_y_ceros_como_r():
    # R: wilcox.test(SLEEP_1, SLEEP_2, paired = TRUE) → V = 0, p = 0.009091 (normal, por los empates)
    r = pre_post(Serie(pre=SLEEP_2, post=SLEEP_1, maximo=None))
    assert r.wilcoxon.estadistico == 0 and aprox(r.wilcoxon.p, 0.009091, 1e-6)
    assert "normal" in r.wilcoxon.metodo


def test_wilcoxon_exacta_como_r():
    # Ejemplo de ?wilcox.test (escala de depresión de Hamilton): V = 40, p = 0.03906
    x = [1.83, 0.50, 1.62, 2.48, 1.68, 1.88, 1.55, 3.06, 1.30]
    y = [0.878, 0.647, 0.598, 2.05, 1.06, 1.29, 1.06, 3.14, 1.29]
    r = pre_post(Serie(pre=y, post=x, maximo=None))
    assert r.wilcoxon.estadistico == 40 and aprox(r.wilcoxon.p, 0.039062, 1e-6)
    assert "exacta" in r.wilcoxon.metodo


def test_ganancia_de_hake_y_pares_incompletos():
    r = pre_post(Serie(pre=[40, 50, None, 100, 60], post=[70, 75, 90, 100, None], maximo=100))
    assert r.n == 3 and r.excluidos == 2
    # medias: pre 63.33, post 81.67 → g = 18.33 / 36.67 = 0.5
    assert aprox(r.g_hake, 0.5) and r.nivel_g == "medio"
    # individuales: 30/60 = 0.5 y 25/50 = 0.5; quien empezó en 100 no cuenta
    assert aprox(r.g_individual, 0.5)


def test_sin_variacion_no_hay_t_ni_nan():
    r = pre_post(Serie(pre=[50, 60, 70], post=[60, 70, 80]))  # todos suben 10: de(D) = 0
    assert r.t_pareada is None and r.d_z is None and r.normalidad is None
    assert r.wilcoxon is not None and r.wilcoxon.estadistico == 6
    assert r.model_dump_json()  # serializable: ningún NaN


def test_welch_y_d_de_cohen_como_r():
    # R: t.test(SLEEP_1, SLEEP_2) → t = -1.8608, df = 17.776, p = 0.07939, IC [-3.3655, 0.2055]
    r = dos_grupos(SolicitudDosGrupos(a=SLEEP_1, b=SLEEP_2))
    assert aprox(r.welch.estadistico, -1.860813) and aprox(r.welch.gl, 17.776474) and aprox(r.welch.p, 0.079394)
    assert aprox(r.ic95[0], -3.365483) and aprox(r.ic95[1], 0.205483)
    assert aprox(r.d_cohen, -0.832181) and aprox(r.g_hedges, -0.797019)


PRE_E = [35, 42, 50, 28, 61, 47, 39, 55, 44, 30]
POST_E = [68, 70, 82, 55, 88, 75, 66, 85, 72, 60]
PRE_C = [38, 45, 33, 52, 40, 58, 29, 47, 36, 43]
POST_C = [55, 60, 48, 70, 52, 74, 41, 63, 50, 58]


def test_ancova_como_statsmodels_y_car():
    # anova_lm(ols('post ~ pre + C(grupo)'), typ=2) y car::Anova(lm(post ~ pre + grupo), type = 2)
    r = ancova(SolicitudAncova(post=POST_E + POST_C, pre=PRE_E + PRE_C, grupo=["experimental"] * 10 + ["control"] * 10))
    assert r.n == 20 and r.grupo.gl == 1 and r.gl_error == 17
    assert aprox(r.grupo.estadistico, 228.382532) and aprox(r.grupo.p, 2.750305e-11, 1e-15)
    assert aprox(r.covariable.estadistico, 439.906166)
    assert aprox(r.pendiente, 1.046899)
    assert aprox(r.medias_ajustadas["experimental"], 71.576551) and aprox(r.medias_ajustadas["control"], 57.623449)
    assert aprox(r.pendientes_homogeneas.estadistico, 2.311889) and aprox(r.pendientes_homogeneas.p, 0.147903)
    assert aprox(r.eta2_parcial, 970.600163 / (970.600163 + 72.248095))


def test_correlacion():
    # R: cor.test(x, y) → r = 0.7746, t = 2.1213, df = 3, p = 0.124; Spearman con empates → rho = 0.7379
    r = correlacion(SolicitudCorrelacion(x=[1, 2, 3, 4, 5], y=[2, 4, 5, 4, 5]))
    assert aprox(r.pearson.estadistico, 0.774597) and aprox(r.pearson.p, 0.124027)
    assert aprox(r.spearman.estadistico, 0.737865)
    plana = correlacion(SolicitudCorrelacion(x=[1, 1, 1], y=[1, 2, 3]))
    assert plana.pearson.estadistico is None


@pytest.fixture
def cliente() -> TestClient:
    app.dependency_overrides[ajustes] = lambda: Ajustes(agente_token="secreto-de-prueba")
    return TestClient(app)


def test_endpoint_pre_post(cliente: TestClient):
    cuerpo = {"series": {"global": {"pre": SLEEP_2, "post": SLEEP_1, "maximo": None}}}
    assert cliente.post("/v1/estadisticas/pre-post", json=cuerpo).status_code == 401
    r = cliente.post("/v1/estadisticas/pre-post", json=cuerpo, headers={"X-Agente-Token": "secreto-de-prueba"})
    assert r.status_code == 200
    assert r.json()["global"]["t_pareada"]["gl"] == 9
    malo = {"series": {"global": {"pre": [1, 2], "post": [1]}}}
    assert cliente.post("/v1/estadisticas/pre-post", json=malo, headers={"X-Agente-Token": "secreto-de-prueba"}).status_code == 422
