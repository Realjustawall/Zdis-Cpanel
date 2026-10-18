CREATE TABLE IF NOT EXISTS entities (
 id VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 kind VARCHAR(48) CHARACTER SET ascii NOT NULL,
 parent_id VARCHAR(192) CHARACTER SET ascii NOT NULL DEFAULT '',
 owner_id VARCHAR(64) CHARACTER SET ascii NOT NULL DEFAULT '',
 data LONGTEXT NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 INDEX entity_parent (kind,parent_id,created_at), INDEX entity_owner(kind,owner_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS accounts (
 id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 username VARCHAR(32) NOT NULL UNIQUE,
 email VARCHAR(254) NOT NULL UNIQUE,
 phone VARCHAR(24) NULL UNIQUE,
 password_hash VARCHAR(255) CHARACTER SET ascii NOT NULL,
 data LONGTEXT NOT NULL,
 created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS memberships (
 scope_id VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 data LONGTEXT NOT NULL,
 PRIMARY KEY(scope_id,user_id), INDEX member_user(user_id,scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sessions (
 token_hash CHAR(64) CHARACTER SET ascii PRIMARY KEY,
 id VARCHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
 user_id VARCHAR(64) CHARACTER SET ascii NOT NULL,
 csrf CHAR(64) CHARACTER SET ascii NOT NULL,
 data TEXT NOT NULL,
 expires_at BIGINT NOT NULL,
 INDEX session_user(user_id), INDEX session_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id VARCHAR(64) CHARACTER SET ascii NOT NULL,
 name VARCHAR(80) CHARACTER SET ascii NOT NULL,
 payload LONGTEXT NOT NULL,
 created_at BIGINT NOT NULL,
 INDEX event_user(user_id,id), INDEX event_expiry(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS rate_limits (
 bucket CHAR(64) CHARACTER SET ascii PRIMARY KEY,
 hits INT NOT NULL,
 expires_at BIGINT NOT NULL,
 INDEX rate_expiry(expires_at)
) ENGINE=InnoDB;
