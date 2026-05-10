"""
Fixtures compartidas para los tests del backend MeritCoin.

Usa SQLite en memoria (via aiosqlite) para no depender de PostgreSQL,
y mockea la blockchain para no necesitar Hardhat corriendo.
"""

import asyncio
from unittest.mock import AsyncMock, MagicMock

import pytest
import pytest_asyncio
from httpx import ASGITransport, AsyncClient
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine

from app.core.database import Base


# ── Engine de prueba (SQLite async en memoria) ──────────────────────────
TEST_DATABASE_URL = "sqlite+aiosqlite:///:memory:"

test_engine = create_async_engine(
    TEST_DATABASE_URL,
    echo=False,
)

TestSessionLocal = async_sessionmaker(
    test_engine,
    class_=AsyncSession,
    expire_on_commit=False,
)


# ── Fixtures ────────────────────────────────────────────────────────────

@pytest_asyncio.fixture(autouse=True)
async def setup_database():
    """Crea y destruye las tablas antes/después de cada test."""
    async with test_engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    yield
    async with test_engine.begin() as conn:
        await conn.run_sync(Base.metadata.drop_all)


@pytest_asyncio.fixture
async def db_session() -> AsyncSession:
    """Sesión de BD para tests individuales."""
    async with TestSessionLocal() as session:
        yield session


@pytest_asyncio.fixture
async def mock_blockchain():
    """
    Mockea el servicio de blockchain.
    Retorna un MagicMock con métodos que devuelven hashes simulados.
    """
    mock = MagicMock()
    mock.is_connected.return_value = True
    mock.mint_badge = AsyncMock(return_value="0x" + "ab" * 32)
    mock.mint_mrt = AsyncMock(return_value="0x" + "cd" * 32)
    mock.burn_mrt = AsyncMock(return_value="0x" + "ef" * 32)
    mock.get_badge_balance.return_value = 1
    mock.get_mrt_balance.return_value = ("100.0", "100000000000000000000")

    with patch("app.services.events_service.blockchain", mock), \
         patch("app.api.students.blockchain", mock), \
         patch("app.services.badges_service.blockchain", mock), \
         patch("app.services.blockchain.blockchain", mock):
        yield mock


@pytest_asyncio.fixture
async def client(mock_blockchain) -> AsyncClient:
    """
    Cliente HTTP de prueba para la FastAPI app.
    Sobreescribe la dependency get_db para usar SQLite en memoria.
    """
    from app.core.database import get_db
    from app.main import app

    async def override_get_db():
        async with TestSessionLocal() as session:
            try:
                yield session
                await session.commit()
            except Exception:
                await session.rollback()
                raise

    app.dependency_overrides[get_db] = override_get_db

    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as ac:
        yield ac

    app.dependency_overrides.clear()


# ── Helpers ─────────────────────────────────────────────────────────────

def make_event_payload(
    event_id: str = "evt-test-001",
    student_wallet: str = "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
    student_id: str = "STU-001",
    course_id: str = "COURSE-101",
    course_name: str = "Introducción a Blockchain",
    activity_id: str = "cmid-10",
    activity_name: str = "Quiz 1",
    event_type: str = "grade",        # ← era "completion", ahora "grade"
    grade: float | None = 4.0,        # ← añadido default aprobatorio
    coins_amount: float = 5.0,        # ← añadido, fuente de verdad del plugin
    coin_symbol: str = "MRT",
) -> dict:
    """Genera un payload de evento académico para tests."""
    return {
        "event_id": event_id,
        "student_wallet": student_wallet,
        "student_id": student_id,
        "course_id": course_id,
        "course_name": course_name,
        "activity_id": activity_id,
        "activity_name": activity_name,
        "event_type": event_type,
        "grade": grade,
        "coins_amount": coins_amount,
        "coin_symbol": coin_symbol,
    }