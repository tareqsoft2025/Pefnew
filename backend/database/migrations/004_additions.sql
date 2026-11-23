-- 004: إضافات: أسماء مستعارة للرحلات، سياسة صورة العداد، حالة الصورة، تنبيه القراءة المعلقة
CREATE TABLE trip_aliases (
  driver_id BIGINT UNSIGNED NOT NULL,
  client_trip_id VARCHAR(64) NOT NULL,
  trip_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (driver_id, client_trip_id),
  CONSTRAINT fk_ta_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE missions ADD COLUMN odometer_photo_required TINYINT(1) NOT NULL DEFAULT 0 AFTER proof_policy;
ALTER TABLE vehicle_odometer_readings ADD COLUMN photo_status ENUM('not_required','pending','received') NOT NULL DEFAULT 'not_required';
ALTER TABLE vehicle_segments ADD COLUMN pending_notified TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE vehicle_segments ADD COLUMN switch_confirmed_at DATETIME NULL;
ALTER TABLE emergency_incidents ADD COLUMN device_id VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN pending_mfa_secret VARCHAR(255) NULL;
