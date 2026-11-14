<?php
session_start();
include 'db.php';
date_default_timezone_set('Asia/Manila');

// Admin only
$role = $_SESSION['role'] ?? $_SESSION['usertype'] ?? '';
if (!isset($_SESSION['user_id']) || $role !== 'admin') {
    http_response_code(403);
    exit('Only the admin can download reports.');
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
$range_label = ($from || $to) ? (($from ?: 'start') . ' to ' . ($to ?: 'today')) : 'All dates';
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
        . '<sheets><sheet name="Summary" sheetId="1" r:id="rId1"/><sheet name="Items" sheetId="2" r:id="rId2"/></sheets>'
        . '</workbook>',
    'xl/_rels/workbook.xml.rels' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>',
    'xl/styles.xml' => $styles,
    'xl/worksheets/sheet1.xml' => sheet_xml($summary, [26, 30]),
    'xl/worksheets/sheet2.xml' => sheet_xml($items_sheet, [16, 28, 50, 26, 12, 14, 22]),
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