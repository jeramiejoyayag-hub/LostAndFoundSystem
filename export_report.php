<?php
session_start();
include 'db.php';
include_once 'missing_item_alerts.php';
include_once 'surrender_fields.php';
date_default_timezone_set('Asia/Manila');

// Admin only
$role = $_SESSION['role'] ?? $_SESSION['usertype'] ?? '';
if (!isset($_SESSION['user_id']) || $role !== 'admin') {
    http_response_code(403);
    exit('Only the admin can download reports.');
}

if (!ensure_missing_item_reports_table($conn) || !ensure_item_surrender_columns($conn)) {
    error_log("Could not prepare report analytics fields: " . $conn->error);
    http_response_code(500);
    exit('Report analytics are temporarily unavailable.');
}

// Optional filters: ?status=all|unclaimed|claimed|archived  &from=YYYY-MM-DD  &to=YYYY-MM-DD
$filter = strtolower($_GET['status'] ?? 'all');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$where = [];
$params = [];
$types = '';
if ($filter === 'claimed') {
    $where[] = "status = 'Claimed'";
} elseif ($filter === 'archived') {
    $where[] = "status = 'Archived'";
} elseif ($filter === 'unclaimed') {
    $where[] = "status NOT IN ('Claimed', 'Archived')";
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = "created_at >= ?";
    $params[] = $from . ' 00:00:00';
    $types .= 's';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = "created_at <= ?";
    $params[] = $to . ' 23:59:59';
    $types .= 's';
}
$sql = "SELECT * FROM items" . ($where ? " WHERE " . implode(' AND ', $where) : "") . " ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// Build rows and totals
$item_rows = [];
$t = ['total' => 0, 'missing' => 0, 'found' => 0, 'claimed' => 0, 'unclaimed' => 0, 'archived' => 0];
while ($r = $res->fetch_assoc()) {
    $type_src = $r['item_type'] ?? '';
    if ($type_src === '') {
        $type_src = in_array(strtolower($r['status']), ['missing', 'found']) ? $r['status'] : 'Missing';
    }
    $type = (strtolower($type_src) === 'found') ? 'Found' : 'Missing';
    $serial = !empty($r['serial_no']) ? $r['serial_no'] : 'ITEM-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT);

    if ($r['status'] === 'Claimed') $status_label = 'Claimed';
    elseif ($r['status'] === 'Archived') $status_label = 'Archived';
    else $status_label = 'Unclaimed';

    $t['total']++;
    if ($type === 'Found') $t['found']++; else $t['missing']++;
    if ($status_label === 'Claimed') $t['claimed']++;
    elseif ($status_label === 'Archived') $t['archived']++;
    else $t['unclaimed']++;

    $item_rows[] = [
        $serial,
        $r['title'],
        $r['description'],
        $r['location'],
        $type,
        $status_label,
        date('M d, Y h:i A', strtotime($r['created_at'])),
    ];
}

$valid_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '';
$valid_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : '';
$analytics_date_conditions = [];
$analytics_date_params = [];
$analytics_date_types = '';
if ($valid_from !== '') {
    $analytics_date_conditions[] = 'created_at >= ?';
    $analytics_date_params[] = $valid_from . ' 00:00:00';
    $analytics_date_types .= 's';
}
if ($valid_to !== '') {
    $analytics_date_conditions[] = 'created_at <= ?';
    $analytics_date_params[] = $valid_to . ' 23:59:59';
    $analytics_date_types .= 's';
}

function track_monthly_report_leader(&$monthly_stats, $period, $title, $count, $metric) {
    if ($period === null || $period === '') return;
    if (!isset($monthly_stats[$period])) {
        $monthly_stats[$period] = [
            'missing' => ['count' => 0, 'titles' => []],
            'surrendered' => ['count' => 0, 'titles' => []],
        ];
    }

    $count = intval($count);
    if ($count > $monthly_stats[$period][$metric]['count']) {
        $monthly_stats[$period][$metric] = ['count' => $count, 'titles' => [$title]];
    } elseif ($count === $monthly_stats[$period][$metric]['count']
        && !in_array($title, $monthly_stats[$period][$metric]['titles'], true)) {
        $monthly_stats[$period][$metric]['titles'][] = $title;
    }
}

function report_top_group($conn, $column, $date_conditions, $date_types, $date_params) {
    if (!in_array($column, ['year_level', 'department'], true)) return false;

    $conditions = ["$column IS NOT NULL", "TRIM($column) <> ''"];
    $conditions = array_merge($conditions, $date_conditions);
    $sql = "SELECT $column AS label, COUNT(*) AS total "
         . "FROM missing_item_reports WHERE " . implode(' AND ', $conditions)
         . " GROUP BY $column ORDER BY total DESC, $column ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    if ($date_params && !$stmt->bind_param($date_types, ...$date_params)) {
        $stmt->close();
        return false;
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }

    $result = $stmt->get_result();
    $leaders = [];
    $top_count = null;
    while ($row = $result->fetch_assoc()) {
        $count = intval($row['total']);
        if ($top_count === null) {
            $top_count = $count;
        } elseif ($count < $top_count) {
            break;
        }
        $leaders[] = $row['label'];
    }
    $stmt->close();

    return ['leaders' => $leaders, 'count' => $top_count ?? 0];
}

