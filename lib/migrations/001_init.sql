-- The password hash, the auth epoch and when they were set live in meta.
CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT);

CREATE TABLE login_attempts (
  id INTEGER PRIMARY KEY,
  ip TEXT NOT NULL,
  ok INTEGER NOT NULL,
  at INTEGER NOT NULL
);
CREATE INDEX login_attempts_ip ON login_attempts (ip, at);

-- One Instagram post, kept for good (the history). state: queued, running, done, error.
-- The files themselves are in data/posts/<code>/, the engine's progress in data/jobs/<code>.json.
CREATE TABLE posts (
  code TEXT PRIMARY KEY,
  kind TEXT NOT NULL DEFAULT 'p',
  url TEXT NOT NULL,
  user TEXT NOT NULL DEFAULT '',
  taken_at INTEGER,
  state TEXT NOT NULL,
  error_kind TEXT,
  error TEXT,
  mode TEXT,
  n_images INTEGER NOT NULL DEFAULT 0,
  n_videos INTEGER NOT NULL DEFAULT 0,
  bytes INTEGER NOT NULL DEFAULT 0,
  zip_name TEXT,
  zip_bytes INTEGER,
  created_at INTEGER NOT NULL,
  requested_at INTEGER NOT NULL,
  fetched_at INTEGER
);
CREATE INDEX posts_requested ON posts (requested_at);

CREATE TABLE files (
  code TEXT NOT NULL REFERENCES posts (code) ON DELETE CASCADE,
  n INTEGER NOT NULL,
  name TEXT NOT NULL,
  type TEXT NOT NULL,
  bytes INTEGER NOT NULL,
  w INTEGER NOT NULL DEFAULT 0,
  h INTEGER NOT NULL DEFAULT 0,
  thumb TEXT,
  PRIMARY KEY (code, n)
);
