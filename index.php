<?php
/**
 * index.php — Urbreath Livability Survey Viewer
 * Bootstrap 5 + Leaflet.js
 * Queries survey.db via PHP SQLite3
 */

require_once __DIR__ . '/db_config.php';

// ── Data loading ────────────────────────────────────────────────────────────

try {
    $db = get_db();
} catch (RuntimeException $e) {
    die('<div style="font-family:sans-serif;padding:2rem;color:#c00">'
        . htmlspecialchars($e->getMessage()) . '</div>');
}

// Load all surveys with their location
$surveys = [];
$res = $db->query(
    'SELECT s.*, l.latitude, l.longitude, l.name AS location_name
     FROM surveys s
     JOIN locations l ON l.id = s.location_id
     ORDER BY s.release_date DESC, s.ingested_at DESC'
);
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $surveys[] = $row;
}

// Load all questions + answers keyed by survey_id
$survey_questions = [];   // [survey_id => [question, ...]]
foreach ($surveys as $s) {
    $sid = (int)$s['id'];
    $survey_questions[$sid] = [];

    $qres = $db->query(
        "SELECT * FROM questions WHERE survey_id = $sid ORDER BY section, order_index"
    );
    while ($q = $qres->fetchArray(SQLITE3_ASSOC)) {
        $qid = (int)$q['id'];
        $q['choice_answers']   = [];
        $q['open_text_answers'] = [];

        if ($q['question_type'] === 'choice') {
            $ares = $db->query(
                "SELECT * FROM choice_answers
                 WHERE question_id = $qid ORDER BY order_index"
            );
            while ($a = $ares->fetchArray(SQLITE3_ASSOC)) {
                $q['choice_answers'][] = $a;
            }
        }

        // Open-text answers always attached (choice questions can also have them)
        $ores = $db->query(
            "SELECT * FROM open_text_answers
             WHERE question_id = $qid ORDER BY order_index"
        );
        while ($o = $ores->fetchArray(SQLITE3_ASSOC)) {
            $q['open_text_answers'][] = $o;
        }

        $survey_questions[$sid][] = $q;
    }
}

// Load aggregated summaries
$summaries = [];   // [question_text => [answer_text => row, ...]]
$sres = $db->query(
    'SELECT * FROM question_summaries ORDER BY question_text, total_respondents DESC'
);
while ($row = $sres->fetchArray(SQLITE3_ASSOC)) {
    $summaries[$row['question_text']][] = $row;
}

// ── Scoring data (tables may not exist on first run before Python) ─────────

// Preferred canonical group order — used as sort key when building GROUP_ORDER from the DB.
$PREFERRED_GROUP_ORDER = [
    'Attractiveness & Well-being',
    'Green & Nature Quality',
    'Urban Design & Heritage',
    'Functionality & Inclusion',
    'Social Life & Participation',
    'Mobility & Transport',
    'Citizens Engagement',
];

$group_summaries       = [];   // [group_name => row]
$survey_group_scores   = [];   // [survey_id  => [group_name => float]]
$survey_indicators     = [];   // [survey_id  => [row, ...]]
$db_group_names        = [];   // distinct group names present in DB

try {
    $gres = $db->query('SELECT * FROM group_score_summaries ORDER BY group_name');
    while ($row = $gres->fetchArray(SQLITE3_ASSOC)) {
        $group_summaries[$row['group_name']] = $row;
    }

    // Collect distinct group names (summaries first; fall back to group_scores rows)
    $db_group_names = array_keys($group_summaries);
    if (empty($db_group_names)) {
        $gnres = $db->query('SELECT DISTINCT group_name FROM group_scores');
        while ($gnrow = $gnres->fetchArray(SQLITE3_NUM)) {
            $db_group_names[] = $gnrow[0];
        }
    }

    $sgres = $db->query('SELECT * FROM group_scores ORDER BY survey_id, group_name');
    while ($row = $sgres->fetchArray(SQLITE3_ASSOC)) {
        $survey_group_scores[(int)$row['survey_id']][$row['group_name']] = (float)$row['group_score'];
    }

    $ires = $db->query(
        'SELECT survey_id, question_text, group_name, weighted_score
         FROM question_scores
         WHERE is_indicator = 1
         ORDER BY survey_id, group_name, question_text'
    );
    while ($row = $ires->fetchArray(SQLITE3_ASSOC)) {
        $survey_indicators[(int)$row['survey_id']][] = $row;
    }
} catch (Exception $e) {
    // Scoring tables not yet created — harvester hasn't been run with the new version.
}

