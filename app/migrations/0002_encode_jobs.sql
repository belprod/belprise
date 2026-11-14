-- Encoding of the playback versions on the server (CAHIER_DES_CHARGES §5.7 bis):
-- one job per version, processed by the scheduled task, one at a time.
CREATE TABLE encode_jobs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    version_id    INTEGER NOT NULL UNIQUE REFERENCES versions (id) ON DELETE CASCADE,
    status        TEXT    NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    progress      REAL    NOT NULL DEFAULT 0 CHECK (progress >= 0 AND progress <= 1),
    attempts      INTEGER NOT NULL DEFAULT 0,
    error         TEXT    NULL,
    started_at    TEXT    NULL,
    heartbeat_at  TEXT    NULL,             -- a running job without news for a while was cut: it starts again
    finished_at   TEXT    NULL,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX encode_jobs_status ON encode_jobs (status, created_at);