$monthly_stats = [];
$missing_sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') AS period, title, COUNT(*) AS total "
             . "FROM missing_item_reports"
             . ($analytics_date_conditions ? " WHERE " . implode(' AND ', $analytics_date_conditions) : "")
             . " GROUP BY DATE_FORMAT(created_at, '%Y-%m'), title"
             . " ORDER BY period DESC, total DESC, title ASC";
$missing_stmt = $conn->prepare($missing_sql);
if (!$missing_stmt) {
    error_log("Could not prepare monthly missing-item report analytics: " . $conn->error);
    http_response_code(500);
    exit('Report analytics are temporarily unavailable.');
}
if ($analytics_date_params && !$missing_stmt->bind_param($analytics_date_types, ...$analytics_date_params)) {
    error_log("Could not bind monthly missing-item report filters: " . $missing_stmt->error);
    $missing_stmt->close();
    http_response_code(500);
    exit('Report analytics are temporarily unavailable.');
}
if (!$missing_stmt->execute()) {
    error_log("Could not load monthly missing-item report analytics: " . $missing_stmt->error);
    $missing_stmt->close();
    http_response_code(500);
    exit('Report analytics are temporarily unavailable.');
}
$missing_result = $missing_stmt->get_result();
while ($row = $missing_result->fetch_assoc()) {
    track_monthly_report_leader($monthly_stats, $row['period'], $row['title'], $row['total'], 'missing');
}
$missing_stmt->close();

$surrender_date_expression = 'COALESCE(surrendered_at, created_at)';
$surrender_conditions = ["surrendered_by IS NOT NULL", "TRIM(surrendered_by) <> ''"];
$surrender_params = [];
$surrender_types = '';
if ($valid_from !== '') {
    $surrender_conditions[] = "$surrender_date_expression >= ?";
    $surrender_params[] = $valid_from . ' 00:00:00';
    $surrender_types .= 's';
}
if ($valid_to !== '') {
    $surrender_conditions[] = "$surrender_date_expression <= ?";
    $surrender_params[] = $valid_to . ' 23:59:59';
    $surrender_types .= 's';
}
$surrender_sql = "SELECT DATE_FORMAT($surrender_date_expression, '%Y-%m') AS period, title, COUNT(*) AS total "
               . "FROM items WHERE " . implode(' AND ', $surrender_conditions)
               . " GROUP BY DATE_FORMAT($surrender_date_expression, '%Y-%m'), title"
               . " ORDER BY period DESC, total DESC, title ASC";
$surrender_stmt = $conn->prepare($surrender_sql);
if (!$surrender_stmt) {
    error_log("Could not prepare monthly surrendered-item analytics: " . $conn->error);
    http_response_code(500);
    exit('Report analytics are temporarily unavailable.');
}
if ($surrender_params && !$surrender_stmt->bind_param($surrender_types, ...$surrender_params)) {
    error_log("Could not bind monthly surrendered-item filters: " . $surrender_stmt->error);
    $surrender_stmt->close();
    http_response_code(500);
    exit('Report analytics are temporarily unavailable.');
}
if (!$surrender_stmt->execute()) {
    error_log("Could not load monthly surrendered-item analytics: " . $surrender_stmt->error);
    $surrender_stmt->close();
    http_response_code(500);
    exit('Report analytics are temporarily unavailable.');
}
$surrender_result = $surrender_stmt->get_result();
while ($row = $surrender_result->fetch_assoc()) {
    track_monthly_report_leader($monthly_stats, $row['period'], $row['title'], $row['total'], 'surrendered');
}
$surrender_stmt->close();

$year_leaders = report_top_group($conn, 'year_level', $analytics_date_conditions, $analytics_date_types, $analytics_date_params);
$department_leaders = report_top_group($conn, 'department', $analytics_date_conditions, $analytics_date_types, $analytics_date_params);
if ($year_leaders === false || $department_leaders === false) {
    error_log("Could not load year or department report analytics: " . $conn->error);
    http_response_code(500);
    exit('Report analytics are temporarily unavailable.');
}