// Build GROUP_ORDER: preferred names first (canonical order), then any new/unknown groups alphabetically.
$_known = array_values(array_filter($PREFERRED_GROUP_ORDER,
    function ($n) use ($db_group_names) { return in_array($n, $db_group_names); }));
$_extra = array_values(array_diff($db_group_names, $PREFERRED_GROUP_ORDER));
sort($_extra);
$GROUP_ORDER = (!empty($_known) || !empty($_extra))
    ? array_merge($_known, $_extra)
    : $PREFERRED_GROUP_ORDER;

$db->close();

// ── Helpers ─────────────────────────────────────────────────────────────────

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_date(?string $iso): string {
    if (!$iso) return '—';
    // Parse ISO 8601 and format as "24 Mar 2026 05:29 UTC"
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->setTimezone(new DateTimeZone('UTC'))
                  ->format('d M Y H:i') . ' UTC';
    } catch (Exception $e) {
        return h($iso);
    }
}

function pct_bar(float $pct, int $count, string $label): string {
    $pct_display = number_format($pct * 100, 1);
    $width = min(100, max(0, round($pct * 100)));
    $bar_class = $width > 60 ? 'bg-success' : ($width > 30 ? 'bg-info' : 'bg-secondary');
    return '
        <div class="mb-2">
          <div class="d-flex justify-content-between small mb-1">
            <span>' . h($label) . '</span>
            <span class="text-muted">' . $pct_display . '% &nbsp;|&nbsp; ' . $count . ' respondent' . ($count !== 1 ? 's' : '') . '</span>
          </div>
          <div class="progress" style="height:18px" role="progressbar"
               aria-valuenow="' . $width . '" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar ' . $bar_class . '" style="width:' . $width . '%"></div>
          </div>
        </div>';
}

