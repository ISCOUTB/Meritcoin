-- ═══════════════════════════════════════════════════════════════════════════
-- MeritCoin v0.2.0 — Migración PostgreSQL (sin Alembic)
-- Ejecutar UNA SOLA VEZ contra la base de datos del backend.
--
-- Cómo ejecutar:
--   docker exec meritcoin-postgres psql -U <usuario> -d <base_de_datos> -f migration_v0.2.0.sql
--
-- O desde psql interactivo:
--   \i /ruta/migration_v0.2.0.sql
-- ═══════════════════════════════════════════════════════════════════════════

BEGIN;

-- ── 1. Nuevas columnas en la tabla "events" ──────────────────────────────────
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS activity_id    VARCHAR(255)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS activity_name  VARCHAR(500)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS coins_amount   FLOAT         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS coin_symbol    VARCHAR(10)   DEFAULT 'MRT';

-- ── 2. Índice para búsquedas por actividad ───────────────────────────────────
CREATE INDEX IF NOT EXISTS ix_events_activity_id
    ON events (activity_id);

-- ── 3. Verificación rápida ───────────────────────────────────────────────────
DO $$
DECLARE
    col_count INT;
BEGIN
    SELECT COUNT(*) INTO col_count
    FROM information_schema.columns
    WHERE table_name = 'events'
      AND column_name IN ('activity_id', 'activity_name', 'coins_amount', 'coin_symbol');

    IF col_count = 4 THEN
        RAISE NOTICE '✅ Migración v0.2.0 aplicada correctamente (4 columnas agregadas).';
    ELSE
        RAISE EXCEPTION '❌ Solo se encontraron % de 4 columnas esperadas.', col_count;
    END IF;
END
$$;

COMMIT;
