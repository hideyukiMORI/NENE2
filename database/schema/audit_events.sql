-- Canonical audit_events table schema for the Nene2\Audit module (ADR 0014).
-- Reference shape (outside the public API stability guarantee, ADR 0009):
-- copy and adapt. Production DDL is applied via database/migrations/ (Phinx);
-- keep this snapshot in sync. This SQLite dialect variant is what the
-- Nene2\Audit repository tests create.
--
-- Append-only: never UPDATE or DELETE these rows.
CREATE TABLE IF NOT EXISTS audit_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    action          VARCHAR(64) NOT NULL,
    entity_type     VARCHAR(64) NOT NULL,
    entity_id       VARCHAR(64) NULL DEFAULT NULL,
    actor_id        VARCHAR(64) NULL DEFAULT NULL,
    organization_id VARCHAR(64) NULL DEFAULT NULL,
    before_json     TEXT NULL DEFAULT NULL,
    after_json      TEXT NULL DEFAULT NULL,
    metadata_json   TEXT NULL DEFAULT NULL,
    occurred_at     DATETIME NOT NULL
);
CREATE INDEX idx_audit_events_organization_id ON audit_events (organization_id);
CREATE INDEX idx_audit_events_entity ON audit_events (entity_type, entity_id);
CREATE INDEX idx_audit_events_action ON audit_events (action);
CREATE INDEX idx_audit_events_occurred_at ON audit_events (occurred_at);
