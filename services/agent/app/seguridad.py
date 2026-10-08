import secrets
from typing import Annotated

from fastapi import Depends, Header, HTTPException, status

from .config import Ajustes, ajustes


def exigir_token(
    x_agente_token: Annotated[str | None, Header()] = None,
    config: Ajustes = Depends(ajustes),
) -> None:
    """Rechaza toda petición que no traiga el secreto compartido con Laravel."""
    if not x_agente_token or not secrets.compare_digest(x_agente_token, config.agente_token):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Token del agente inválido.")
