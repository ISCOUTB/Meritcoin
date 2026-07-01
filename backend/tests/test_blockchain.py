"""
Tests para el servicio de blockchain (con mocks de web3).
Tests para servicios de badges y tokens.
"""

import pytest

from app.models.events import AcademicEvent, EventType
from app.services import badges_service, tokens_service


# ── Helpers ─────────────────────────────────────────────────────────────

def _make_event(**kwargs) -> AcademicEvent:
    defaults = {
        "event_id": "evt-test-001",
        "student_wallet": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
        "student_id": "STU-001",
        "course_id": "COURSE-101",
        "course_name": "Introducción a Blockchain",
        "activity_id": "cmid-10",
        "activity_name": "Quiz 1",
        "event_type": "grade",
        "grade": None,
        "coins_amount": None,
        "coin_symbol": "MRT",
    }
    defaults.update(kwargs)
    return AcademicEvent(**defaults)


# ── Tests: tokens_service ───────────────────────────────────────────────

class TestTokensService:
    """Tests para cálculo de recompensas MRT (fallback local)."""

    def test_coins_amount_es_fuente_principal(self):
        """Si coins_amount > 0, se usa directamente sin fallback."""
        event = _make_event(event_type="grade", grade=4.0, coins_amount=7.5)
        assert tokens_service.calculate_mrt_reward(event) == 7.5

    def test_grade_passing_fallback(self):
        """Sin coins_amount, grade >= 3.0 usa fallback de 1.0 MRT."""
        event = _make_event(event_type="grade", grade=3.0, coins_amount=None)
        assert tokens_service.calculate_mrt_reward(event) == 1.0

    def test_grade_failing_no_reward(self):
        """Sin coins_amount, grade < 3.0 retorna 0 MRT."""
        event = _make_event(event_type="grade", grade=2.9, coins_amount=None)
        assert tokens_service.calculate_mrt_reward(event) == 0.0

    def test_grade_none_no_reward(self):
        """Sin coins_amount ni grade retorna 0 MRT."""
        event = _make_event(event_type="grade", grade=None, coins_amount=None)
        assert tokens_service.calculate_mrt_reward(event) == 0.0

    def test_grade_boundary_exactly_three(self):
        """Nota exactamente 3.0 SÍ recibe recompensa (aprobatoria)."""
        event = _make_event(event_type="grade", grade=3.0, coins_amount=None)
        assert tokens_service.calculate_mrt_reward(event) == 1.0

    def test_coins_amount_cero_activa_fallback(self):
        """coins_amount=0 activa el fallback local."""
        event = _make_event(event_type="grade", grade=4.0, coins_amount=0.0)
        assert tokens_service.calculate_mrt_reward(event) == 1.0


# ── Tests: BlockchainService (mocked) ───────────────────────────────────

class TestBlockchainServiceMocked:
    """Tests del servicio blockchain usando el mock de conftest."""

    @pytest.mark.asyncio
    async def test_blockchain_connected(self, mock_blockchain):
        """El mock reporta conexión activa."""
        assert mock_blockchain.is_connected() is True

    @pytest.mark.asyncio
    async def test_mint_badge_returns_hash(self, mock_blockchain):
        """mint_badge retorna un tx_hash hex."""
        tx = await mock_blockchain.mint_badge(
            "0x70997970C51812dc3A010C7d01b50e0d17dc79C8", 12345, "ipfs://QmTest"
        )
        assert tx.startswith("0x")
        assert len(tx) == 66  # 0x + 64 hex chars

    @pytest.mark.asyncio
    async def test_mint_mrt_returns_hash(self, mock_blockchain):
        """mint_mrt retorna un tx_hash hex."""
        tx = await mock_blockchain.mint_mrt(
            "0x70997970C51812dc3A010C7d01b50e0d17dc79C8", 100
        )
        assert tx.startswith("0x")
        assert len(tx) == 66

    @pytest.mark.asyncio
    async def test_get_mrt_balance(self, mock_blockchain):
        """get_mrt_balance retorna tupla (mrt, wei)."""
        balance = mock_blockchain.get_mrt_balance(
            "0x70997970C51812dc3A010C7d01b50e0d17dc79C8"
        )
        assert balance == ("100.0", "100000000000000000000")
