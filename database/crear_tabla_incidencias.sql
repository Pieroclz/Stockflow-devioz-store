CREATE TABLE IF NOT EXISTS delivery_incidents (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 driver_id INT UNSIGNED NOT NULL,
 description VARCHAR(1000) NOT NULL,
 status ENUM('pendiente','en_revision','resuelta') NOT NULL DEFAULT 'pendiente',
 reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_at DATETIME NULL,
 resolution_notes VARCHAR(1000) NULL,
 INDEX idx_incident_reported (reported_at),
 INDEX idx_incident_status (status, reported_at),
 INDEX idx_incident_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
