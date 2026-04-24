"""
Worker de procesamiento de eventos.

En el piloto, el procesamiento es síncrono dentro del endpoint /events/ingest.
Este módulo sirve como punto de extensión para procesamiento asíncrono
con colas (Redis/RabbitMQ) en producción.

Para el MVP, simplemente re-exporta la función de procesamiento.
"""

from app.services.events_service import process_event

__all__ = ["process_event"]
