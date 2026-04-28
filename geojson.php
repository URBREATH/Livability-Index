<?php
/**
 * geojson.php — REST endpoint: GET /geojson.php
 *
 * Returns a GeoJSON FeatureCollection.
 * Each Feature represents one survey location; properties contain the
 * per-group scores from the group_scores table plus a computed overallScore.
 *
 * Optional query params:
 *   ?survey_id=42   — return only that survey's feature
 *   ?round=1        — round scores to nearest integer (default: 2 dp float)
 */

declare(strict_types=1);

header('Content-Type: application/geo+json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

require_once __DIR__ . '/db_config.php';

// ── Group name → camelCase property key ──────────────────────────────────────
const GROUP_KEYS = [
    'Attractiveness & Well-being'  => 'attractiveness',
    'Green & Nature Quality'       => 'greenness',
    'Urban Design & Heritage'      => 'urbanDesign',
    'Functionality & Inclusion'    => 'functionality',
    'Social Life & Participation'  => 'socialLife',
    'Mobility & Transport'         => 'mobility',
    'Citizens Engagement'          => 'civicEngagement',
];

// ── Input validation ─────────────────────────────────────────────────────────
$filter_id  = null;
$round_ints = false;

if (isset($_GET['survey_id'])) {
    $raw = $_GET['survey_id'];
    if (!ctype_digit((string)$raw)) {
        http_response_code(400);
        echo json_encode(['error' => 'survey_id must be a positive integer']);
        exit;
    }
    $filter_id = (int)$raw;
}

if (isset($_GET['round']) && $_GET['round'] === '1') {
    $round_ints = true;
}

// ── Query ────────────────────────────────────────────────────────────────────
try {
    $db = get_db();

    // All surveys + coordinates
    $sql = 'SELECT s.id, s.dataset_title, l.latitude, l.longitude
            FROM surveys s
            JOIN locations l ON l.id = s.location_id';
    if ($filter_id !== null) {
        $sql .= ' WHERE s.id = ' . $filter_id;
    }
    $sql .= ' ORDER BY s.release_date DESC, s.ingested_at DESC';

    $survey_rows = $db->query($sql);

    // Pre-load all group scores indexed by survey_id
    $gs_raw       = $db->query('SELECT survey_id, group_name, group_score FROM group_scores');
    $group_scores = [];
    while ($r = $gs_raw->fetchArray(SQLITE3_ASSOC)) {
        $group_scores[(int)$r['survey_id']][$r['group_name']] = (float)$r['group_score'];
    }

    // Pre-load open-text answers grouped by survey_id → db row id (q.id)
    // Using the DB primary key for grouping ensures questions are always
    // separated, even when question_id is 0 on migrated legacy data.
    // Only includes questions explicitly typed as open_text.
    $ot_raw = $db->query(
        "SELECT q.id AS db_id, q.survey_id, q.question_id, q.question_text, ota.answer_text
         FROM open_text_answers ota
         JOIN questions q ON q.id = ota.question_id
         WHERE q.question_type = 'open_text'
         ORDER BY q.survey_id, q.order_index, ota.order_index"
    );
    $open_texts = [];   // [survey_id][db_id] = ['questionId' => ..., 'questionText' => ..., 'responses' => [...]]
    while ($r = $ot_raw->fetchArray(SQLITE3_ASSOC)) {
        $sid   = (int)$r['survey_id'];
        $db_id = (int)$r['db_id'];
        if (!isset($open_texts[$sid][$db_id])) {
            $open_texts[$sid][$db_id] = [
                'questionId'   => (int)$r['question_id'],
                'questionText' => $r['question_text'],
                'responses'    => [],
            ];
        }
        $open_texts[$sid][$db_id]['responses'][] = $r['answer_text'];
    }

    // ── Build GeoJSON features ────────────────────────────────────────────────
    $features = [];

    while ($s = $survey_rows->fetchArray(SQLITE3_ASSOC)) {
        $sid    = (int)$s['id'];
        $scores = $group_scores[$sid] ?? [];

        // Map group names → camelCase keys, collect values for overallScore
        $group_props = [];
        $total       = 0.0;
        $count       = 0;

        foreach (GROUP_KEYS as $db_name => $js_key) {
            if (array_key_exists($db_name, $scores)) {
                $v                = $round_ints
                    ? (int)round($scores[$db_name])
                    : round($scores[$db_name], 2);
                $group_props[$js_key] = $v;
                $total               += $scores[$db_name];
                $count++;
            } else {
                $group_props[$js_key] = null;
            }
        }

        $overall = $count > 0
            ? ($round_ints ? (int)round($total / $count) : round($total / $count, 2))
            : null;

        $properties = array_merge(
            [
                'title'              => $s['dataset_title'],
                'overallScore'       => $overall,
                'openTextResponses'  => array_values($open_texts[$sid] ?? []),
            ],
            $group_props
        );

        $features[] = [
            'type'       => 'Feature',
            'properties' => $properties,
            'geometry'   => [
                'type'        => 'Point',
                'coordinates' => [(float)$s['longitude'], (float)$s['latitude']],
            ],
        ];
    }

    // ── Output ────────────────────────────────────────────────────────────────
    echo json_encode(
        ['type' => 'FeatureCollection', 'features' => $features],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
