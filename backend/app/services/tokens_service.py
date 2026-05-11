"""
Servicio de tokens MRT: determina cuántos tokens acuñar por evento.

Desde v0.2.0 la fuente principal del reward es event.coins_amount,
calculado en Moodle por el plugin según reglas configuradas por curso
y actividad. Este servicio solo actúa como fallback si coins_amount
no viene o viene en cero.

Fallback por tipo de evento:
  - COMPLETION : 0 MRT fijo
  - GRADE      : 1 MRT si la calificación es aprobatoria (>= 3.0)
  - Otros      : 0 MRT

Nota: los valores del fallback están hardcodeados aquí intencionalmente
porque los settings mrt_reward_completion/grade fueron eliminados al
confirmar que el plugin siempre envía coins_amount. Si en el futuro
se necesita configurarlos por entorno, volver a añadirlos a config.py.
"""

from app.models.events import AcademicEvent, EventType

# Valores fallback (solo aplican si Moodle no envía coins_amount)
_FALLBACK_MRT_GRADE      = 1.0
_GRADE_MIN_APROBATORIA   = 3.0


def calculate_mrt_reward(event: AcademicEvent) -> float:
    if event.coins_amount is not None and float(event.coins_amount) > 0:
        return float(event.coins_amount)

    if event.event_type == EventType.GRADE:
        if event.grade is not None and event.grade >= _GRADE_MIN_APROBATORIA:
            return _FALLBACK_MRT_GRADE
        return 0.0

    return 0.0