// ---------------------------------------------------------- minimal XLSX writer (no ZipArchive needed)
function col_letter($i) {
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - $m - 1, 26);
    }
    return $s;
}
function xml_text($v) {
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$v);
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
// $rows: list of rows; a cell is a scalar or [value, styleIndex]. Styles: 0 normal, 1 header, 2 title
function sheet_xml($rows, $widths) {
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    if ($widths) {
        $x .= '<cols>';
        foreach ($widths as $i => $w) {
            $n = $i + 1;
            $x .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"/>';
        }
        $x .= '</cols>';
    }
    $x .= '<sheetData>';
    foreach ($rows as $ri => $row) {
        $x .= '<row r="' . ($ri + 1) . '">';
        foreach ($row as $ci => $cell) {
            $style = 0;
            $val = $cell;
            if (is_array($cell)) { $val = $cell[0]; $style = $cell[1]; }
            $ref = col_letter($ci) . ($ri + 1);
            if ($val === '' || $val === null) {
                $x .= '<c r="' . $ref . '" s="' . $style . '"/>';
            } elseif (is_int($val) || is_float($val)) {
                $x .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $val . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xml_text($val) . '</t></is></c>';
            }
        }
        $x .= '</row>';
    }
    return $x . '</sheetData></worksheet>';
}

// Builds a ZIP file in memory (stored, no compression). An .xlsx file is a ZIP file.
function build_zip($files) {
    $d = getdate();
    $dos_time = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
    $dos_date = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];

    $data = '';
    $central = '';
    $count = 0;
    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $size = strlen($content);
        $name_len = strlen($name);
        $offset = strlen($data);

        $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dos_time, $dos_date, $crc, $size, $size, $name_len, 0)
               . $name . $content;

        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dos_time, $dos_date, $crc, $size, $size, $name_len, 0, 0, 0, 0, 32, $offset)
                  . $name;
        $count++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($data), 0);
    return $data . $central . $end;
}

// Summary sheet
$filter_label = ($filter === 'all' || $filter === '') ? 'All items' : ucfirst($filter) . ' items';
$range_label = ($valid_from || $valid_to) ? (($valid_from ?: 'start') . ' to ' . ($valid_to ?: 'today')) : 'All dates';
$summary = [
    [['LCUP Lost & Found Report', 2]],
    ['Generated on', date('M d, Y h:i A')],
    ['Filter', $filter_label],
    ['Date range', $range_label],
    [''],
    [['Category', 1], ['Count', 1]],
    ['Total items', $t['total']],
    ['Missing', $t['missing']],
    ['Found', $t['found']],
    ['Claimed (returned)', $t['claimed']],
    ['Unclaimed', $t['unclaimed']],
    ['Archived', $t['archived']],
];

// Items sheet
$items_sheet = [[
    ['Serial No.', 1], ['Title', 1], ['Description', 1], ['Location', 1],
    ['Type', 1], ['Status', 1], ['Date Posted', 1],
]];
foreach ($item_rows as $r) $items_sheet[] = $r;

$analytics_sheet = [
    ['Date range', $range_label],
    ['Missing items are grouped by report submission month; surrendered items by surrender date, or post date if missing.'],
    [''],
    [['Month', 1], ['Most Reported Missing Item(s)', 1], ['Missing Reports', 1], ['Most Surrendered Item(s)', 1], ['Items Surrendered', 1]],
];
$months = array_keys($monthly_stats);
rsort($months);
if (!$months) {
    $analytics_sheet[] = ['No monthly missing-item reports or surrendered items are available.'];
} else {
    foreach ($months as $month) {
        $missing = $monthly_stats[$month]['missing'];
        $surrendered = $monthly_stats[$month]['surrendered'];
        $analytics_sheet[] = [
            date('F Y', strtotime($month . '-01')),
            $missing['titles'] ? implode('; ', $missing['titles']) : 'No reports',
            $missing['count'],
            $surrendered['titles'] ? implode('; ', $surrendered['titles']) : 'No items',
            $surrendered['count'],
        ];
    }
}
$analytics_sheet[] = [''];
$analytics_sheet[] = [['Most Items Reported Lost by Year and Department', 2]];
$analytics_sheet[] = ['Rankings include reports that have year and department information; older unclassified reports are excluded.'];
$analytics_sheet[] = [['Category', 1], ['Top value(s)', 1], ['Missing-item Reports', 1]];
$analytics_sheet[] = [
    'Year level',
    $year_leaders['leaders'] ? implode('; ', $year_leaders['leaders']) : 'No year data submitted',
    $year_leaders['count'],
];
$analytics_sheet[] = [
    'Department',
    $department_leaders['leaders'] ? implode('; ', $department_leaders['leaders']) : 'No department data submitted',
    $department_leaders['count'],
];
$summary = array_merge($summary, [['']], $analytics_sheet);

