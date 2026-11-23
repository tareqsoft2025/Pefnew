-- 003: الطوارئ، SMS، المزامنة، الفحص والوقود
CREATE TABLE emergency_types (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL,
  name_ar VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_et (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE emergency_incidents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  incident_no VARCHAR(32) NOT NULL,
  client_event_id VARCHAR(64) NOT NULL,
  driver_id BIGINT UNSIGNED NOT NULL,
  vehicle_id BIGINT UNSIGNED NULL,
  mission_id BIGINT UNSIGNED NULL,
  trip_id BIGINT UNSIGNED NULL,
  type_id SMALLINT UNSIGNED NULL,
  status ENUM('new','acknowledged','in_progress','awaiting_update','resolved','closed','false_alarm') NOT NULL DEFAULT 'new',
  created_at_client DATETIME NOT NULL,
  received_at DATETIME NOT NULL,
  lat DECIMAL(9,6) NULL,
  lon DECIMAL(9,6) NULL,
  accuracy_m FLOAT NULL,
  location_at DATETIME NULL,
  location_is_last_known TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  channel ENUM('internet','sms_fallback') NOT NULL DEFAULT 'internet',
  acknowledged_by BIGINT UNSIGNED NULL,
  acknowledged_at DATETIME NULL,
  followup_user_id BIGINT UNSIGNED NULL,
  escalation_level TINYINT NOT NULL DEFAULT 0,
  closed_at DATETIME NULL,
  close_reason VARCHAR(500) NULL,
  team VARCHAR(64) NULL,
  region VARCHAR(64) NULL,
  fleet VARCHAR(64) NULL,
  UNIQUE KEY uq_inc_no (incident_no),
  UNIQUE KEY uq_inc_event (client_event_id),
  KEY ix_inc_status (status, received_at),
  KEY ix_inc_driver (driver_id, received_at),
  CONSTRAINT fk_inc_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inc_type FOREIGN KEY (type_id) REFERENCES emergency_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE emergency_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  incident_id BIGINT UNSIGNED NOT NULL,
  assignee_user_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL,
  CONSTRAINT fk_ea_inc FOREIGN KEY (incident_id) REFERENCES emergency_incidents(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE emergency_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  incident_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  action_type ENUM('call','message','dispatch','note','status_change','other') NOT NULL,
  details TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY ix_eact_inc (incident_id, created_at),
  CONSTRAINT fk_eact_inc FOREIGN KEY (incident_id) REFERENCES emergency_incidents(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تحديثات تُعرض للسائق في التطبيق
CREATE TABLE emergency_updates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  incident_id BIGINT UNSIGNED NOT NULL,
  author_user_id BIGINT UNSIGNED NOT NULL,
  message VARCHAR(1000) NOT NULL,
  visible_to_driver TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  KEY ix_eu_inc (incident_id, id),
  CONSTRAINT fk_eu_inc FOREIGN KEY (incident_id) REFERENCES emergency_incidents(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE emergency_attachments (
  incident_id BIGINT UNSIGNED NOT NULL,
  attachment_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (incident_id, attachment_id),
  CONSTRAINT fk_eatt_inc FOREIGN KEY (incident_id) REFERENCES emergency_incidents(id) ON DELETE RESTRICT,
  CONSTRAINT fk_eatt_att FOREIGN KEY (attachment_id) REFERENCES mission_attachments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE emergency_escalations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  incident_id BIGINT UNSIGNED NOT NULL,
  level TINYINT NOT NULL,
  notified_user_id BIGINT UNSIGNED NULL,
  reason VARCHAR(255) NOT NULL,
  escalated_at DATETIME NOT NULL,
  UNIQUE KEY uq_esc (incident_id, level),
  CONSTRAINT fk_esc_inc FOREIGN KEY (incident_id) REFERENCES emergency_incidents(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- حالات SMS مفصولة: طلب → قبول المزود → تسليم → استلام فعلي بالتطبيق
CREATE TABLE sms_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purpose ENUM('mission_alert','other') NOT NULL DEFAULT 'mission_alert',
  user_id BIGINT UNSIGNED NULL,
  mission_id BIGINT UNSIGNED NULL,
  mission_version INT UNSIGNED NULL,
  to_number VARCHAR(32) NOT NULL,
  body VARCHAR(480) NOT NULL,
  requested_at DATETIME NOT NULL,
  provider VARCHAR(32) NULL,
  provider_message_id VARCHAR(128) NULL,
  provider_accepted_at DATETIME NULL,
  delivered_at DATETIME NULL,
  failed_at DATETIME NULL,
  error VARCHAR(255) NULL,
  app_received_at DATETIME NULL,
  KEY ix_sms_mission (mission_id),
  KEY ix_sms_provider (provider, provider_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- idempotency للمزامنة: كل حدث يُعالج مرة واحدة ويُعاد نفس الرد
CREATE TABLE sync_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  event_id VARCHAR(64) NOT NULL,
  event_type VARCHAR(48) NOT NULL,
  device_id VARCHAR(100) NULL,
  client_ts DATETIME NOT NULL,
  status ENUM('applied','conflict','rejected') NOT NULL,
  result JSON NULL,
  processed_at DATETIME NOT NULL,
  UNIQUE KEY uq_sync (user_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- وحدات اختيارية قابلة للتفعيل من الإعدادات (feature.inspection / feature.fuel)
CREATE TABLE vehicle_inspections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id BIGINT UNSIGNED NULL,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  driver_id BIGINT UNSIGNED NOT NULL,
  phase ENUM('pre','post') NOT NULL,
  tires_ok TINYINT(1) NULL,
  lights_ok TINYINT(1) NULL,
  fuel_level ENUM('empty','quarter','half','three_quarter','full') NULL,
  damage_noted TINYINT(1) NULL,
  notes VARCHAR(1000) NULL,
  client_event_id VARCHAR(64) NOT NULL,
  inspected_at DATETIME NOT NULL,
  recorded_at DATETIME NOT NULL,
  UNIQUE KEY uq_insp_event (client_event_id),
  KEY ix_insp_vehicle (vehicle_id, inspected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  driver_id BIGINT UNSIGNED NULL,
  liters DECIMAL(8,2) NOT NULL,
  cost DECIMAL(10,2) NULL,
  odometer DECIMAL(10,1) NULL,
  filled_at DATETIME NOT NULL,
  notes VARCHAR(500) NULL,
  KEY ix_fuel_vehicle (vehicle_id, filled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
