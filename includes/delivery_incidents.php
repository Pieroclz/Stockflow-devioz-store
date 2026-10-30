<?php
declare(strict_types=1);
/** Registro permanente de incidencias, independiente de reasignaciones. */
function delivery_incidents_initialize(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS delivery_incidents (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        driver_id INT UNSIGNED NOT NULL,
        description VARCHAR(1000) NOT NULL,
        status ENUM('pendiente','en_revision','resuelta') NOT NULL DEFAULT 'pendiente',
        reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        resolution_notes VARCHAR(1000) NULL,
        decision VARCHAR(24) NULL,
        decided_at DATETIME NULL,
        INDEX idx_incident_reported (reported_at),
        INDEX idx_incident_status (status, reported_at),
        INDEX idx_incident_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Upgrade safe for existing installs; never remove existing incidents.
    $cols = $db->query("SHOW COLUMNS FROM delivery_incidents")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('decision', $cols, true)) $db->exec("ALTER TABLE delivery_incidents ADD COLUMN decision VARCHAR(24) NULL AFTER resolution_notes");
    if (!in_array('decided_at', $cols, true)) $db->exec("ALTER TABLE delivery_incidents ADD COLUMN decided_at DATETIME NULL AFTER decision");
    // Recuperar incidencias antiguas que aún permanecen en delivery_assignments.
    $db->exec("INSERT INTO delivery_incidents (order_id,driver_id,description,reported_at)
        SELECT a.order_id,a.driver_id,a.delivery_notes,COALESCE(a.finished_at,a.claimed_at)
        FROM delivery_assignments a
        WHERE a.delivery_status='failed' AND a.delivery_notes IS NOT NULL AND TRIM(a.delivery_notes)<>''
        AND NOT EXISTS (SELECT 1 FROM delivery_incidents i WHERE i.order_id=a.order_id AND i.driver_id=a.driver_id AND i.description COLLATE utf8mb4_unicode_ci = a.delivery_notes COLLATE utf8mb4_unicode_ci)");
}
