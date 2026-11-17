<?php
// Shared helpers: how long an item stays posted (set by the admin) and the "nearly gone" pop-up.

function plural_label($n, $word) {
    return $n . ' ' . $word . ($n == 1 ? '' : 's');
}

function time_left_label($secs) {
    $secs = intval($secs);
    if ($secs <= 0) return 'less than an hour';
    if ($secs >= 86400) return plural_label((int)ceil($secs / 86400), 'day');
    return plural_label((int)ceil($secs / 3600), 'hour');
}

// Reads the settings (creates the table and the defaults the first time)
function retention_settings($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(50) NOT NULL PRIMARY KEY, setting_value VARCHAR(255) NOT NULL)");
    $conn->query("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('item_days', '30'), ('warn_days', '3')");

    $s = ['item_days' => 30, 'warn_days' => 3];
    $r = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('item_days', 'warn_days')");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $s[$row['setting_key']] = intval($row['setting_value']);
        }
    }
    if ($s['item_days'] < 1) $s['item_days'] = 30;
    if ($s['warn_days'] < 0) $s['warn_days'] = 0;
    return $s;
}

function save_retention($conn, $item_days, $warn_days) {
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if (!$stmt) return false;
    $ok = true;
    foreach (['item_days' => $item_days, 'warn_days' => $warn_days] as $key => $value) {
        $val = strval(intval($value));
        $stmt->bind_param("ss", $key, $val);
        if (!$stmt->execute()) $ok = false;
    }
    $stmt->close();
    return $ok;
}

function ensure_expired_item_notifications_table($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS item_expiry_notifications (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                admin_id INT NOT NULL,
                item_title VARCHAR(255) NOT NULL,
                serial_no VARCHAR(50) NOT NULL,
                expired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL,
                UNIQUE KEY unique_item_admin (item_id, admin_id)
            )";
    if (!$conn->query($sql)) {
        error_log("Could not prepare item expiry notifications: " . $conn->error);
        return false;
    }
    return true;
}

// Archives expired active items and records one durable notice for each admin.
function archive_expired_items($conn, $item_days) {
    $item_days = max(1, intval($item_days));
    if (!ensure_expired_item_notifications_table($conn) || !$conn->begin_transaction()) {
        error_log("Could not start item expiry processing: " . $conn->error);
        return false;
    }

    $expired = $conn->query(
        "SELECT id, title, DATE_ADD(created_at, INTERVAL $item_days DAY) AS expired_at,
                COALESCE(NULLIF(serial_no, ''), CONCAT('ITEM-', LPAD(id, 4, '0'))) AS serial_no
         FROM items
         WHERE status NOT IN ('Archived', 'Claimed')
           AND created_at < NOW() - INTERVAL $item_days DAY
         FOR UPDATE"
    );
    if (!$expired) {
        error_log("Could not load expired items: " . $conn->error);
        $conn->rollback();
        return false;
    }

    $items = [];
    while ($item = $expired->fetch_assoc()) {
        $items[] = $item;
    }
    if (empty($items)) {
        if (!$conn->commit()) {
            error_log("Could not finish item expiry check: " . $conn->error);
            $conn->rollback();
            return false;
        }
        return true;
    }

    $admins = $conn->query("SELECT DISTINCT id FROM users WHERE role = 'admin' OR usertype = 'admin'");
    if (!$admins) {
        error_log("Could not load administrators for item expiry notifications: " . $conn->error);
        $conn->rollback();
        return false;
    }
    $admin_ids = [];
    while ($admin = $admins->fetch_assoc()) {
        $admin_ids[] = intval($admin['id']);
    }
    if (empty($admin_ids)) {
        error_log("Expired items were not archived because no administrator could receive a notification.");
        $conn->rollback();
        return false;
    }

    $notification = $conn->prepare(
        "INSERT IGNORE INTO item_expiry_notifications (item_id, admin_id, item_title, serial_no, expired_at)
         VALUES (?, ?, ?, ?, ?)"
    );
    $archive = $conn->prepare("UPDATE items SET status = 'Archived' WHERE id = ? AND status NOT IN ('Archived', 'Claimed')");
    if (!$notification || !$archive) {
        error_log("Could not prepare item expiry updates: " . $conn->error);
        if ($notification) $notification->close();
        if ($archive) $archive->close();
        $conn->rollback();
        return false;
    }

    foreach ($items as $item) {
        $item_id = intval($item['id']);
        $title = $item['title'];
        $serial = $item['serial_no'];
        $expired_at = $item['expired_at'];
        foreach ($admin_ids as $admin_id) {
            $notification->bind_param("iisss", $item_id, $admin_id, $title, $serial, $expired_at);
            if (!$notification->execute()) {
                error_log("Could not save an item expiry notification: " . $notification->error);
                $notification->close();
                $archive->close();
                $conn->rollback();
                return false;
            }
        }
        $archive->bind_param("i", $item_id);
        if (!$archive->execute()) {
            error_log("Could not archive an expired item: " . $archive->error);
            $notification->close();
            $archive->close();
            $conn->rollback();
            return false;
        }
    }

    $notification->close();
    $archive->close();
    if (!$conn->commit()) {
        error_log("Could not commit item expiry updates: " . $conn->error);
        $conn->rollback();
        return false;
    }
    return true;
}

