<?php
// src/public/ajax/check_conflicts.php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error'=>'Invalid method']); exit;
}

$start = $_POST['start_datetime'] ?? '';
$end = $_POST['end_datetime'] ?? '';
$venue_id = $_POST['venue_id'] ?? null;
$participants = $_POST['participants'] ?? [];

if (!$start || !$end || empty($participants)) {
    echo json_encode(['conflicts'=>[]]); exit;
}

$pdo = getPDO();
$conflicts = [];

$sql = "SELECT m.id,m.title,m.start_datetime,m.end_datetime,m.venue_id, mp.participant_id
FROM meetings m
JOIN meeting_participants mp ON mp.meeting_id = m.id
WHERE (:start < m.end_datetime AND :end > m.start_datetime)";

$params = [':start'=>$start, ':end'=>$end];

if ($venue_id) {
    $sql .= " AND m.venue_id = :venue_id";
    $params[':venue_id'] = $venue_id;
}

// limit to participants list
$in = [];
foreach ($participants as $i => $pid) {
    $in[] = ':p'.$i;
    $params[':p'.$i] = (int)$pid;
}
$sql .= " AND mp.participant_id IN (".implode(',', $in).")";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as $r) {
    $pid = $r['participant_id'];
    $conflicts[$pid][] = [
        'id' => $r['id'],
        'title' => $r['title'],
        'start_datetime' => $r['start_datetime'],
        'end_datetime' => $r['end_datetime'],
        'venue_id' => $r['venue_id'],
    ];
}

echo json_encode(['conflicts'=>$conflicts]);
