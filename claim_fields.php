<?php
function ensure_item_claims_table($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS item_claims (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                claimant_name VARCHAR(255) NOT NULL,
                claimant_year VARCHAR(100) NULL,
                claimant_contact VARCHAR(255) NULL,
                claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_item_claim (item_id)
            )";
    if (!$conn->query($sql)) {
        error_log("Could not prepare item claims table: " . $conn->error);
        return false;
    }

    $result = $conn->query("SHOW COLUMNS FROM items");
    if (!$result) {
        error_log("Could not inspect legacy item claim columns: " . $conn->error);
        return false;
    }

    $existing = [];
    while ($column = $result->fetch_assoc()) {
        $existing[$column['Field']] = true;
    }

    $legacy_columns = ['claimant_name', 'claimant_year', 'claimant_contact', 'claimed_at'];
    foreach ($legacy_columns as $name) {
        if (!isset($existing[$name])) {
            return true;
        }
    }

    $migration = $conn->query(
        "INSERT IGNORE INTO item_claims (item_id, claimant_name, claimant_year, claimant_contact, claimed_at)
         SELECT id, claimant_name, claimant_year, NULLIF(claimant_contact, ''), COALESCE(claimed_at, NOW())
         FROM items
         WHERE status = 'Claimed' AND claimant_name IS NOT NULL AND claimant_name <> ''"
    );
    if (!$migration) {
        error_log("Could not migrate legacy item claim details: " . $conn->error);
        return false;
    }

    return true;
}