// Items that will be removed within the warning window (soonest first)
function expiring_items($conn, $item_days, $warn_days) {
    $list = [];
    $item_days = max(1, intval($item_days));
    $warn_days = intval($warn_days);
    if ($warn_days < 1) return $list;

    $sql = "SELECT id, serial_no, title,
                   TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(created_at, INTERVAL $item_days DAY)) AS secs_left
            FROM items
            WHERE status NOT IN ('Archived', 'Claimed')
              AND DATE_ADD(created_at, INTERVAL $item_days DAY) > NOW()
              AND DATE_ADD(created_at, INTERVAL $item_days DAY) <= DATE_ADD(NOW(), INTERVAL $warn_days DAY)
            ORDER BY secs_left ASC";
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) $list[] = $r;
    }
    return $list;
}

// Pop-up that says which items are nearly gone.
// $audience: 'admin' or 'student'. $on_admin_page: true on admin.php (shows the settings button).
function render_expiry_popup($items, $audience = 'student', $on_admin_page = false) {
    if (empty($items)) return;
    $ids = [];
    foreach ($items as $it) $ids[] = intval($it['id']);
    $sig = implode(',', $ids);
    $count = count($items);
    ?>
    <style>
        .expiry-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.55); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .expiry-card { background: #ffffff; width: 92%; max-width: 460px; max-height: 88vh; border-radius: 16px; box-shadow: 0 12px 35px rgba(0,0,0,0.3); overflow: hidden; display: flex; flex-direction: column; font-family: 'Segoe UI', Roboto, Arial, sans-serif; }
        .expiry-head { background: #b45309; color: #ffffff; padding: 14px 20px; font-size: 15px; font-weight: 700; }
        .expiry-body { padding: 16px 20px; overflow-y: auto; }
        .expiry-body p { margin: 0 0 12px 0; font-size: 12px; color: #475569; line-height: 1.5; }
        .expiry-item { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 9px 11px; margin-bottom: 8px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 8px; }
        .expiry-item strong { display: block; font-size: 12.5px; color: #1e293b; }
        .expiry-item small { font-size: 10px; font-weight: 800; color: #1b357a; letter-spacing: 0.3px; }
        .expiry-left { font-size: 11px; font-weight: 800; color: #991b1b; white-space: nowrap; }
        .expiry-view { background: #1b357a; color: #ffffff; border: none; border-radius: 6px; padding: 5px 10px; font-size: 11px; font-weight: bold; cursor: pointer; }
        .expiry-foot { padding: 12px 20px 16px; display: flex; gap: 8px; justify-content: flex-end; border-top: 1px solid #f1f5f9; }
        .expiry-foot button { border: none; border-radius: 8px; padding: 9px 18px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; }
        .expiry-ok { background: #1b357a; color: #ffffff; }
        .expiry-alt { background: #e2e8f0; color: #1e293b; }
    </style>

    <div class="expiry-overlay" id="expiryModal">
        <div class="expiry-card">
            <div class="expiry-head">Nearly gone: <?php echo $count; ?> item<?php echo $count == 1 ? '' : 's'; ?> will be removed soon</div>
            <div class="expiry-body">
                <?php if ($audience === 'admin'): ?>
                    <p>These items will be archived automatically when their posting time ends. If an item was already returned, mark it as claimed.</p>
                <?php else: ?>
                    <p>These items will be removed from the list soon. If one of them is yours, message the admin before it is gone.</p>
                <?php endif; ?>

                <?php foreach ($items as $it):
                    $serial = !empty($it['serial_no']) ? $it['serial_no'] : 'ITEM-' . str_pad($it['id'], 4, '0', STR_PAD_LEFT);
                ?>
                    <div class="expiry-item">
                        <div>
                            <small><?php echo htmlspecialchars($serial); ?></small>
                            <strong><?php echo htmlspecialchars($it['title']); ?></strong>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span class="expiry-left"><?php echo htmlspecialchars(time_left_label($it['secs_left'])); ?> left</span>
                            <?php if (!$on_admin_page): ?>
                                <button type="button" class="expiry-view" onclick="viewExpiringItem(<?php echo intval($it['id']); ?>)">View</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="expiry-foot">
                <?php if ($on_admin_page): ?>
                    <button type="button" class="expiry-alt" onclick="closeExpiryPopup(); toggleRetentionModal();">Change posting time</button>
                <?php endif; ?>
                <button type="button" class="expiry-ok" onclick="closeExpiryPopup()">OK</button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var sig = <?php echo json_encode($sig); ?>;
        var key = <?php echo json_encode('lfExpirySeen:' . $audience); ?>;
        var seen = null;
        try { seen = sessionStorage.getItem(key); } catch (e) {}
        var modal = document.getElementById('expiryModal');
        if (seen !== sig) modal.style.display = 'flex';

        window.closeExpiryPopup = function () {
            modal.style.display = 'none';
            try { sessionStorage.setItem(key, sig); } catch (e) {}
        };
        window.viewExpiringItem = function (id) {
            window.closeExpiryPopup();
            var el = document.getElementById('item_' + id);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.style.outline = '3px solid #f59e0b';
                setTimeout(function () { el.style.outline = ''; }, 2500);
            }
        };
    })();
    </script>
    <?php
}