"""
Generación de certificados PDF para insignias otorgadas.

Usa ReportLab para construir el documento. El certificado incluye:
  - Nombre del estudiante y la insignia obtenida
  - Descripción, habilidades y criterios de la insignia
  - Datos de verificación on-chain (tx_hash, chain_status)
  - URL pública de verificación

Uso:
    pdf_bytes = generate_certificate_pdf(
        award_id=award.id,
        student_id=award.student_id,
        ...
    )
    return Response(content=pdf_bytes, media_type="application/pdf")
"""

import io
from datetime import datetime
from typing import List, Optional

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import cm
from reportlab.platypus import HRFlowable, Paragraph, SimpleDocTemplate, Spacer

# ── Paleta de colores MeritCoin ───────────────────────────────────────────────
COLOR_DARK   = colors.HexColor("#1a1a2e")
COLOR_ACCENT = colors.HexColor("#0f3460")
COLOR_GOLD   = colors.HexColor("#e94560")
COLOR_GRAY   = colors.HexColor("#6b7280")


def _build_styles() -> dict:
    """
    Construye y retorna el diccionario de estilos ParagraphStyle del certificado.

    Centralizar los estilos aquí evita redefinirlos dentro de la función
    principal y facilita ajustes futuros de diseño.
    """
    base = getSampleStyleSheet()
    return {
        "title": ParagraphStyle(
            "cert_title",
            parent=base["Title"],
            fontSize=28,
            textColor=COLOR_DARK,
            alignment=TA_CENTER,
            fontName="Helvetica-Bold",
            spaceAfter=6,
        ),
        "subtitle": ParagraphStyle(
            "cert_subtitle",
            parent=base["Normal"],
            fontSize=12,
            textColor=COLOR_GRAY,
            alignment=TA_CENTER,
            spaceAfter=4,
        ),
        "badge_name": ParagraphStyle(
            "cert_badge_name",
            parent=base["Heading1"],
            fontSize=22,
            textColor=COLOR_ACCENT,
            alignment=TA_CENTER,
            fontName="Helvetica-Bold",
            spaceBefore=12,
            spaceAfter=8,
        ),
        "body": ParagraphStyle(
            "cert_body",
            parent=base["Normal"],
            fontSize=10,
            textColor=COLOR_DARK,
            spaceAfter=4,
            leading=16,
        ),
        "section": ParagraphStyle(
            "cert_section",
            parent=base["Heading2"],
            fontSize=11,
            textColor=COLOR_ACCENT,
            fontName="Helvetica-Bold",
            spaceBefore=14,
            spaceAfter=6,
        ),
        "meta": ParagraphStyle(
            "cert_meta",
            parent=base["Normal"],
            fontSize=8,
            textColor=COLOR_GRAY,
            alignment=TA_CENTER,
            spaceAfter=2,
        ),
    }


def generate_certificate_pdf(
    award_id: str,
    student_id: str,
    badge_name: str,
    badge_description: str,
    criteria: List[str],
    skills: List[str],
    issued_by_id: str,
    issued_at: datetime,
    chain_status: str,
    tx_hash: Optional[str] = None,
    verify_base_url: str = "https://meritcoin.app/verify",
) -> bytes:
    """
    Genera el PDF del certificado de una insignia otorgada.

    Args:
        award_id:          UUID del BadgeAward (usado para verificación pública).
        student_id:        Identificador del estudiante.
        badge_name:        Nombre de la insignia.
        badge_description: Descripción completa de la insignia.
        criteria:          Lista de criterios de obtención.
        skills:            Lista de habilidades asociadas.
        issued_by_id:      ID del emisor (profesor o sistema).
        issued_at:         Fecha de emisión.
        chain_status:      Estado en blockchain (confirmed, skipped, failed).
        tx_hash:           Hash de la transacción on-chain (opcional).
        verify_base_url:   Base URL del endpoint de verificación pública.

    Returns:
        Bytes del PDF generado listos para enviar como respuesta HTTP.
    """
    buffer = io.BytesIO()
    doc = SimpleDocTemplate(
        buffer,
        pagesize=A4,
        rightMargin=2 * cm,
        leftMargin=2 * cm,
        topMargin=2 * cm,
        bottomMargin=2 * cm,
        title=f"Certificado — {badge_name}",
        author="MeritCoin",
    )

    styles = _build_styles()
    story = []

    # ── Encabezado ────────────────────────────────────────────────────────────
    story.append(Paragraph("MeritCoin", styles["title"]))
    story.append(Paragraph("Certificado de Logro Digital", styles["subtitle"]))
    story.append(Spacer(1, 0.3 * cm))
    story.append(HRFlowable(width="100%", thickness=2, color=COLOR_GOLD, spaceAfter=12))

    # ── Estudiante e insignia ─────────────────────────────────────────────────
    story.append(Paragraph("Se certifica que", styles["subtitle"]))
    story.append(Spacer(1, 0.2 * cm))
    story.append(Paragraph(f"<b>{student_id}</b>", styles["badge_name"]))
    story.append(Paragraph("ha obtenido la insignia", styles["subtitle"]))
    story.append(Spacer(1, 0.2 * cm))
    story.append(Paragraph(badge_name, styles["badge_name"]))
    story.append(Spacer(1, 0.3 * cm))
    story.append(HRFlowable(width="60%", thickness=1, color=COLOR_ACCENT, spaceAfter=10))

    # ── Descripción ───────────────────────────────────────────────────────────
    story.append(Paragraph("Descripción", styles["section"]))
    story.append(Paragraph(badge_description, styles["body"]))

    # ── Habilidades ───────────────────────────────────────────────────────────
    if skills:
        story.append(Paragraph("Habilidades", styles["section"]))
        story.append(Paragraph(" · ".join(skills), styles["body"]))

    # ── Criterios ─────────────────────────────────────────────────────────────
    if criteria:
        story.append(Paragraph("Criterios de Obtención", styles["section"]))
        for criterion in criteria:
            story.append(Paragraph(f"• {criterion}", styles["body"]))

    # ── Metadatos de verificación ─────────────────────────────────────────────
    story.append(Spacer(1, 0.5 * cm))
    story.append(HRFlowable(width="100%", thickness=1, color=colors.lightgrey, spaceAfter=10))
    story.append(Paragraph(
        f"Emitido por: {issued_by_id}   ·   Fecha: {issued_at.strftime('%d/%m/%Y')}",
        styles["meta"],
    ))
    story.append(Paragraph(f"ID de verificación: {award_id}", styles["meta"]))
    story.append(Paragraph(f"Estado en cadena: {chain_status}", styles["meta"]))
    if tx_hash:
        story.append(Paragraph(f"TX Hash: {tx_hash}", styles["meta"]))

    # ── URL de verificación pública ───────────────────────────────────────────
    verify_url = f"{verify_base_url}/{award_id}"
    story.append(Spacer(1, 0.3 * cm))
    story.append(Paragraph(
        f'Verifica en: <a href="{verify_url}" color="#0f3460">{verify_url}</a>',
        styles["meta"],
    ))

    doc.build(story)
    return buffer.getvalue()
