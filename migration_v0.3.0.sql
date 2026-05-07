-- MeritCoin — Migración v0.3.0: Sistema de insignias personalizables

CREATE TABLE IF NOT EXISTS skills (
    id          VARCHAR(36)  PRIMARY KEY,
    name        VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_skills_name ON skills (name);

CREATE TABLE IF NOT EXISTS badge_templates (
    id               VARCHAR(36)  PRIMARY KEY,
    name             VARCHAR(255) NOT NULL,
    description      TEXT         NOT NULL,
    image_url        VARCHAR(500),
    criteria         TEXT,
    created_by_id    VARCHAR(255) NOT NULL,
    created_by_role  VARCHAR(50)  NOT NULL DEFAULT 'teacher',
    is_active        BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_badge_templates_created_by ON badge_templates (created_by_id);
CREATE INDEX IF NOT EXISTS idx_badge_templates_active     ON badge_templates (is_active);

CREATE TABLE IF NOT EXISTS badge_template_skills (
    template_id  VARCHAR(36) NOT NULL REFERENCES badge_templates(id) ON DELETE CASCADE,
    skill_id     VARCHAR(36) NOT NULL REFERENCES skills(id)          ON DELETE CASCADE,
    PRIMARY KEY (template_id, skill_id)
);

CREATE TABLE IF NOT EXISTS badge_awards (
    id              VARCHAR(36)  PRIMARY KEY,
    template_id     VARCHAR(36)  NOT NULL REFERENCES badge_templates(id) ON DELETE RESTRICT,
    student_id      VARCHAR(255) NOT NULL,
    student_wallet  VARCHAR(42),
    issued_by_id    VARCHAR(255) NOT NULL,
    issued_by_role  VARCHAR(50)  NOT NULL,
    course_id       VARCHAR(255),
    revoked         BOOLEAN      NOT NULL DEFAULT FALSE,
    revoked_at      TIMESTAMP,
    revoked_by_id   VARCHAR(255),
    tx_hash         VARCHAR(66),
    chain_status    VARCHAR(20)  NOT NULL DEFAULT 'simulated',
    issued_at       TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_badge_awards_student   ON badge_awards (student_id);
CREATE INDEX IF NOT EXISTS idx_badge_awards_template  ON badge_awards (template_id);
CREATE INDEX IF NOT EXISTS idx_badge_awards_issued_by ON badge_awards (issued_by_id);