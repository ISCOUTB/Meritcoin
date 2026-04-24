"""
Servicio de tokens MRT: determina cuántos tokens acuñar por evento.
"""

from app.core.config import settings
from app.models.events import AcademicEvent, EventType


def calculate_mrt_reward(event: AcademicEvent) -> int:
    """
    Calcula la recompensa en MRT según el tipo de evento.

    - completion: mrt_reward_completion (default 100 MRT)
    - grade: mrt_reward_grade (default 50 MRT), solo si aprueba (grade >= 3.0)

    Retorna 0 si no aplica recompensa.
    """
    if event.event_type == EventType.COMPLETION:
        return settings.mrt_reward_completion

    if event.event_type == EventType.GRADE:
        # Solo recompensar calificaciones aprobatorias
        if event.grade is not None and event.grade >= 3.0:
            return settings.mrt_reward_grade
        return 0

    return 0
