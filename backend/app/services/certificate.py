"""Generación de certificado PDF para insignias otorgadas (reportlab)."""

import io
from datetime import datetime
from typing import List, Optional

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import cm
from reportlab.lib.enums import TA_CENTER
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, HRFlowable

MC_DARK    = colors.HexColor("#1a1a2e")
MC_ACCENT  = colors.HexColor("#0f3460")
MC_GOLD    = colors.HexColor("#e94560")
MC_GRAY    = colors.HexColor("#6b7280")


def generate_certificate_pdf(
    award_id: str, student_id: str, badge_name: str, badge_description: str,
    criteria: List[str], skills: List[str], issued_by_id: str, issued_at: datetime,
    chain_status: str, tx_hash: Optional[str] = None,
    verify_base_url: str = "https://meritcoin.app/verify",
) -> bytes:
    buffer = io.BytesIO()
    doc = SimpleDocTemplate(buffer, pagesize=A4,
        rightMargin=2*cm, leftMargin=2*cm, topMargin=2*cm, bottomMargin=2*cm,
        title=f"Certificado — {badge_name}", author="MeritCoin")

    styles = getSampleStyleSheet()
    title_s    = ParagraphStyle("T",  parent=styles["Title"],   fontSize=28, textColor=MC_DARK,   alignment=TA_CENTER, fontName="Helvetica-Bold", spaceAfter=6)
    sub_s      = ParagraphStyle("S",  parent=styles["Normal"],  fontSize=12, textColor=MC_GRAY,   alignment=TA_CENTER, spaceAfter=4)
    badge_s    = ParagraphStyle("B",  parent=styles["Heading1"],fontSize=22, textColor=MC_ACCENT, alignment=TA_CENTER, fontName="Helvetica-Bold", spaceBefore=12, spaceAfter=8)
    body_s     = ParagraphStyle("Bo", parent=styles["Normal"],  fontSize=10, textColor=MC_DARK,   spaceAfter=4, leading=16)
    section_s  = ParagraphStyle("Se", parent=styles["Heading2"],fontSize=11, textColor=MC_ACCENT, fontName="Helvetica-Bold", spaceBefore=14, spaceAfter=6)
    meta_s     = ParagraphStyle("M",  parent=styles["Normal"],  fontSize=8,  textColor=MC_GRAY,   alignment=TA_CENTER, spaceAfter=2)

    story = []
    story.append(Paragraph("MeritCoin", title_s))
    story.append(Paragraph("Certificado de Logro Digital", sub_s))
    story.append(Spacer(1, 0.3*cm))
    story.append(HRFlowable(width="100%", thickness=2, color=MC_GOLD, spaceAfter=12))
    story.append(Paragraph("Se certifica que", sub_s))
    story.append(Spacer(1, 0.2*cm))
    story.append(Paragraph(f"<b>{student_id}</b>", badge_s))
    story.append(Paragraph("ha obtenido la insignia", sub_s))
    story.append(Spacer(1, 0.2*cm))
    story.append(Paragraph(badge_name, badge_s))
    story.append(Spacer(1, 0.3*cm))
    story.append(HRFlowable(width="60%", thickness=1, color=MC_ACCENT, spaceAfter=10))
    story.append(Paragraph("Descripción", section_s))
    story.append(Paragraph(badge_description, body_s))
    if skills:
        story.append(Paragraph("Habilidades", section_s))
        story.append(Paragraph(" · ".join(skills), body_s))
    if criteria:
        story.append(Paragraph("Criterios de Obtención", section_s))
        for c in criteria:
            story.append(Paragraph(f"• {c}", body_s))
    story.append(Spacer(1, 0.5*cm))
    story.append(HRFlowable(width="100%", thickness=1, color=colors.lightgrey, spaceAfter=10))
    story.append(Paragraph(f"Emitido por: {issued_by_id}   ·   Fecha: {issued_at.strftime('%d/%m/%Y')}", meta_s))
    story.append(Paragraph(f"ID de verificación: {award_id}", meta_s))
    story.append(Paragraph(f"Estado en cadena: {chain_status}", meta_s))
    if tx_hash:
        story.append(Paragraph(f"TX Hash: {tx_hash}", meta_s))
    verify_url = f"{verify_base_url}/{award_id}"
    story.append(Spacer(1, 0.3*cm))
    story.append(Paragraph(f'Verifica en: <a href="{verify_url}" color="#0f3460">{verify_url}</a>', meta_s))

    doc.build(story)
    return buffer.getvalue()