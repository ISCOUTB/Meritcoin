"""
Tests para el servicio de blockchain (con mocks de web3).
Tests para servicios de badges y tokens.
"""

import pytest

from app.models.events import AcademicEvent, EventType
from app.services import badges_service, tokens_service


# ── Helpers ─────────────────────────────────────────────────────────────

def _make_event(**kwargs) -> AcademicEvent:
    """Crea un AcademicEvent para tests."""
    defaults = {
        "event_id": "evt-test-001",
        "student_wallet": "0x70997970C51812dc3A010C7d01b50e0d17dc79C8",
        "student_id": "STU-001",
        "course_id": "COURSE-101",
        "course_name": "Introducción a Blockchain",
        "event_type": "completion",
        "grade": None,
    }
    defaults.update(kwargs)
    return AcademicEvent(**defaults)


# ── Tests: badges_service ───────────────────────────────────────────────

class TestBadgeService:
    """Tests para generación de badge_id, metadatos OBv2 e IPFS simulado."""

    def test_generate_badge_id_deterministic(self):
        """El mismo curso+tipo siempre genera el mismo badge_id."""
        event = _make_event()
        id1 = badges_service.generate_badge_id(event)
        id2 = badges_service.generate_badge_id(event)
        assert id1 == id2
        assert isinstance(id1, int)
        assert id1 > 0

    def test_generate_badge_id_different_for_different_events(self):
        """Diferentes cursos o tipos generan diferentes badge_id."""
        event_a = _make_event(course_id="COURSE-101", event_type="completion")
        event_b = _make_event(course_id="COURSE-102", event_type="completion")
        event_c = _make_event(course_id="COURSE-101", event_type="grade")

        id_a = badges_service.generate_badge_id(event_a)
        id_b = badges_service.generate_badge_id(event_b)
        id_c = badges_service.generate_badge_id(event_c)

        assert id_a != id_b  # Diferente curso
        assert id_a != id_c  # Diferente tipo

    def test_generate_obv2_metadata_structure(self):
        """Los metadatos OBv2 tienen la estructura correcta."""
        event = _make_event()
        meta = badges_service.generate_obv2_metadata(event)

        assert meta["@context"] == "https://w3id.org/openbadges/v2"
        assert meta["type"] == "Assertion"
        assert meta["id"] == f"urn:meritcoin:badge:{event.event_id}"
        assert meta["recipient"]["type"] == "ethereumAddress"
        assert meta["recipient"]["identity"] == event.student_wallet
        assert meta["badge"]["type"] == "BadgeClass"
        assert "Universidad Tecnológica de Bolívar" in meta["badge"]["issuer"]["name"]

    def test_generate_obv2_no_personal_data(self):
        """Los metadatos NO contienen datos personales reales."""
        event = _make_event(student_id="STU-SECRET-001")
        meta = badges_service.generate_obv2_metadata(event)

        # Serializar todo como string para buscar
        import json
        meta_str = json.dumps(meta)

        assert "STU-SECRET-001" not in meta_str  # student_id no debe filtrarse
        # Solo contiene wallet (pseudo-anónimo) y datos del curso
        assert event.student_wallet in meta_str

    def test_simulate_ipfs_pin_deterministic(self):
        """El mismo contenido siempre genera el mismo CID."""
        event = _make_event()
        meta = badges_service.generate_obv2_metadata(event)

        cid1 = badges_service.simulate_ipfs_pin(meta)
        cid2 = badges_service.simulate_ipfs_pin(meta)

        assert cid1 == cid2
        assert cid1.startswith("QmSimulated")

    def test_simulate_ipfs_pin_different_content(self):
        """Diferente contenido genera diferente CID."""
        meta_a = {"test": "a"}
        meta_b = {"test": "b"}

        cid_a = badges_service.simulate_ipfs_pin(meta_a)
        cid_b = badges_service.simulate_ipfs_pin(meta_b)

        assert cid_a != cid_b


# ── Tests: tokens_service ───────────────────────────────────────────────

class TestTokensService:
    """Tests para cálculo de recompensas MRT."""

    def test_completion_reward(self):
        """Evento completion retorna 100 MRT."""
        event = _make_event(event_type="completion")
        assert tokens_service.calculate_mrt_reward(event) == 100

    def test_grade_passing_reward(self):
        """Evento grade con nota >= 3.0 retorna 50 MRT."""
        event = _make_event(event_type="grade", grade=3.0)
        assert tokens_service.calculate_mrt_reward(event) == 50

        event_high = _make_event(event_type="grade", grade=5.0)
        assert tokens_service.calculate_mrt_reward(event_high) == 50

    def test_grade_failing_no_reward(self):
        """Evento grade con nota < 3.0 retorna 0 MRT."""
        event = _make_event(event_type="grade", grade=2.9)
        assert tokens_service.calculate_mrt_reward(event) == 0

    def test_grade_none_no_reward(self):
        """Evento grade sin nota retorna 0 MRT."""
        event = _make_event(event_type="grade", grade=None)
        assert tokens_service.calculate_mrt_reward(event) == 0

    def test_grade_boundary_exactly_three(self):
        """Nota exactamente 3.0 SÍ recibe recompensa (es aprobatoria)."""
        event = _make_event(event_type="grade", grade=3.0)
        reward = tokens_service.calculate_mrt_reward(event)
        assert reward == 50


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
        tx = mock_blockchain.mint_badge(
            "0x70997970C51812dc3A010C7d01b50e0d17dc79C8", 12345, "ipfs://QmTest"
        )
        assert tx.startswith("0x")
        assert len(tx) == 66  # 0x + 64 hex chars

    @pytest.mark.asyncio
    async def test_mint_mrt_returns_hash(self, mock_blockchain):
        """mint_mrt retorna un tx_hash hex."""
        tx = mock_blockchain.mint_mrt(
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
