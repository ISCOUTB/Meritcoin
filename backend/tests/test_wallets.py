"""
Tests para el sistema de wallets custodiales.

Cubre:
  - Provisionado de wallet nueva
  - Reutilización de wallet existente en curso nuevo
  - Rematrícula: reactivación con saldo en 0
  - Expiración de curso (snapshot MRT)
  - Sobreescritura de expires_at
  - Endpoints HTTP: provision, get, expire-course, patch expires-at
"""

from datetime import datetime, timezone, timedelta

import pytest
from httpx import AsyncClient
from sqlalchemy.ext.asyncio import AsyncSession

from app.services import wallet_service
from app.models.wallets import WalletRegistry, CourseEnrollment


# ── Helpers ───────────────────────────────────────────────────────────────────

STUDENT_A  = "STU-WALLET-001"
STUDENT_B  = "STU-WALLET-002"
COURSE_101 = "COURSE-101"
COURSE_102 = "COURSE-102"

def _future(days: int = 90) -> datetime:
    return datetime.now(timezone.utc) + timedelta(days=days)

def _past(days: int = 1) -> datetime:
    return datetime.now(timezone.utc) - timedelta(days=days)


# ── Tests: wallet_service (unitarios) ────────────────────────────────────────

class TestWalletService:

    @pytest.mark.asyncio
    async def test_provision_crea_wallet_nueva(self, db_session: AsyncSession):
        """Primera llamada genera wallet y enrollment nuevos."""
        result = await wallet_service.provision_wallet(
            db=db_session,
            student_id=STUDENT_A,
            course_id=COURSE_101,
            expires_at=_future(),
        )
        assert result["wallet_address"].startswith("0x")
        assert len(result["wallet_address"]) == 42
        assert result["created"] is True

    @pytest.mark.asyncio
    async def test_provision_reutiliza_wallet_existente(self, db_session: AsyncSession):
        """Segunda llamada con mismo student_id pero distinto curso reutiliza la wallet."""
        r1 = await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_A,
            course_id=COURSE_101, expires_at=_future(),
        )
        r2 = await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_A,
            course_id=COURSE_102, expires_at=_future(),
        )
        assert r1["wallet_address"] == r2["wallet_address"]  # misma wallet
        assert r2["created"] is False

    @pytest.mark.asyncio
    async def test_provision_diferentes_estudiantes_wallets_distintas(self, db_session: AsyncSession):
        """Dos estudiantes distintos obtienen wallets distintas."""
        r1 = await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_A,
            course_id=COURSE_101, expires_at=_future(),
        )
        r2 = await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_B,
            course_id=COURSE_101, expires_at=_future(),
        )
        assert r1["wallet_address"] != r2["wallet_address"]

    @pytest.mark.asyncio
    async def test_provision_rematricula_reactiva_enrollment(self, db_session: AsyncSession):
        """Si el enrollment estaba expired, se reactiva con saldo 0."""
        # Provisionar y expirar
        await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_A,
            course_id=COURSE_101, expires_at=_past(),
        )
        await wallet_service.expire_course(db=db_session, course_id=COURSE_101)

        # Rematricular
        result = await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_A,
            course_id=COURSE_101, expires_at=_future(),
        )
        assert result["created"] is False  # wallet reutilizada, no nueva

        # Verificar que el enrollment volvió a active con snapshot en 0
        from sqlalchemy import select
        row = await db_session.execute(
            select(CourseEnrollment).where(
                CourseEnrollment.student_id == STUDENT_A,
                CourseEnrollment.course_id  == COURSE_101,
            )
        )
        enrollment = row.scalar_one()
        assert enrollment.status == "active"
        assert enrollment.mrt_snapshot == 0.0
        assert enrollment.expired_at is None

    @pytest.mark.asyncio
    async def test_expire_course_marca_enrollments(self, db_session: AsyncSession):
        """expire_course marca todos los enrollments activos del curso como expired."""
        await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_A,
            course_id=COURSE_101, expires_at=_future(),
        )
        await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_B,
            course_id=COURSE_101, expires_at=_future(),
        )

        result = await wallet_service.expire_course(db=db_session, course_id=COURSE_101)

        assert result["expired_count"] == 2
        assert result["course_id"] == COURSE_101

    @pytest.mark.asyncio
    async def test_expire_course_no_afecta_otros_cursos(self, db_session: AsyncSession):
        """expire_course solo afecta al curso indicado."""
        await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_A,
            course_id=COURSE_101, expires_at=_future(),
        )
        await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_A,
            course_id=COURSE_102, expires_at=_future(),
        )

        await wallet_service.expire_course(db=db_session, course_id=COURSE_101)

        from sqlalchemy import select
        row = await db_session.execute(
            select(CourseEnrollment).where(
                CourseEnrollment.student_id == STUDENT_A,
                CourseEnrollment.course_id  == COURSE_102,
            )
        )
        enrollment = row.scalar_one()
        assert enrollment.status == "active"   # COURSE_102 no se tocó

    @pytest.mark.asyncio
    async def test_update_expires_at(self, db_session: AsyncSession):
        """update_expires_at sobreescribe correctamente la fecha."""
        await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_A,
            course_id=COURSE_101, expires_at=_future(90),
        )
        nueva_fecha = _future(180)
        updated = await wallet_service.update_expires_at(
            db=db_session,
            student_id=STUDENT_A,
            course_id=COURSE_101,
            expires_at=nueva_fecha,
        )
        assert updated is True

        from sqlalchemy import select
        row = await db_session.execute(
            select(CourseEnrollment).where(
                CourseEnrollment.student_id == STUDENT_A,
                CourseEnrollment.course_id  == COURSE_101,
            )
        )
        enrollment = row.scalar_one()
        # Comparar sin timezone para compatibilidad con SQLite
        assert enrollment.expires_at.replace(tzinfo=None) == nueva_fecha.replace(tzinfo=None)

    @pytest.mark.asyncio
    async def test_update_expires_at_enrollment_inexistente(self, db_session: AsyncSession):
        """update_expires_at retorna False si el enrollment no existe."""
        updated = await wallet_service.update_expires_at(
            db=db_session,
            student_id="NO-EXISTE",
            course_id=COURSE_101,
            expires_at=_future(),
        )
        assert updated is False

    @pytest.mark.asyncio
    async def test_get_wallet_existente(self, db_session: AsyncSession):
        """get_wallet retorna la wallet correcta."""
        await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_A,
            course_id=COURSE_101, expires_at=_future(),
        )
        wallet = await wallet_service.get_wallet(db_session, STUDENT_A)
        assert wallet is not None
        assert wallet.student_id == STUDENT_A
        assert wallet.wallet_address.startswith("0x")

    @pytest.mark.asyncio
    async def test_get_wallet_inexistente(self, db_session: AsyncSession):
        """get_wallet retorna None si el estudiante no tiene wallet."""
        wallet = await wallet_service.get_wallet(db_session, "NO-EXISTE")
        assert wallet is None

    @pytest.mark.asyncio
    async def test_private_key_no_expuesta(self, db_session: AsyncSession):
        """get_wallet no expone la clave privada desencriptada."""
        await wallet_service.provision_wallet(
            db=db_session, student_id=STUDENT_A,
            course_id=COURSE_101, expires_at=_future(),
        )
        wallet = await wallet_service.get_wallet(db_session, STUDENT_A)
        # private_key_enc debe estar encriptada (no empieza con 0x)
        assert not wallet.private_key_enc.startswith("0x")


