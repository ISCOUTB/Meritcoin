"""
Servicio de wallets custodiales.

Responsabilidades:
  - Provisionar wallets para nuevos estudiantes (genera par de claves eth).
  - Reutilizar wallets existentes cuando el estudiante se rematricula.
  - Crear / renovar CourseEnrollment por (student_id, course_id).
  - Expirar enrollments al cierre del semestre y guardar mrt_snapshot.
"""

import logging
from datetime import datetime, timezone

from eth_account import Account
from sqlalchemy import select, update
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.wallet_crypto import encrypt_private_key
from app.models.wallets import CourseEnrollment, WalletRegistry
from app.services.blockchain import blockchain

logger = logging.getLogger(__name__)


async def provision_wallet(
    db: AsyncSession,
    student_id: str,
    course_id: str,
    expires_at: datetime,
) -> dict:
    """
    Provisiona una wallet para un estudiante en un curso piloto.

    - Si el estudiante ya tiene wallet → la reutiliza.
    - Si el enrollment (student_id, course_id) ya existe y está expired
      → lo reactiva con nuevo expires_at y saldo en 0 (rematrícula).
    - Si no existe → crea wallet + enrollment nuevos.

    Retorna { wallet_address, created: bool }
    """
    # ── 1. Buscar wallet existente ────────────────────────────────────────────
    result = await db.execute(
        select(WalletRegistry).where(WalletRegistry.student_id == student_id)
    )
    wallet = result.scalar_one_or_none()
    created = False

    if wallet is None:
        # ── 2. Generar nueva wallet custodial ─────────────────────────────────
        account = Account.create()
        wallet = WalletRegistry(
            student_id      = student_id,
            wallet_address  = account.address,
            private_key_enc = encrypt_private_key(account.key.hex()),
            status          = "active",
        )
        db.add(wallet)
        await db.flush()   # obtener el ID antes del enrollment
        created = True
        logger.info("Wallet provisionada para %s → %s", student_id, account.address)

    else:
        # Actualizar last_active_at
        await db.execute(
            update(WalletRegistry)
            .where(WalletRegistry.student_id == student_id)
            .values(last_active_at=datetime.now(timezone.utc))
        )

    # ── 3. Buscar enrollment existente ────────────────────────────────────────
    result = await db.execute(
        select(CourseEnrollment).where(
            CourseEnrollment.student_id == student_id,
            CourseEnrollment.course_id  == course_id,
        )
    )
    enrollment = result.scalar_one_or_none()

    if enrollment is None:
        # ── 4a. Crear nuevo enrollment ────────────────────────────────────────
        enrollment = CourseEnrollment(
            student_id     = student_id,
            wallet_address = wallet.wallet_address,
            course_id      = course_id,
            status         = "active",
            mrt_snapshot   = 0.0,
            expires_at     = expires_at,
        )
        db.add(enrollment)
        logger.info("Enrollment creado: %s en curso %s (expira %s)", student_id, course_id, expires_at)

    elif enrollment.status == "expired":
        # ── 4b. Rematrícula — reactivar con saldo en 0 ────────────────────────
        enrollment.status       = "active"
        enrollment.mrt_snapshot = 0.0
        enrollment.expires_at   = expires_at
        enrollment.expired_at   = None
        logger.info("Enrollment reactivado: %s en curso %s", student_id, course_id)

    await db.commit()
    return {"wallet_address": wallet.wallet_address, "created": created}


async def expire_course(db: AsyncSession, course_id: str) -> dict:
    """
    Cierra todos los enrollments activos de un curso.

    Para cada enrollment:
      1. Consulta el saldo MRT actual desde blockchain.
      2. Guarda el snapshot.
      3. Marca como expired.

    Retorna { expired_count, course_id }
    """
    result = await db.execute(
        select(CourseEnrollment).where(
            CourseEnrollment.course_id == course_id,
            CourseEnrollment.status   == "active",
        )
    )
    enrollments = result.scalars().all()

    now = datetime.now(timezone.utc)
    expired_count = 0

    for enrollment in enrollments:
        # Intentar obtener saldo real desde blockchain
        try:
            balance_mrt, _ = blockchain.get_mrt_balance(enrollment.wallet_address)
            snapshot = float(str(balance_mrt))
        except Exception as exc:
            logger.warning(
                "No se pudo obtener balance de %s para snapshot: %s",
                enrollment.wallet_address, exc
            )
            snapshot = enrollment.mrt_snapshot  # conservar el último conocido

        enrollment.status       = "expired"
        enrollment.mrt_snapshot = snapshot
        enrollment.expired_at   = now
        expired_count += 1
        logger.info(
            "Enrollment expirado: %s en curso %s (snapshot: %.4f MRT)",
            enrollment.student_id, course_id, snapshot
        )

    await db.commit()
    return {"expired_count": expired_count, "course_id": course_id}


async def update_expires_at(
    db: AsyncSession,
    student_id: str,
    course_id: str,
    expires_at: datetime,
) -> bool:
    """
    Sobreescribe la fecha de expiración de un enrollment específico.
    Retorna True si se actualizó, False si no existía.
    """
    result = await db.execute(
        update(CourseEnrollment)
        .where(
            CourseEnrollment.student_id == student_id,
            CourseEnrollment.course_id  == course_id,
            CourseEnrollment.status     == "active",
        )
        .values(expires_at=expires_at)
    )
    await db.commit()
    updated = result.rowcount > 0
    if updated:
        logger.info("expires_at actualizado para %s/%s → %s", student_id, course_id, expires_at)
    return updated


async def get_wallet(db: AsyncSession, student_id: str) -> WalletRegistry | None:
    """Retorna la wallet de un estudiante sin exponer la clave privada."""
    result = await db.execute(
        select(WalletRegistry).where(WalletRegistry.student_id == student_id)
    )
    return result.scalar_one_or_none()