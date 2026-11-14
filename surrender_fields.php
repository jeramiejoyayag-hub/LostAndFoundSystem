<?php
function ensure_item_surrender_columns($conn) {
    $result = $conn->query("SHOW COLUMNS FROM items");
    if (!$result) return false;

    $existing = [];
    while ($column = $result->fetch_assoc()) {
        $existing[$column['Field']] = true;
    }

    $columns = [
        'surrendered_by' => 'VARCHAR(255) NULL',
        'surrendered_to' => 'VARCHAR(255) NULL',
        'surrendered_at' => 'DATETIME NULL',
        'surrenderer_contact' => 'VARCHAR(50) NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (!isset($existing[$name]) && !$conn->query("ALTER TABLE items ADD COLUMN $name $definition")) {
            return false;
        }
    }
    return true;
}
