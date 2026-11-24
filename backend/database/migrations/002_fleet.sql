-- 002_fleet: السائقون، المركبات، المهام، المسارات، الرحلات
CREATE TABLE drivers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  license_no VARCHAR(64) NULL,
  license_expiry DATE NULL,
  team VARCHAR(64) NULL,
  region VARCHAR(64) NULL,
  fleet VARCHAR(64) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_driver_user (user_id),
  KEY ix_driver_scope (team, region, fleet),
  CONSTRAINT fk_driver_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  internal_no VARCHAR(32) NOT NULL,
  plate_no VARCHAR(32) NOT NULL,
  vehicle_type VARCHAR(64) NOT NULL,
  model VARCHAR(96) NULL,
  year SMALLINT NULL,
  status ENUM('operational','maintenance','out_of_service') NOT NULL DEFAULT 'operational',
  license_expiry DATE NULL,
  next_maintenance_date DATE NULL,
  require_odometer_photo TINYINT(1) NOT NULL DEFAULT 0,
  last_odometer DECIMAL(10,1) NULL,
  team VARCHAR(64) NULL,
  region VARCHAR(64) NULL,
  fleet VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_vehicle_no (internal_no),
  UNIQUE KEY uq_vehicle_plate (plate_no),
  KEY ix_vehicle_scope (team, region, fleet)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE missions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mission_no VARCHAR(32) NOT NULL,
  type VARCHAR(48) NOT NULL DEFAULT 'transport',
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  status ENUM('draft','sent','delivered','accepted','rejected','active','arrived','completion_pending','completed','cancelled') NOT NULL DEFAULT 'draft',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  scheduled_start DATETIME NOT NULL,
  scheduled_end DATETIME NULL,
  origin_name VARCHAR(200) NULL,
  origin_lat DECIMAL(9,6) NULL,
  origin_lon DECIMAL(9,6) NULL,
  dest_name VARCHAR(200) NULL,
  dest_lat DECIMAL(9,6) NULL,
  dest_lon DECIMAL(9,6) NULL,
  arrival_radius_m INT NOT NULL DEFAULT 150,
  party_info TEXT NULL,
  instructions TEXT NULL,
  proof_policy ENUM('optional','photo_required') NOT NULL DEFAULT 'optional',
  driver_id BIGINT UNSIGNED NULL,
  vehicle_id BIGINT UNSIGNED NULL,
  team VARCHAR(64) NULL,
  region VARCHAR(64) NULL,
  fleet VARCHAR(64) NULL,
  rejection_reason VARCHAR(500) NULL,
  cancel_reason VARCHAR(500) NULL,
  delivered_at DATETIME NULL,
  accepted_at DATETIME NULL,
  arrived_at DATETIME NULL,
  completed_at DATETIME NULL,
  completion_notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_mission_no (mission_no),
  KEY ix_mission_driver (driver_id, status),
  KEY ix_mission_vehicle (vehicle_id, scheduled_start),
  KEY ix_mission_status (status, scheduled_start),
  KEY ix_mission_scope (team, region, fleet),
  CONSTRAINT fk_mission_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
  CONSTRAINT fk_mission_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
  CONSTRAINT fk_mission_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mission_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mission_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(32) NULL,
  to_status VARCHAR(32) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  note VARCHAR(500) NULL,
  occurred_at DATETIME NOT NULL,
  recorded_at DATETIME NOT NULL,
  KEY ix_me_mission (mission_id, occurred_at),
  CONSTRAINT fk_me_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mission_stops (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mission_id BIGINT UNSIGNED NOT NULL,
  seq SMALLINT NOT NULL,
  name VARCHAR(200) NOT NULL,
  lat DECIMAL(9,6) NOT NULL,
  lon DECIMAL(9,6) NOT NULL,
  UNIQUE KEY uq_ms_seq (mission_id, seq),
  CONSTRAINT fk_ms_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجل الإسناد: سائق/مركبة لكل مهمة، التغييرات موثقة
CREATE TABLE mission_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mission_id BIGINT UNSIGNED NOT NULL,
  driver_id BIGINT UNSIGNED NULL,
  assigned_by BIGINT UNSIGNED NULL,
  reason VARCHAR(500) NULL,
  assigned_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  KEY ix_ma_mission (mission_id),
  CONSTRAINT fk_ma_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicle_assignment_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mission_id BIGINT UNSIGNED NOT NULL,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  driver_id BIGINT UNSIGNED NULL,
  assigned_by BIGINT UNSIGNED NULL,
  reason VARCHAR(500) NULL,
  conflict_exception_reason VARCHAR(500) NULL,
  assigned_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  driver_confirmed_at DATETIME NULL,
  KEY ix_vah_vehicle (vehicle_id, assigned_at),
  KEY ix_vah_mission (mission_id),
  CONSTRAINT fk_vah_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE,
  CONSTRAINT fk_vah_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mission_responses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mission_id BIGINT UNSIGNED NOT NULL,
  driver_id BIGINT UNSIGNED NOT NULL,
  response ENUM('accepted','rejected') NOT NULL,
  reason VARCHAR(500) NULL,
  responded_at DATETIME NOT NULL,
  recorded_at DATETIME NOT NULL,
  KEY ix_mr_mission (mission_id),
  CONSTRAINT fk_mr_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إصدارات المسار؛ geometry = JSON [[lat,lon],...]
CREATE TABLE mission_routes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mission_id BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  source ENUM('auto','manual') NOT NULL,
  provider VARCHAR(32) NULL,
  geometry MEDIUMTEXT NOT NULL,
  distance_m INT NULL,
  duration_s INT NULL,
  status ENUM('proposed','approved','superseded') NOT NULL DEFAULT 'proposed',
  created_by BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_mroute (mission_id, version),
  CONSTRAINT fk_mroute_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE route_waypoints (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_id BIGINT UNSIGNED NOT NULL,
  seq SMALLINT NOT NULL,
  lat DECIMAL(9,6) NOT NULL,
  lon DECIMAL(9,6) NOT NULL,
  label VARCHAR(160) NULL,
  UNIQUE KEY uq_rw (route_id, seq),
  CONSTRAINT fk_rw_route FOREIGN KEY (route_id) REFERENCES mission_routes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mission_attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_type ENUM('mission_proof','odometer','emergency','inspection') NOT NULL,
  owner_id BIGINT UNSIGNED NOT NULL,
  uploader_user_id BIGINT UNSIGNED NOT NULL,
  client_file_id VARCHAR(64) NOT NULL,
  original_name VARCHAR(190) NULL,
  mime VARCHAR(64) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  captured_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_att_client (uploader_user_id, client_file_id),
  KEY ix_att_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الرحلة الفعلية: سائق واحد، رحلة مفتوحة واحدة في الوقت نفسه
CREATE TABLE trips (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_id BIGINT UNSIGNED NOT NULL,
  client_trip_id VARCHAR(64) NOT NULL,
  status ENUM('active','closed') NOT NULL DEFAULT 'active',
  open_flag TINYINT(1) GENERATED ALWAYS AS (CASE WHEN status='active' THEN 1 ELSE NULL END) VIRTUAL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  distance_gps_m INT NOT NULL DEFAULT 0,
  last_point_at DATETIME NULL,
  last_lat DECIMAL(9,6) NULL,
  last_lon DECIMAL(9,6) NULL,
  last_received_at DATETIME NULL,
  last_accuracy_m FLOAT NULL,
  point_count INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_trip_client (driver_id, client_trip_id),
  UNIQUE KEY uq_trip_open (driver_id, open_flag),
  KEY ix_trip_status (status, last_received_at),
  CONSTRAINT fk_trip_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_missions (
  trip_id BIGINT UNSIGNED NOT NULL,
  mission_id BIGINT UNSIGNED NOT NULL,
  attached_at DATETIME NOT NULL,
  detached_at DATETIME NULL,
  PRIMARY KEY (trip_id, mission_id),
  UNIQUE KEY uq_tm_mission (mission_id),
  CONSTRAINT fk_tm_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_tm_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- مرحلة استخدام مركبة داخل رحلة
CREATE TABLE vehicle_segments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id BIGINT UNSIGNED NOT NULL,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  seq SMALLINT NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  start_odometer DECIMAL(10,1) NULL,
  end_odometer DECIMAL(10,1) NULL,
  odometer_pending TINYINT(1) NOT NULL DEFAULT 1,
  gps_distance_m INT NOT NULL DEFAULT 0,
  odometer_distance_m INT NULL,
  review_flag TINYINT(1) NOT NULL DEFAULT 0,
  change_reason VARCHAR(500) NULL,
  approved_by BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_seg (trip_id, seq),
  KEY ix_seg_vehicle (vehicle_id, status),
  CONSTRAINT fk_seg_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_seg_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicle_odometer_readings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  segment_id BIGINT UNSIGNED NOT NULL,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('start','end') NOT NULL,
  value DECIMAL(10,1) NOT NULL,
  measured_at DATETIME NOT NULL,
  entered_at DATETIME NOT NULL,
  synced_at DATETIME NOT NULL,
  client_event_id VARCHAR(64) NOT NULL,
  attachment_id BIGINT UNSIGNED NULL,
  decrease_exception_reason VARCHAR(500) NULL,
  exception_by BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_odo_event (client_event_id),
  UNIQUE KEY uq_odo_seg_kind (segment_id, kind),
  KEY ix_odo_vehicle (vehicle_id, measured_at),
  CONSTRAINT fk_odo_seg FOREIGN KEY (segment_id) REFERENCES vehicle_segments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- نقاط GPS: المعرف الفريد يمنع التكرار عند إعادة المزامنة
CREATE TABLE gps_points (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id BIGINT UNSIGNED NOT NULL,
  client_point_id VARCHAR(64) NOT NULL,
  captured_at DATETIME(3) NOT NULL,
  lat DECIMAL(9,6) NOT NULL,
  lon DECIMAL(9,6) NOT NULL,
  accuracy_m FLOAT NULL,
  speed_mps FLOAT NULL,
  bearing FLOAT NULL,
  source ENUM('gps','network','fused','manual') NOT NULL DEFAULT 'fused',
  uploaded_at DATETIME NOT NULL,
  UNIQUE KEY uq_gps_client (trip_id, client_point_id),
  KEY ix_gps_trip_time (trip_id, captured_at),
  CONSTRAINT fk_gps_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stop_reasons (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL,
  name_ar VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_sr (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- توقف واحد على مستوى الرحلة
CREATE TABLE trip_stops (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id BIGINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  duration_s INT NOT NULL DEFAULT 0,
  lat DECIMAL(9,6) NOT NULL,
  lon DECIMAL(9,6) NOT NULL,
  status ENUM('ongoing','ended') NOT NULL DEFAULT 'ongoing',
  KEY ix_stop_trip (trip_id, started_at),
  CONSTRAINT fk_stop_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trip_stop_missions (
  stop_id BIGINT UNSIGNED NOT NULL,
  mission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (stop_id, mission_id),
  CONSTRAINT fk_tsm_stop FOREIGN KEY (stop_id) REFERENCES trip_stops(id) ON DELETE CASCADE,
  CONSTRAINT fk_tsm_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stop_explanations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stop_id BIGINT UNSIGNED NOT NULL,
  reason_id SMALLINT UNSIGNED NOT NULL,
  notes VARCHAR(500) NULL,
  client_event_id VARCHAR(64) NOT NULL,
  explained_at DATETIME NOT NULL,
  recorded_at DATETIME NOT NULL,
  UNIQUE KEY uq_se_event (client_event_id),
  UNIQUE KEY uq_se_stop (stop_id),
  CONSTRAINT fk_se_stop FOREIGN KEY (stop_id) REFERENCES trip_stops(id) ON DELETE CASCADE,
  CONSTRAINT fk_se_reason FOREIGN KEY (reason_id) REFERENCES stop_reasons(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- level: record(3د) | prompt(5د) | alert(10د). event_time هو وقت الحدث الأصلي.
CREATE TABLE stop_alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stop_id BIGINT UNSIGNED NOT NULL,
  level ENUM('prompt','alert') NOT NULL,
  event_time DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_sa (stop_id, level),
  CONSTRAINT fk_sa_stop FOREIGN KEY (stop_id) REFERENCES trip_stops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE route_deviations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id BIGINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  max_distance_m INT NOT NULL,
  lat DECIMAL(9,6) NOT NULL,
  lon DECIMAL(9,6) NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  reason VARCHAR(500) NULL,
  reason_at DATETIME NULL,
  review_status ENUM('pending','accepted','flagged') NOT NULL DEFAULT 'pending',
  reviewed_by BIGINT UNSIGNED NULL,
  KEY ix_dev_trip (trip_id, started_at),
  CONSTRAINT fk_dev_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