# ── Tests: endpoints HTTP ─────────────────────────────────────────────────────

class TestWalletEndpoints:

    @pytest.mark.asyncio
    async def test_provision_endpoint(self, client: AsyncClient):
        """POST /wallets/provision retorna wallet_address y created=True."""
        resp = await client.post("/wallets/provision", json={
            "student_id": STUDENT_A,
            "course_id":  COURSE_101,
            "expires_at": _future().isoformat(),
        })
        assert resp.status_code == 200
        data = resp.json()
        assert data["wallet_address"].startswith("0x")
        assert data["created"] is True

    @pytest.mark.asyncio
    async def test_provision_idempotente(self, client: AsyncClient):
        """Segunda llamada con mismo student+curso retorna created=False."""
        payload = {
            "student_id": STUDENT_A,
            "course_id":  COURSE_101,
            "expires_at": _future().isoformat(),
        }
        r1 = await client.post("/wallets/provision", json=payload)
        r2 = await client.post("/wallets/provision", json=payload)

        assert r1.status_code == 200
        assert r2.status_code == 200
        assert r1.json()["wallet_address"] == r2.json()["wallet_address"]
        assert r2.json()["created"] is False

    @pytest.mark.asyncio
    async def test_get_wallet_endpoint(self, client: AsyncClient):
        """GET /wallets/{student_id} retorna datos de la wallet."""
        await client.post("/wallets/provision", json={
            "student_id": STUDENT_A,
            "course_id":  COURSE_101,
            "expires_at": _future().isoformat(),
        })
        resp = await client.get(f"/wallets/{STUDENT_A}")
        assert resp.status_code == 200
        data = resp.json()
        assert data["student_id"] == STUDENT_A
        assert data["wallet_address"].startswith("0x")
        assert data["status"] == "active"
        assert "private_key_enc" not in data   # nunca se expone

    @pytest.mark.asyncio
    async def test_get_wallet_no_encontrada(self, client: AsyncClient):
        """GET /wallets/{student_id} retorna 404 si no existe."""
        resp = await client.get("/wallets/NO-EXISTE-999")
        assert resp.status_code == 404

    @pytest.mark.asyncio
    async def test_expire_course_endpoint(self, client: AsyncClient):
        """POST /wallets/expire-course retorna expired_count correcto."""
        # Provisionar 2 estudiantes en el mismo curso
        for sid in [STUDENT_A, STUDENT_B]:
            await client.post("/wallets/provision", json={
                "student_id": sid,
                "course_id":  COURSE_101,
                "expires_at": _future().isoformat(),
            })

        resp = await client.post("/wallets/expire-course", json={"course_id": COURSE_101})
        assert resp.status_code == 200
        data = resp.json()
        assert data["expired_count"] == 2
        assert data["course_id"] == COURSE_101

    @pytest.mark.asyncio
    async def test_update_expires_at_endpoint(self, client: AsyncClient):
        """PATCH /wallets/enrollments/{student}/{course} retorna 204."""
        await client.post("/wallets/provision", json={
            "student_id": STUDENT_A,
            "course_id":  COURSE_101,
            "expires_at": _future(90).isoformat(),
        })
        resp = await client.patch(
            f"/wallets/enrollments/{STUDENT_A}/{COURSE_101}",
            json={"expires_at": _future(180).isoformat()},
        )
        assert resp.status_code == 204

    @pytest.mark.asyncio
    async def test_update_expires_at_no_encontrado(self, client: AsyncClient):
        """PATCH /wallets/enrollments retorna 404 si no existe el enrollment."""
        resp = await client.patch(
            f"/wallets/enrollments/NO-EXISTE/{COURSE_101}",
            json={"expires_at": _future().isoformat()},
        )
        assert resp.status_code == 404