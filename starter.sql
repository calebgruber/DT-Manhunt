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
  payment_status ENUM('pending','submitted','approved') NOT NULL DEFAULT 'pending',
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_enrolled TINYINT(1) NOT NULL DEFAULT 1,
  game_status ENUM('in','eliminated','seeker','withdrawn') NOT NULL DEFAULT 'in',
  pending_alert TEXT NOT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  location_accuracy DECIMAL(10,2) NULL,
  location_updated_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_phone (phone),
  KEY idx_users_step_mode (registration_step, mode),
  KEY idx_users_teammate (teammate_user_id),
  KEY idx_users_enrolled_status (is_enrolled, game_status),
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

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sender_admin_user_id BIGINT UNSIGNED NULL,
  recipient_scope VARCHAR(60) NOT NULL,
  body TEXT NOT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_messages_created (created_at),
  CONSTRAINT fk_messages_admin FOREIGN KEY (sender_admin_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_recipients (
  message_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  PRIMARY KEY (message_id, user_id),
  KEY idx_message_recipients_user_read (user_id, is_read),
  CONSTRAINT fk_message_recipients_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_message_recipients_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incidents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_user_id BIGINT UNSIGNED NOT NULL,
  incident_type VARCHAR(120) NOT NULL,
  severity ENUM('low','medium','high','emergency') NOT NULL,
  details TEXT NOT NULL,
  status ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_incidents_status_created (status, created_at),
  CONSTRAINT fk_incidents_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
