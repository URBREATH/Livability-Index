<?php
/**
 * scores-embed.php — Embeddable Livability Scores Widget
 *
 * Renders ONLY the radar chart + group score bars.
 * No navbar, no map, no survey detail — designed to live inside an <iframe>.
 *
 * Usage on the embedding site:
 *   <iframe src="https://yoursite.com/scores-embed.php"
 *           width="100%" height="540" frameborder="0"
 *           style="border:none;overflow:hidden"></iframe>
 *
 * If your web server adds "X-Frame-Options: SAMEORIGIN" globally you will
 * also need to override it there (e.g. in .htaccess or nginx config).
 */

// Allow embedding from any origin (modern browsers honour CSP frame-ancestors
// over the legacy X-Frame-Options header).
header_remove('X-Frame-Options');
header('Content-Security-Policy: frame-ancestors *');

require_once __DIR__ . '/db_config.php';

// ── Constants ────────────────────────────────────────────────────────────────

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

// ── Data ─────────────────────────────────────────────────────────────────────

$group_summaries = [];
$survey_count    = 0;
$radar_data      = [];
$db_error        = null;

try {
    $db           = get_db();
    $survey_count = (int)$db->querySingle('SELECT COUNT(*) FROM surveys');

    $gres = $db->query('SELECT * FROM group_score_summaries ORDER BY group_name');
    while ($row = $gres->fetchArray(SQLITE3_ASSOC)) {
        $group_summaries[$row['group_name']] = $row;
    }
    $db->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

// Build GROUP_ORDER: preferred names first (canonical order), then any new/unknown groups alphabetically.
$_db_gn = array_keys($group_summaries);
$_known = array_values(array_filter($PREFERRED_GROUP_ORDER,
    function ($n) use ($_db_gn) { return in_array($n, $_db_gn); }));
$_extra = array_values(array_diff($_db_gn, $PREFERRED_GROUP_ORDER));
sort($_extra);
$GROUP_ORDER = (!empty($_known) || !empty($_extra))
    ? array_merge($_known, $_extra)
    : $PREFERRED_GROUP_ORDER;

$radar_labels = $GROUP_ORDER;
foreach ($GROUP_ORDER as $gn) {
    $radar_data[] = isset($group_summaries[$gn])
        ? round((float)$group_summaries[$gn]['aggregate_score'], 3)
        : null;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Urbreath — Livability Scores</title>

  <!-- Bootstrap 5 (local) -->
  <link rel="stylesheet" href="lib/bootstrap.min.css">

  <!-- Chart.js (local) -->
  <script src="lib/chart.umd.min.js"></script>

  <style>
    /* Transparent background so the host page colour shows through */
    html, body { margin: 0; padding: 0; background: transparent; font-family: inherit; }
    .scores-card { border-left: 4px solid #6f42c1; }
    .score-bar-wrap {
      position: relative; height: 20px; background: #e9ecef;
      border-radius: .25rem; overflow: hidden;
    }
    .score-bar-fill {
      position: absolute; top: 0; height: 100%;
      border-radius: .25rem; transition: width .4s;
    }
    .centre-tick {
      position: absolute; top: 0; left: 50%;
      width: 2px; height: 100%; background: rgba(0,0,0,.15);
    }
    .score-label-row { font-size: .8rem; }
  </style>
</head>
<body>

<div class="p-3">

<?php if ($db_error): ?>
  <div class="alert alert-danger">
    <strong>Database error:</strong> <?= h($db_error) ?>
  </div>

<?php elseif (empty($group_summaries)): ?>
  <div class="alert alert-warning text-center">
    <p class="mb-1 fw-semibold">No livability scores available yet.</p>
    <p class="mb-0 small text-muted">Run the harvester to generate scores.</p>
  </div>

<?php else:
  // Compute overall mean (ignore nulls)
  $non_null = array_filter($radar_data, function($v) { return $v !== null; });
  $overall  = $non_null ? array_sum($non_null) / count($non_null) : null;
?>

  <div class="card shadow-sm scores-card">
    <div class="card-header">
      <h5 class="mb-0">🏆 Livability Scores</h5>
      <p class="text-muted small mb-0">
        Weighted mean per thematic group (scale &minus;2 to +2).
        Aggregated across <?= $survey_count ?> survey(s).
        <?php if ($overall !== null): ?>
          &nbsp;|&nbsp; <strong>Overall: <?= number_format($overall, 2) ?></strong>
        <?php endif; ?>
      </p>
    </div>
    <div class="card-body">
      <div class="row align-items-center">

        <!-- Radar chart -->
        <div class="col-lg-5 mb-4 mb-lg-0 d-flex justify-content-center">
          <canvas id="radarChart" style="max-width:320px;max-height:320px"></canvas>
        </div>

        <!-- Score bars -->
        <div class="col-lg-7">
          <?php foreach ($GROUP_ORDER as $gn):
            if (!isset($group_summaries[$gn])) continue;
            $sc   = (float)$group_summaries[$gn]['aggregate_score'];
            $pct  = round(($sc + 2) / 4 * 100);
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
              <div class="centre-tick"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

      </div>
    </div>
    <div class="card-footer text-muted" style="font-size:.7rem">
      🌿 <a href="index.php" target="_blank" rel="noopener"
            style="color:inherit;text-decoration:none">Urbreath Livability Survey Viewer</a>
    </div>
  </div>

<?php endif; ?>

</div><!-- /.p-3 -->

<script>
(function () {
  'use strict';
  const labels = <?= json_encode($radar_labels, JSON_UNESCAPED_UNICODE) ?>;
  const data   = <?= json_encode($radar_data) ?>;

  const ctx = document.getElementById('radarChart');
  if (!ctx || !data.some(v => v !== null)) return;

  new Chart(ctx, {
    type: 'radar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Livability Score',
        data: data,
        backgroundColor: 'rgba(111,66,193,0.15)',
        borderColor: '#6f42c1',
        pointBackgroundColor: '#6f42c1',
        pointRadius: 5,
        pointHoverRadius: 7,
      }]
    },
    options: {
      scales: {
        r: {
          min: -2, max: 2,
          ticks: {
            stepSize: 1,
            callback: function (v) { return v > 0 ? '+' + v : String(v); },
            font: { size: 10 },
          },
          pointLabels: { font: { size: 11 } },
          grid:       { color: 'rgba(0,0,0,0.1)' },
          angleLines: { color: 'rgba(0,0,0,0.1)' },
        }
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function (ctx) {
              const v = ctx.raw;
              return v !== null ? (v > 0 ? '+' : '') + v.toFixed(2) : 'n/a';
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

</body>
</html>