// Build GeoJSON for map markers
$geo_features = [];
foreach ($surveys as $s) {
    $geo_features[] = [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [(float)$s['longitude'], (float)$s['latitude']],
        ],
        'properties' => [
            'survey_id'    => (int)$s['id'],
            'title'        => $s['dataset_title'],
            'release_date' => fmt_date($s['release_date']),
            'ingested_at'  => fmt_date($s['ingested_at']),
            'anchor'       => 'survey-' . $s['id'],
        ],
    ];
}
$geojson = json_encode(['type' => 'FeatureCollection', 'features' => $geo_features],
                       JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Urbreath — Livability Survey Viewer</title>

  <!-- Bootstrap 5 (local) -->
  <link rel="stylesheet" href="lib/bootstrap.min.css">

  <!-- Leaflet.js (local) -->
  <link rel="stylesheet" href="lib/leaflet.css">

  <!-- Chart.js (local) -->
  <script src="lib/chart.umd.min.js"></script>

  <style>
    body { background: #f8f9fa; }
    #survey-map { height: 400px; border-radius: .5rem; }
    .survey-card  { border-left: 4px solid #0d6efd; }
    .summary-card { border-left: 4px solid #198754; }
    .scores-card  { border-left: 4px solid #6f42c1; }
    .section-badge-summary { background: #198754; }
    .open-answer-card { background: #fff; border: 1px solid #dee2e6; border-radius: .375rem; padding: .5rem .75rem; margin-bottom: .5rem; font-size:.9rem; }
    .timestamp-row span { margin-right: 1.5rem; }
    .survey-count-badge { font-size:.75rem; }
    .sticky-top-nav { top: 0; z-index: 1020; }
    .score-bar-wrap { position: relative; height: 20px; background: #e9ecef; border-radius: .25rem; overflow: hidden; }
    .score-bar-fill { position: absolute; top: 0; height: 100%; border-radius: .25rem; transition: width .4s; }
    .score-label-row { font-size: .8rem; }
    .indicator-badge { font-size: .75rem; padding: .2em .55em; }
    /* Tab panels */
    .view-panel { display: none; }
    .view-panel.active { display: block; }
    #tab-overview.active-tab, #tab-detail.active-tab {
      border-bottom: 3px solid #0d6efd; color: #0d6efd; font-weight: 600;
    }
    .back-btn { cursor: pointer; }
  </style>
</head>
<body>

<!-- ── Navbar ─────────────────────────────────────────────────────────────── -->
<nav class="navbar navbar-dark bg-dark sticky-top-nav">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">🌿 Urbreath Livability Surveys</a>
    <span class="text-white-50 small"><?= count($surveys) ?> survey(s) in database</span>
  </div>
</nav>

<div class="container-xl py-4">

<?php if (empty($surveys)): ?>
  <div class="alert alert-warning text-center mt-5">
    <h4>No surveys in the database yet.</h4>
    <p class="mb-1">Run the harvester to fetch and import the first survey:</p>
    <code>python harvester.py --once</code>
  </div>

<?php else: ?>

  <?php
    $radar_labels = $GROUP_ORDER;
    $radar_data   = [];
    foreach ($GROUP_ORDER as $gn) {
        $radar_data[] = isset($group_summaries[$gn])
            ? round((float)$group_summaries[$gn]['aggregate_score'], 3)
            : null;
    }
    $overall_vals = array_filter($radar_data, function($v) { return $v !== null; });
    $mean_overall = $overall_vals ? array_sum($overall_vals) / count($overall_vals) : null;
  ?>

  <!-- ── Tab bar ────────────────────────────────────────────────────────── -->
  <ul class="nav nav-tabs mb-4" id="mainTabs">
    <li class="nav-item">
      <button class="nav-link active" id="tab-overview" onclick="showPanel('overview')">
        🗺 Overview
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link" id="tab-detail" onclick="showPanel('detail')" style="display:none">
        📋 Survey Detail
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link" id="tab-opentext" onclick="showPanel('opentext')" style="display:none">
        💬 Open Responses
      </button>
    </li>
  </ul>

  <!-- ══════════════════════════════════════════════════════════════════════ -->
  <!-- OVERVIEW PANEL                                                         -->
  <!-- ══════════════════════════════════════════════════════════════════════ -->
  <div id="panel-overview" class="view-panel active">

    <!-- Map -->
    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold">📍 Survey Locations</div>
      <div class="card-body p-2"><div id="survey-map"></div></div>
      <div class="card-footer text-muted small">Click a marker to view that survey's full results.</div>
    </div>

    <!-- Aggregated scores -->
    <?php if (!empty($group_summaries)): ?>
    <div class="card shadow-sm scores-card mb-4">
      <div class="card-header">
        <h5 class="mb-0">🏆 Livability Scores</h5>
        <p class="text-muted small mb-0">
          Weighted mean per thematic group (scale &minus;2 to +2).
          Aggregated across <?= count($surveys) ?> survey(s).
          <?php if ($mean_overall !== null): ?>
            &nbsp;|&nbsp; <strong>Overall: <?= number_format($mean_overall, 2) ?></strong>
          <?php endif; ?>
        </p>
      </div>
      <div class="card-body">
        <div class="row align-items-center">
          <div class="col-lg-5 mb-4 mb-lg-0 d-flex justify-content-center">
            <canvas id="radarChart" style="max-width:380px;max-height:380px"></canvas>
          </div>
          <div class="col-lg-7">
            <?php foreach ($GROUP_ORDER as $gn):
              if (!isset($group_summaries[$gn])) continue;
              $sc  = (float)$group_summaries[$gn]['aggregate_score'];
              $pct = round(($sc + 2) / 4 * 100);
              if      ($sc >= 1.0)  $fill = '#198754';
              elseif  ($sc >= 0.0)  $fill = '#0dcaf0';
              elseif  ($sc >= -1.0) $fill = '#ffc107';
              else                  $fill = '#dc3545';
            ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between score-label-row mb-1">
                <span class="fw-semibold"><?= h($gn) ?></span>
                <span class="text-muted"><?= number_format($sc, 2) ?> / 2.00</span>
              </div>
              <div class="score-bar-wrap">
                <div class="score-bar-fill" style="width:<?= $pct ?>%;background:<?= $fill ?>"></div>
                <div style="position:absolute;top:0;left:50%;width:2px;height:100%;background:rgba(0,0,0,.15)"></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Aggregated question summary -->
    <?php if (!empty($summaries)): ?>
    <div class="card shadow-sm summary-card mb-4">
      <div class="card-header">
        <h5 class="mb-0">📊 Aggregated Question Summary</h5>
        <p class="text-muted small mb-0">Respondent counts combined across all ingested surveys.</p>
      </div>
      <div class="card-body">
        <?php foreach ($summaries as $question_text => $ans_rows): ?>
          <div class="mb-4">
            <p class="fw-semibold mb-2"><?= h($question_text) ?>
              <?php
                $max_sc = max(array_column($ans_rows, 'survey_count'));
                echo '<span class="badge bg-secondary survey-count-badge ms-2">'
                   . $max_sc . ' survey' . ($max_sc !== 1 ? 's' : '') . '</span>';
              ?>
            </p>
            <?php foreach ($ans_rows as $a): ?>
              <?= pct_bar((float)$a['total_percentage'], (int)$a['total_respondents'], $a['answer_text']) ?>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /#panel-overview -->

  <!-- ══════════════════════════════════════════════════════════════════════ -->
  <!-- DETAIL PANEL (hidden until a survey is selected)                       -->
  <!-- ══════════════════════════════════════════════════════════════════════ -->
  <div id="panel-detail" class="view-panel">

    <button class="btn btn-sm btn-outline-secondary mb-3 back-btn" onclick="showPanel('overview')">
      ← Back to Overview
    </button>

    <?php foreach ($surveys as $s):
      $sid        = (int)$s['id'];
      $qs         = $survey_questions[$sid] ?? [];
      $survey_qs  = array_filter($qs, function($q) { return $q['section'] === 'survey'; });
      $summary_qs = array_filter($qs, function($q) { return $q['section'] === 'summary'; });
      $gs         = $survey_group_scores[$sid] ?? [];
    ?>
    <div id="survey-<?= $sid ?>" class="survey-detail" style="display:none">
      <div class="card shadow-sm survey-card mb-4">
        <div class="card-header">
          <h5 class="mb-0"><?= h($s['dataset_title']) ?></h5>
          <div class="timestamp-row mt-1 text-muted small">
            <span>📅 Published: <strong><?= fmt_date($s['release_date']) ?></strong></span>
            <span>🔄 Updated: <strong><?= fmt_date($s['distribution_modified_date']) ?></strong></span>
            <span>💾 Imported: <strong><?= fmt_date($s['ingested_at']) ?></strong></span>
          </div>
          <div class="mt-1 text-muted small">
            📌 <?= $s['location_name'] ? h($s['location_name']) . ' — ' : '' ?>
            <?= number_format((float)$s['latitude'], 6) ?>°N,
            <?= number_format((float)$s['longitude'], 6) ?>°E
          </div>
        </div>
        <div class="card-body">

          <!-- Per-survey group scores -->
          <?php if (!empty($gs) || !empty($survey_indicators[$sid])): ?>
          <div class="mb-4">
            <h6 class="text-uppercase fw-semibold mb-2 border-bottom pb-1" style="color:#6f42c1">🏆 Group Scores</h6>
            <div class="row">
              <div class="col-md-8">
                <?php foreach ($GROUP_ORDER as $gn):
                  if (!isset($gs[$gn])) continue;
                  $sc  = (float)$gs[$gn];
                  $pct = round(($sc + 2) / 4 * 100);
                  if      ($sc >= 1.0)  $fill = '#198754';
                  elseif  ($sc >= 0.0)  $fill = '#0dcaf0';
                  elseif  ($sc >= -1.0) $fill = '#ffc107';
                  else                  $fill = '#dc3545';
                ?>
                <div class="mb-2">
                  <div class="d-flex justify-content-between score-label-row mb-1">
                    <span><?= h($gn) ?></span>
                    <span class="text-muted"><?= number_format($sc, 2) ?></span>
                  </div>
                  <div class="score-bar-wrap">
                    <div class="score-bar-fill" style="width:<?= $pct ?>%;background:<?= $fill ?>"></div>
                    <div style="position:absolute;top:0;left:50%;width:2px;height:100%;background:rgba(0,0,0,.15)"></div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <?php if (!empty($survey_indicators[$sid])): ?>
              <div class="col-md-4">
                <p class="score-label-row fw-semibold mb-1">Binary indicators</p>
                <?php foreach ($survey_indicators[$sid] as $ind):
                  $iv  = (float)$ind['weighted_score'];
                  $short = mb_strimwidth($ind['question_text'], 0, 55, '…');
                  $badge_class = $iv > 0 ? 'bg-success' : ($iv < 0 ? 'bg-danger' : 'bg-secondary');
                  $sign = $iv > 0 ? '+' : '';
                ?>
                <div class="mb-2">
                  <div class="score-label-row text-muted mb-1" title="<?= h($ind['question_text']) ?>"><?= h($short) ?></div>
                  <span class="badge indicator-badge <?= $badge_class ?>"><?= $sign . number_format($iv, 2) ?> / ±1</span>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Survey questions -->
          <?php if (!empty($survey_qs)): ?>
            <h6 class="text-uppercase text-muted fw-semibold mb-3 border-bottom pb-1">Survey Questions</h6>
            <?php foreach ($survey_qs as $q): ?>
              <?php _render_question($q); ?>
            <?php endforeach; ?>
          <?php endif; ?>

          <!-- Samlet status -->
          <?php if (!empty($summary_qs)): ?>
            <h6 class="text-uppercase text-muted fw-semibold mt-4 mb-3 border-bottom pb-1">
              <span class="badge section-badge-summary me-1">Summary</span> Samlet Status
            </h6>
            <?php foreach ($summary_qs as $q): ?>
              <?php _render_question($q); ?>
            <?php endforeach; ?>
          <?php endif; ?>

          <?php if (empty($qs)): ?>
            <p class="text-muted">No questions found for this survey.</p>
          <?php endif; ?>

        </div>
      </div>
    </div>
    <?php endforeach; ?>

  </div><!-- /#panel-detail -->

  <!-- ══════════════════════════════════════════════════════════════════════ -->
  <!-- OPEN TEXT PANEL (hidden until a survey is selected)                    -->
  <!-- ══════════════════════════════════════════════════════════════════════ -->
  <div id="panel-opentext" class="view-panel">

    <button class="btn btn-sm btn-outline-secondary mb-3 back-btn" onclick="showPanel('overview')">
      &larr; Back to Overview
    </button>

    <?php foreach ($surveys as $s):
      $sid   = (int)$s['id'];
      $qs    = $survey_questions[$sid] ?? [];
      $ot_qs = array_values(array_filter($qs, function($q) { return !empty($q['open_text_answers']); }));
    ?>
    <div id="opentext-<?= $sid ?>" class="opentext-detail" style="display:none">
      <div class="card shadow-sm mb-4" style="border-left:4px solid #0dcaf0">
        <div class="card-header">
          <h5 class="mb-0">💬 Open Responses &mdash; <?= h($s['dataset_title']) ?></h5>
          <p class="text-muted small mb-0"><?= count($ot_qs) ?> open-text question(s)</p>
        </div>
        <div class="card-body">
          <?php if (empty($ot_qs)): ?>
            <p class="text-muted">No open-text responses for this survey.</p>
          <?php else: ?>
            <div class="accordion" id="ot-acc-<?= $sid ?>">
              <?php foreach ($ot_qs as $qi => $q):
                $cnt    = count($q['open_text_answers']);
                $acc_id = "ot-{$sid}-{$qi}";
              ?>
              <div class="accordion-item">
                <h2 class="accordion-header">
                  <button class="accordion-button <?= $qi > 0 ? 'collapsed' : '' ?>" type="button"
                          data-bs-toggle="collapse" data-bs-target="#<?= $acc_id ?>"
                          aria-expanded="<?= $qi === 0 ? 'true' : 'false' ?>">
                    <?= h($q['question_text']) ?>
                    <span class="badge bg-secondary ms-2 flex-shrink-0"><?= $cnt ?> response<?= $cnt !== 1 ? 's' : '' ?></span>
                  </button>
                </h2>
                <div id="<?= $acc_id ?>" class="accordion-collapse collapse <?= $qi === 0 ? 'show' : '' ?>"
                     data-bs-parent="#ot-acc-<?= $sid ?>">
                  <div class="accordion-body">
                    <ol class="mb-0">
                      <?php foreach ($q['open_text_answers'] as $ot): ?>
                        <li class="mb-1"><?= h($ot['answer_text']) ?></li>
                      <?php endforeach; ?>
                    </ol>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

  </div><!-- /#panel-opentext -->

<?php endif; // surveys not empty ?>

</div><!-- /.container-xl -->

<!-- ── Scripts ───────────────────────────────────────────────────────────── -->
<script src="lib/bootstrap.bundle.min.js"></script>
<script src="lib/leaflet.js"></script>

<script>
(function () {
  'use strict';

  // ── Tab switching ────────────────────────────────────────────────────────
  window.showPanel = function (name) {
    document.querySelectorAll('.view-panel').forEach(function(p) { p.classList.remove('active'); });
    document.getElementById('panel-' + name).classList.add('active');
    document.querySelectorAll('#mainTabs .nav-link').forEach(function(t) { t.classList.remove('active'); });
    document.getElementById('tab-' + name).classList.add('active');
  };

  window.showSurvey = function (sid) {
    // Hide all per-survey blocks, show the requested one
    document.querySelectorAll('.survey-detail').forEach(function(d) { d.style.display = 'none'; });
    document.querySelectorAll('.opentext-detail').forEach(function(d) { d.style.display = 'none'; });
    var el = document.getElementById('survey-' + sid);
    if (el) { el.style.display = 'block'; }
    var ot = document.getElementById('opentext-' + sid);
    if (ot) { ot.style.display = 'block'; }
    // Reveal both context-sensitive tabs and switch to Survey Detail
    document.getElementById('tab-detail').style.display = '';
    document.getElementById('tab-opentext').style.display = '';
    showPanel('detail');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };
})();
</script>

<?php if (!empty($group_summaries)): ?>
<script>
(function () {
  'use strict';
  const labels = <?= json_encode($radar_labels, JSON_UNESCAPED_UNICODE) ?>;
  const data   = <?= json_encode($radar_data) ?>;

  const ctx = document.getElementById('radarChart');
  if (!ctx) return;

  new Chart(ctx, {
    type: 'radar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Livability Score',
        data: data,
        backgroundColor: 'rgba(111, 66, 193, 0.15)',
        borderColor: '#6f42c1',
        pointBackgroundColor: '#6f42c1',
        pointRadius: 5,
        pointHoverRadius: 7,
      }]
    },
    options: {
      scales: {
        r: {
          min: -2,
          max:  2,
          ticks: {
            stepSize: 1,
            callback: function(v) { return v > 0 ? '+' + v : String(v); },
            font: { size: 10 },
          },
          pointLabels: { font: { size: 11 } },
          grid:  { color: 'rgba(0,0,0,0.1)' },
          angleLines: { color: 'rgba(0,0,0,0.1)' },
        }
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              const v = ctx.raw;
              return (v > 0 ? '+' : '') + v.toFixed(2);
            }
          }
        }
      },
      responsive: true,
      maintainAspectRatio: true,
    }
  });
})();
</script>
<?php endif; ?>

<script>
(function () {
  'use strict';

  const geojson = <?= $geojson ?>;

  // ── Map init ───────────────────────────────────────────────────────────
  const map = L.map('survey-map');

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  }).addTo(map);

  // Custom marker icon (blue dot)
  const icon = L.divIcon({
    className: '',
    html: '<div style="width:16px;height:16px;background:#0d6efd;border:2px solid #fff;border-radius:50%;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>',
    iconSize: [16, 16],
    iconAnchor: [8, 8],
    popupAnchor: [0, -10],
  });

  const bounds = [];

  geojson.features.forEach(function (feat) {
    const p   = feat.properties;
    const lng = feat.geometry.coordinates[0];
    const lat = feat.geometry.coordinates[1];
    bounds.push([lat, lng]);

    const marker = L.marker([lat, lng], { icon: icon }).addTo(map);

    marker.bindPopup(
      '<strong>' + escHtml(p.title) + '</strong><br>' +
      '<span style="font-size:.8rem;color:#555">📅 ' + escHtml(p.release_date) + '</span><br>' +
      '<span style="font-size:.8rem;color:#555">💾 Imported: ' + escHtml(p.ingested_at) + '</span><br>' +
      '<a href="#" style="color:#0d6efd" onclick="showSurvey(' + p.survey_id + ');return false;">View results ↓</a>',
      { maxWidth: 280 }
    );
  });

  // Fit map to all markers, or default to Europe if no data
  if (bounds.length > 0) {
    if (bounds.length === 1) {
      map.setView(bounds[0], 13);
    } else {
      map.fitBounds(bounds, { padding: [40, 40] });
    }
  } else {
    map.setView([54, 15], 4);
  }

  // ── Scroll helper ──────────────────────────────────────────────────────
  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
})();
</script>

<?php
// ── PHP helper: render a single question block ───────────────────────────
function _render_question(array $q): void {
    echo '<div class="mb-4">';
    echo '<p class="fw-semibold mb-2">' . h($q['question_text']) . '</p>';

    // Choice answers → progress bars
    if (!empty($q['choice_answers'])) {
        foreach ($q['choice_answers'] as $a) {
            echo pct_bar(
                (float)$a['percentage'],
                (int)$a['respondents_count'],
                $a['answer_text']
            );
        }
    }

    // Open-text answers → cards
    if (!empty($q['open_text_answers'])) {
        foreach ($q['open_text_answers'] as $ot) {
            echo '<div class="open-answer-card">' . h($ot['answer_text']) . '</div>';
        }
    }

    if (empty($q['choice_answers']) && empty($q['open_text_answers'])) {
        echo '<p class="text-muted small fst-italic">No answers recorded for this question.</p>';
    }

    echo '</div>';
}
?>

</body>
</html>