if (($_GET['preview'] ?? '') === '1') {
    function report_preview_table($rows, $header_row = 0) {
        echo '<div class="table-wrap"><table>';
        foreach ($rows as $row_index => $row) {
            if ($row_index === $header_row) {
                echo '<thead><tr>';
            } elseif ($row_index === $header_row + 1) {
                echo '<tbody><tr>';
            } else {
                echo '<tr>';
            }
            foreach ($row as $cell) {
                $value = is_array($cell) ? ($cell[0] ?? '') : $cell;
                $value = htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $tag = $row_index === $header_row ? 'th' : 'td';
                echo '<' . $tag . '>' . nl2br($value, false) . '</' . $tag . '>';
            }
            echo $row_index === $header_row ? '</tr></thead>' : '</tr>';
        }
        if (count($rows) > $header_row + 1) echo '</tbody>';
        echo '</table></div>';
    }

    $preview_summary = [
        ['Category', 'Count'],
        ['Total items', $t['total']],
        ['Missing', $t['missing']],
        ['Found', $t['found']],
        ['Claimed (returned)', $t['claimed']],
        ['Unclaimed', $t['unclaimed']],
        ['Archived', $t['archived']],
    ];
    $analytics_preview = array_slice($analytics_sheet, 3);
    $download_query = http_build_query([
        'status' => $filter,
        'from' => $valid_from,
        'to' => $valid_to,
    ]);

    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Lost &amp; Found Report Preview</title>
        <style>
            :root { color-scheme: light; font-family: Arial, sans-serif; color: #1e293b; background: #f1f5f9; }
            body { margin: 0; padding: 24px; }
            main { max-width: 1200px; margin: 0 auto; }
            header, section { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 18px; }
            header { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
            h1 { margin: 0 0 8px; color: #1b357a; font-size: 24px; }
            h2 { margin: 0 0 14px; color: #1b357a; font-size: 18px; }
            p { margin: 4px 0; color: #64748b; font-size: 14px; }
            .actions { display: flex; gap: 10px; flex-wrap: wrap; }
            .actions a { display: inline-block; padding: 10px 14px; border-radius: 7px; background: #1b357a; color: #fff; text-decoration: none; font-weight: 700; }
            .actions a.secondary { background: #e2e8f0; color: #1e293b; }
            .table-wrap { overflow-x: auto; }
            table { width: 100%; border-collapse: collapse; font-size: 13px; }
            th, td { padding: 9px 10px; border: 1px solid #e2e8f0; text-align: left; vertical-align: top; white-space: pre-wrap; }
            th { background: #1b357a; color: #fff; }
            tbody tr:nth-child(even) { background: #f8fafc; }
            @media (max-width: 600px) { body { padding: 12px; } header, section { padding: 14px; } }
        </style>
    </head>
    <body>
        <main>
            <header>
                <div>
                    <h1>Lost &amp; Found Report Preview</h1>
                    <p>Generated: <?php echo htmlspecialchars(date('M d, Y h:i A'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <p>Filter: <?php echo htmlspecialchars($filter_label, ENT_QUOTES, 'UTF-8'); ?> | Date range: <?php echo htmlspecialchars($range_label, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div class="actions">
                    <a href="export_report.php?<?php echo htmlspecialchars($download_query, ENT_QUOTES, 'UTF-8'); ?>">Download Excel</a>
                    <a class="secondary" href="admin.php">Back to Admin</a>
                </div>
            </header>
            <section>
                <h2>Summary</h2>
                <?php report_preview_table($preview_summary); ?>
            </section>
            <section>
                <h2>Items</h2>
                <?php report_preview_table($items_sheet); ?>
            </section>
            <section>
                <h2>Monthly Trends and Rankings</h2>
                <?php report_preview_table($analytics_preview); ?>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit();
}

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="3">'
    . '<font><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="3">'
    . '<fill><patternFill patternType="none"/></fill>'
    . '<fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF1B357A"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="3">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
    . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '</cellXfs>'
    . '</styleSheet>';

$files = [
    '[Content_Types].xml' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>',
    '_rels/.rels' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>',
    'xl/workbook.xml' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Summary" sheetId="1" r:id="rId1"/><sheet name="Items" sheetId="2" r:id="rId2"/><sheet name="Analytics" sheetId="3" r:id="rId3"/></sheets>'
        . '</workbook>',
    'xl/_rels/workbook.xml.rels' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
        . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>',
    'xl/styles.xml' => $styles,
    'xl/worksheets/sheet1.xml' => sheet_xml($summary, [26, 38, 20, 38, 20]),
    'xl/worksheets/sheet2.xml' => sheet_xml($items_sheet, [16, 28, 50, 26, 12, 14, 22]),
    'xl/worksheets/sheet3.xml' => sheet_xml($analytics_sheet, [16, 38, 20, 38, 20]),
];

$xlsx = build_zip($files);

$filename = 'LostFound_Report_' . date('Y-m-d_His') . '.xlsx';
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($xlsx));
header('Cache-Control: max-age=0');
echo $xlsx;
exit();