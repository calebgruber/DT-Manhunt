-- DT-Manhunt starter schema (MySQL 8+)

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone VARCHAR(32) NOT NULL,
  pin_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  full_name VARCHAR(220) NOT NULL,
  graduation_year CHAR(4) NOT NULL,
  concentration VARCHAR(160) NOT NULL,
  mode ENUM('solo','duo') NULL,
  registration_step ENUM('profile','mode','matchmaking','payment','complete') NOT NULL DEFAULT 'profile',
  teammate_user_id BIGINT UNSIGNED NULL,
  payment_status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_phone (phone),
  KEY idx_users_step_mode (registration_step, mode),
  KEY idx_users_teammate (teammate_user_id),
  CONSTRAINT fk_users_teammate FOREIGN KEY (teammate_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  inviter_user_id BIGINT UNSIGNED NOT NULL,
  invitee_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_invites_inviter_status (inviter_user_id, status),
  KEY idx_invites_invitee_status (invitee_user_id, status),
  CONSTRAINT fk_invites_inviter FOREIGN KEY (inviter_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_invites_invitee FOREIGN KEY (invitee_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
