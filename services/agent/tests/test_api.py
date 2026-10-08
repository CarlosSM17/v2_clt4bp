import json
from pathlib import Path

from fastapi.testclient import TestClient

from app.config import Ajustes, ajustes
from app.conocimiento import VERSION
from app.main import app

EJEMPLO = Path(__file__).parents[3] / "packages/contracts/examples/diseno-curso/clase-recorridos.json"

app.dependency_overrides[ajustes] = lambda: Ajustes(agente_token="secreto-de-prueba")
cliente = TestClient(app)


def test_sin_token_no_hay_acceso():
    assert cliente.post("/v1/verificar", json={}).status_code == 401
    assert cliente.post("/v1/verificar", json={}, headers={"X-Agente-Token": "otro"}).status_code == 401


def test_verificar_por_http():
    diseno = json.loads(EJEMPLO.read_text(encoding="utf-8"))
    r = cliente.post("/v1/verificar", json={"diseno": diseno}, headers={"X-Agente-Token": "secreto-de-prueba"})
    assert r.status_code == 200
    assert r.json()["errores"] == 3  # sin resultados de código: tres soluciones sin ejecutar


def test_contenido_que_no_cumple_el_contrato_es_422():
    r = cliente.post("/v1/verificar", json={"diseno": {"curso": {}}}, headers={"X-Agente-Token": "secreto-de-prueba"})
    assert r.status_code == 422


def test_salud_dice_la_version_del_prompt():
    # Así se nota si responde un proceso viejo (la recarga de «fastapi dev» en Windows puede quedarse colgada)
    assert cliente.get("/salud").json()["prompt"] == VERSION
