-- belprise 3.0: starting schema (CAHIER_DES_CHARGES §7.4). Fresh start:
-- the migrations of the 1.x and 2.x versions are folded into this file.
-- Timecodes are frame numbers (integers); dates are UTC 'YYYY-MM-DD HH:MM:SS'.

-- Organizations and accounts ---------------------------------------------

CREATE TABLE organizations (
    id                     INTEGER PRIMARY KEY,
    name                   TEXT    NOT NULL,
    last_video_number      INTEGER NOT NULL DEFAULT 1000,
    quota_bytes            INTEGER NULL CHECK (quota_bytes IS NULL OR quota_bytes > 0),  -- NULL: no limit
    totp_required          INTEGER NOT NULL DEFAULT 0 CHECK (totp_required IN (0, 1)),
    logo                   TEXT    NULL,
    accent_color           TEXT    NOT NULL DEFAULT '#e8515f',
    theme                  TEXT    NOT NULL DEFAULT 'dark' CHECK (theme IN ('dark', 'light')),
    downgraded_at          TEXT    NULL,
    downgraded_from        TEXT    NULL,   -- previous quota, as shown ("250 Go", "Sans limite")
    purge_at               TEXT    NULL,
    purge_reminded         INTEGER NULL,
    deletion_requested_at  TEXT    NULL,
    deletion_at            TEXT    NULL,
    deletion_by            TEXT    NULL CHECK (deletion_by IN ('admin', 'operator')),
    deletion_requested_by  TEXT    NULL,
    deletion_reminded      INTEGER NULL,
    created_at             TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at             TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE users (
    id               INTEGER PRIMARY KEY,
    organization_id  INTEGER NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    email            TEXT    NOT NULL UNIQUE COLLATE NOCASE,   -- login
    name             TEXT    NOT NULL,
    password_hash    TEXT    NOT NULL,                         -- Argon2id
    role             TEXT    NOT NULL CHECK (role IN ('admin', 'editor', 'viewer')),
    totp_secret      TEXT    NULL,                             -- sealed (SecretBox)
    totp_last_step   INTEGER NULL,
    session_epoch    TEXT    NOT NULL DEFAULT '',              -- changed when credentials change
    last_login_at    TEXT    NULL,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- One administrator per organization (§2.2).
CREATE UNIQUE INDEX users_one_admin ON users (organization_id) WHERE role = 'admin';
CREATE INDEX users_organization_id ON users (organization_id);

-- Two-factor recovery codes: single use, stored hashed.
CREATE TABLE recovery_codes (
    id         INTEGER PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    code_hash  TEXT    NOT NULL,
    used_at    TEXT    NULL
);

CREATE INDEX recovery_codes_user ON recovery_codes (user_id);

CREATE TABLE invitations (
    id               INTEGER PRIMARY KEY,
    organization_id  INTEGER NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    email            TEXT    NOT NULL COLLATE NOCASE,
    role             TEXT    NOT NULL CHECK (role IN ('admin', 'editor', 'viewer')),
    token_hash       TEXT    NOT NULL UNIQUE,                  -- sha256 of the token, never the token
    invited_by       INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    expires_at       TEXT    NOT NULL,
    accepted_at      TEXT    NULL,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX invitations_organization_id ON invitations (organization_id);

-- Password and two-factor resets (§5.10): link sent to the person, 1 hour, single use.
CREATE TABLE account_resets (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind          TEXT    NOT NULL CHECK (kind IN ('password', 'totp')),
    token_hash    TEXT    NOT NULL UNIQUE,
    requested_by  TEXT    NOT NULL,           -- name shown in the journal and the e-mail
    expires_at    TEXT    NOT NULL,
    used_at       TEXT    NULL,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX account_resets_user ON account_resets (user_id, kind);

CREATE TABLE superadmins (
    id              INTEGER PRIMARY KEY,
    email           TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    name            TEXT    NOT NULL,
    password_hash   TEXT    NOT NULL,
    totp_secret     TEXT    NOT NULL,          -- sealed (SecretBox)
    totp_last_step  INTEGER NULL,
    session_epoch   TEXT    NOT NULL DEFAULT '',
    last_login_at   TEXT    NULL,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Content --------------------------------------------------------------------

-- AUTOINCREMENT: a client's id names its media folder, never reused.
CREATE TABLE clients (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id  INTEGER NULL REFERENCES organizations (id),
    name             TEXT    NOT NULL,
    logo             TEXT    NULL,                 -- file name under data/logos/
    bg_color         TEXT    NOT NULL DEFAULT '#0a0a0b',
    text_color       TEXT    NOT NULL DEFAULT '#fafafa',
    accent_color     TEXT    NOT NULL DEFAULT '#e8515f',
    welcome_text     TEXT    NOT NULL DEFAULT '',
    archived_at      TEXT    NULL,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX clients_organization_id ON clients (organization_id);

CREATE TABLE projects (
    id           INTEGER PRIMARY KEY,
    client_id    INTEGER NOT NULL REFERENCES clients (id) ON DELETE CASCADE,
    name         TEXT    NOT NULL,
    archived_at  TEXT    NULL,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX projects_client_id ON projects (client_id);

CREATE TABLE videos (
    id                INTEGER PRIMARY KEY,
    number            INTEGER NOT NULL CHECK (number >= 1001),   -- per organization
    project_id        INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    title             TEXT    NOT NULL,
    download_allowed  INTEGER NOT NULL DEFAULT 1 CHECK (download_allowed IN (0, 1)),
    archived_at       TEXT    NULL,
    created_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX videos_project_id ON videos (project_id);
CREATE INDEX videos_number ON videos (number);

CREATE TABLE tags (
    id    INTEGER PRIMARY KEY,
    name  TEXT    NOT NULL UNIQUE COLLATE NOCASE
);

CREATE TABLE video_tags (
    video_id  INTEGER NOT NULL REFERENCES videos (id) ON DELETE CASCADE,
    tag_id    INTEGER NOT NULL REFERENCES tags (id) ON DELETE CASCADE,
    PRIMARY KEY (video_id, tag_id)
);

CREATE INDEX video_tags_tag_id ON video_tags (tag_id);

CREATE TABLE versions (
    id               INTEGER PRIMARY KEY,
    video_id         INTEGER NOT NULL REFERENCES videos (id) ON DELETE CASCADE,
    number           INTEGER NOT NULL CHECK (number >= 1),            -- V1, V2…
    status           TEXT    NOT NULL DEFAULT 'in_progress'
                     CHECK (status IN ('in_progress', 'to_review', 'changes_requested', 'approved')),
    is_published     INTEGER NOT NULL DEFAULT 0 CHECK (is_published IN (0, 1)),
    file_path        TEXT    NULL,                 -- relative to data/
    original_name    TEXT    NULL,
    size_bytes       INTEGER NULL,
    duration_frames  INTEGER NULL,
    fps_num          INTEGER NULL CHECK (fps_num > 0),
    fps_den          INTEGER NULL CHECK (fps_den > 0),
    drop_frame       INTEGER NOT NULL DEFAULT 0 CHECK (drop_frame IN (0, 1)),
    start_frame      INTEGER NOT NULL DEFAULT 0,   -- start timecode, as a frame number
    timecode_source  TEXT    NULL CHECK (timecode_source IS NULL OR timecode_source IN ('tmcd', 'xmp', 'default', 'manual')),
    width            INTEGER NULL,
    height           INTEGER NULL,
    codec            TEXT    NULL,
    bit_rate         INTEGER NULL,
    poster_path      TEXT    NULL,
    poster_frame     INTEGER NULL,
    thumb_path       TEXT    NULL,
    sprite_path      TEXT    NULL,
    sprite_meta      TEXT    NULL,
    hls_path         TEXT    NULL,                 -- playback version (§5.7 bis)
    approved_by      TEXT    NULL,
    approved_at      TEXT    NULL,
    published_at     TEXT    NULL,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (video_id, number)
);

-- Revisions (the code says "comments").
CREATE TABLE comments (
    id                 INTEGER PRIMARY KEY,
    version_id         INTEGER NOT NULL REFERENCES versions (id) ON DELETE CASCADE,
    parent_id          INTEGER NULL REFERENCES comments (id) ON DELETE CASCADE,  -- replies: one level
    author_name        TEXT    NOT NULL,
    author_token_hash  TEXT    NULL,             -- browser token (localStorage), hashed
    is_admin           INTEGER NOT NULL DEFAULT 0 CHECK (is_admin IN (0, 1)),
    frame_in           INTEGER NOT NULL CHECK (frame_in >= 0),
    frame_out          INTEGER NULL CHECK (frame_out IS NULL OR frame_out >= frame_in),
    body               TEXT    NOT NULL,
    status             TEXT    NOT NULL DEFAULT 'todo' CHECK (status IN ('todo', 'done', 'refused')),
    is_read_by_admin   INTEGER NOT NULL DEFAULT 0 CHECK (is_read_by_admin IN (0, 1)),
    ip_hash            TEXT    NULL,
    created_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    deleted_at         TEXT    NULL
);

CREATE INDEX comments_version_id ON comments (version_id, frame_in);
CREATE INDEX comments_parent_id ON comments (parent_id);
CREATE INDEX comments_unread ON comments (is_read_by_admin) WHERE is_read_by_admin = 0;
CREATE INDEX comments_ip_hash ON comments (ip_hash, created_at);

CREATE TABLE collections (
    id               INTEGER PRIMARY KEY,
    organization_id  INTEGER NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    title            TEXT    NOT NULL,
    introduction     TEXT    NOT NULL DEFAULT '',
    bg_color         TEXT    NOT NULL DEFAULT '#0a0a0b',
    text_color       TEXT    NOT NULL DEFAULT '#fafafa',
    accent_color     TEXT    NOT NULL DEFAULT '#e8515f',
    logo             TEXT    NULL,               -- file under data/logos/
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX collections_organization_id ON collections (organization_id);

CREATE TABLE collection_videos (
    collection_id  INTEGER NOT NULL REFERENCES collections (id) ON DELETE CASCADE,
    video_id       INTEGER NOT NULL REFERENCES videos (id) ON DELETE CASCADE,
    position       INTEGER NOT NULL,
    PRIMARY KEY (collection_id, video_id)
);

CREATE INDEX collection_videos_video_id ON collection_videos (video_id);

-- Shared links: space, client (review), view, collection.
CREATE TABLE links (
    id             INTEGER PRIMARY KEY,
    type           TEXT    NOT NULL CHECK (type IN ('space', 'client', 'view', 'collection')),
    target_id      INTEGER NOT NULL,
    key            TEXT    NOT NULL UNIQUE CHECK (length(key) = 10),
    password_hash  TEXT    NULL,
    expires_at     TEXT    NULL,
    is_active      INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (type, target_id)
);

-- Chunked uploads (§5.7). AUTOINCREMENT: an id names a part file, never reused.
CREATE TABLE uploads (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT    NOT NULL,
    size_bytes      INTEGER NOT NULL CHECK (size_bytes > 0),
    fingerprint     TEXT    NOT NULL,            -- name + size + hash of first and last bytes
    bytes_received  INTEGER NOT NULL DEFAULT 0,
    video_id        INTEGER NULL REFERENCES videos (id) ON DELETE CASCADE,     -- target video
    version_id      INTEGER NULL REFERENCES versions (id) ON DELETE CASCADE,   -- playback file of a version
    target          TEXT    NULL,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX uploads_fingerprint ON uploads (fingerprint);

-- Journals and activity --------------------------------------------------------

CREATE TABLE audit_log (
    id               INTEGER PRIMARY KEY,
    organization_id  INTEGER NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    actor            TEXT    NOT NULL,
    action           TEXT    NOT NULL,
    details          TEXT    NOT NULL DEFAULT '',
    created_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX audit_log_organization_id ON audit_log (organization_id, created_at);

CREATE TABLE platform_log (
    id                 INTEGER PRIMARY KEY,
    organization_id    INTEGER NULL,          -- no foreign key: the organization may be gone
    organization_name  TEXT    NOT NULL,
    actor              TEXT    NOT NULL,
    action             TEXT    NOT NULL,
    details            TEXT    NOT NULL DEFAULT '',
    created_at         TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX platform_log_created_at ON platform_log (created_at);

CREATE TABLE activity (
    id               INTEGER PRIMARY KEY,
    organization_id  INTEGER NOT NULL REFERENCES organizations (id) ON DELETE CASCADE,
    client_id        INTEGER NOT NULL REFERENCES clients (id) ON DELETE CASCADE,
    project_id       INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    video_id         INTEGER NOT NULL REFERENCES videos (id) ON DELETE CASCADE,
    version_id       INTEGER NULL REFERENCES versions (id) ON DELETE SET NULL,
    version_number   INTEGER NULL,
    type             TEXT    NOT NULL CHECK (type IN ('version', 'revision', 'reply', 'approved', 'status')),
    actor            TEXT    NOT NULL DEFAULT '',
    is_admin         INTEGER NOT NULL DEFAULT 0 CHECK (is_admin IN (0, 1)),
    details          TEXT    NOT NULL DEFAULT '',
    created_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX activity_organization ON activity (organization_id, created_at);
CREATE INDEX activity_video ON activity (video_id, created_at);
CREATE INDEX activity_project ON activity (project_id, created_at);
CREATE INDEX activity_client ON activity (client_id, created_at);

-- Technical ------------------------------------------------------------------

CREATE TABLE settings (
    key    TEXT PRIMARY KEY,
    value  TEXT NOT NULL
);

CREATE TABLE login_attempts (
    scope            TEXT    NOT NULL,
    key_hash         TEXT    NOT NULL,
    failures         INTEGER NOT NULL DEFAULT 0,
    last_failure_at  INTEGER NOT NULL,           -- unix time
    blocked_until    INTEGER NOT NULL DEFAULT 0, -- unix time
    PRIMARY KEY (scope, key_hash)
);
