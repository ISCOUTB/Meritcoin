"""
Servicio de tokens MRT: determina cuántos tokens acuñar por evento.

Desde v0.2.0, la fuente principal del reward es event.coins_amount,
calculado en Moodle según reglas por curso/actividad.
Este servicio conserva una lógica fallback para compatibilidad.
"""

from app.core.config import settings
from app.models.events import AcademicEvent, EventType


def calculate_mrt_reward(event: AcademicEvent) -> float:
    """
    Prioridad:
    1. Usar coins_amount si viene desde Moodle.
    2. Si no viene, aplicar fallback local.
    """

    if event.coins_amount is not None and float(event.coins_amount) > 0:
        return float(event.coins_amount)

    if event.event_type == EventType.COMPLETION:
        return float(settings.mrt_reward_completion)

    if event.event_type == EventType.GRADE:
        if event.grade is not None and event.grade >= 3.0:
            return float(settings.mrt_reward_grade)
        return 0.0

    return 0.0