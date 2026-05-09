-- ═══════════════════════════════════════════════════════════════════════════
-- MeritCoin v0.4.0 — Migración consolidada PostgreSQL
--
-- Consolida: v0.2.0 (nuevos campos events) + v0.3.0 (sistema de insignias)
-- Agrega:    v0.4.0 (campos nullable en audit_log, status/last_error en events)
--
-- Ejecutar UNA SOLA VEZ contra la base de datos del backend:
--   docker compose exec postgres psql -U meritcoin -d meritcoin_db \
--     -c "\i /migration_v0.4.0.sql"
--
-- O directo:
--   docker compose exec postgres psql -U meritcoin -d meritcoin_db \
--     -f migration_v0.4.0.sql
-- ═══════════════════════════════════════════════════════════════════════════

BEGIN;

-- ── 1. Nuevas columnas en events (v0.2.0) ───────────────────────────────────
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS activity_id   VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS activity_name VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS coins_amount  FLOAT        DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS coin_symbol   VARCHAR(10)  DEFAULT 'MRT';

CREATE INDEX IF NOT EXISTS ix_events_activity_id ON events (activity_id);

-- ── 2. Estado e idempotencia en events (v0.4.0) ──────────────────────────────
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS status     VARCHAR(20) NOT NULL DEFAULT 'processing',
    ADD COLUMN IF NOT EXISTS last_error TEXT        DEFAULT NULL;

CREATE INDEX IF NOT EXISTS ix_events_status ON events (status);

-- ── 3. Relajar NOT NULL en audit_log (v0.4.0) ───────────────────────────────
ALTER TABLE audit_log
    ALTER COLUMN cid_ipfs  DROP NOT NULL,
    ALTER COLUMN tx_badge  DROP NOT NULL,
    ALTER COLUMN tx_mrt    DROP NOT NULL,
    ALTER COLUMN badge_id  DROP NOT NULL,
    ALTER COLUMN mrt_amount DROP NOT NULL;

-- ── 4. Sistema de insignias: skills (v0.3.0) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS skills (
    id          VARCHAR(36)  PRIMARY KEY,
    name        VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_skills_name ON skills (name);

-- ── 5. Sistema de insignias: badge_templates (v0.3.0) ────────────────────────
CREATE TABLE IF NOT EXISTS badge_templates (
    id              VARCHAR(36)  PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    description     TEXT         NOT NULL,
    image_url       VARCHAR(500),
    criteria        TEXT,
    created_by_id   VARCHAR(255) NOT NULL,
    created_by_role VARCHAR(50)  NOT NULL DEFAULT 'teacher',
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_badge_templates_created_by ON badge_templates (created_by_id);
CREATE INDEX IF NOT EXISTS idx_badge_templates_active     ON badge_templates (is_active);

-- ── 6. Sistema de insignias: badge_template_skills (v0.3.0) ──────────────────
CREATE TABLE IF NOT EXISTS badge_template_skills (
    template_id VARCHAR(36) NOT NULL REFERENCES badge_templates(id) ON DELETE CASCADE,
    skill_id    VARCHAR(36) NOT NULL REFERENCES skills(id)          ON DELETE CASCADE,
    PRIMARY KEY (template_id, skill_id)
);

-- ── 7. Sistema de insignias: badge_awards (v0.3.0) ───────────────────────────
CREATE TABLE IF NOT EXISTS badge_awards (
    id             VARCHAR(36)  PRIMARY KEY,
    template_id    VARCHAR(36)  NOT NULL REFERENCES badge_templates(id) ON DELETE RESTRICT,
    student_id     VARCHAR(255) NOT NULL,
    student_wallet VARCHAR(42),
    issued_by_id   VARCHAR(255) NOT NULL,
    issued_by_role VARCHAR(50)  NOT NULL,
    course_id      VARCHAR(255),
    revoked        BOOLEAN      NOT NULL DEFAULT FALSE,
    revoked_at     TIMESTAMP,
    revoked_by_id  VARCHAR(255),
    tx_hash        VARCHAR(66),
    chain_status   VARCHAR(20)  NOT NULL DEFAULT 'simulated',
    issued_at      TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_badge_awards_student   ON badge_awards (student_id);
CREATE INDEX IF NOT EXISTS idx_badge_awards_template  ON badge_awards (template_id);
CREATE INDEX IF NOT EXISTS idx_badge_awards_issued_by ON badge_awards (issued_by_id);

-- ── 8. Verificación ──────────────────────────────────────────────────────────
DO $$
DECLARE
    col_count INT;
BEGIN
    SELECT COUNT(*) INTO col_count
    FROM information_schema.columns
    WHERE table_name = 'events'
      AND column_name IN (
          'activity_id', 'activity_name', 'coins_amount', 'coin_symbol',
          'status', 'last_error'
      );

    IF col_count = 6 THEN
        RAISE NOTICE '✅ Migración v0.4.0 aplicada correctamente.';
    ELSE
        RAISE EXCEPTION '❌ Solo se encontraron % de 6 columnas esperadas en events.', col_count;
    END IF;
END
$$;

COMMIT;