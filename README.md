# Urbreath Livability Index

A system for collecting, scoring, and visualising citizen survey data about urban public spaces. Survey results are harvested from a remote NGSI-LD open data catalog, scored against a configurable scoring schema, stored in a local SQLite database, and served through a PHP web interface.

---

## Architecture Overview

```
NGSI-LD Catalog (remote API)
        │
        ▼
  harvester.py          ← Python: polls API, downloads CSVs, populates SQLite
        │
        ▼
   survey.db            ← SQLite database (single file)
        │
        ├──► index.php          ← Full survey viewer (map + questions + charts)
        ├──► scores-embed.php   ← Embeddable radar/bar widget (PHP live version)
        ├──► geojson.php        ← REST endpoint returning GeoJSON scores
        │
        └──► export_snapshot.py ← Exports survey.db → snapshot.js (static JSON)
                  │
                  ▼
            snapshot.js
                  │
                  └──► scores-embed.html  ← Embeddable widget (static version, no PHP)
```

---

## Components

### `harvester.py` — Data Pipeline

Polls the NGSI-LD catalog API hourly (or once with `--once`) for datasets tagged with the keywords **livability**, **index**, and **survey**.

For each matching dataset it:

1. Finds and loads **`control.csv`** — the scoring configuration for that location (see below).
2. Iterates over **all distributions** in the dataset, skipping `control.csv` and non-CSV files (e.g. `.xlsx`).
3. Downloads each survey CSV and parses it into questions and answers.
4. Scores every answer and writes everything to `survey.db`.

```bash
python harvester.py          # run continuously, polls every hour
python harvester.py --once   # single pass (useful for testing)
```

---

### `control.csv` — Scoring Configuration

A semicolon-delimited file (no header row) that defines how a survey is scored. Each row has a key in column 1 and a value in column 2.

| Key                            | Value                        | Meaning                                     |
| ------------------------------ | ---------------------------- | ------------------------------------------- |
| `Survey cluster`               | Location name                | Human-readable name for the survey location |
| `Open Text`                    | Comma-separated question IDs | These questions are free-text; not scored   |
| `reverse 5-point Likert scale` | Comma-separated question IDs | Answer positions scored: 2, 1, 0, −1, −2    |
| `binary score`                 | Comma-separated question IDs | Scored: 1 (Ja), −1 (Nej), 0 (third option)  |
| `do not score`                 | Comma-separated question IDs | Excluded from all calculations              |
| `<Name> Group`                 | Comma-separated question IDs | Assigns questions to a named thematic group |

**Example (`control.csv`):**

```
Survey cluster;Vestrbro Torv
Open Text;16
reverse 5-point Likert scale;11
binary score;19
do not score;1,Samlet status
Attractiveness & Well-being Group;2,3,13
Green & Nature Quality  Group;4,5
Urban Design & Heritage Group;6,7
Functionality & Inclusion Group;8,9
Social Life & Participation Group;10,11,12,14,15
Mobility & Transport Group;17,18
Citizens Engagement Group;20,21
```

The file is looked up in this order:

1. In the dataset's `files` array (by filename `control.csv`)
2. Among the dataset's distributions (by title containing `control.csv`)
3. A **local fallback**: `control.csv` sitting next to `harvester.py` (used during development)

---

### Survey CSV Format

4-column, semicolon-delimited. No header row.

```
<question_id>;<question_text>;<percent>;<respondents>
```

- **Question rows**: column 0 is an integer question ID; column 1 is the question text.
- **Answer rows**: column 0 is empty; column 1 is the answer label; columns 2–3 are percentage and respondent count.
- **Open-text rows**: column 0 is empty; columns 2–3 are also empty (just text).

---

### Scoring Rules

Scores are assigned **by answer position** (0-based) within a question:

| Question type           | Position 0 | Position 1 | Position 2 | Position 3 | Position 4 |
| ----------------------- | ---------- | ---------- | ---------- | ---------- | ---------- |
| Standard 5-pt Likert    | −2         | −1         | 0          | +1         | +2         |
| Reversed 5-pt Likert    | +2         | +1         | 0          | −1         | −2         |
| Binary (Ja / Nej / 3rd) | +1         | −1         | 0          | —          | —          |

The **weighted score** for a question is:

$$\text{weighted\_score} = \sum_{i} \text{percentage}_i \times \text{score}_i$$

The **group score** is the mean weighted score of all non-indicator questions within that group for one survey.

The **aggregate group score** (shown in the dashboard) is the mean group score across all surveys.

---

### `survey.db` — SQLite Database

Key tables:

| Table                   | Description                                              |
| ----------------------- | -------------------------------------------------------- |
| `locations`             | One row per survey location (dataset), with coordinates  |
| `surveys`               | One row per ingested distribution (survey CSV)           |
| `questions`             | All questions, linked to a survey                        |
| `choice_answers`        | Answer options with percentage and respondent count      |
| `open_text_answers`     | Free-text responses                                      |
| `question_scores`       | Weighted score per question per survey                   |
| `group_scores`          | Mean score per thematic group per survey                 |
| `group_score_summaries` | Aggregate scores across all surveys (shown in dashboard) |
| `question_summaries`    | Aggregated answer counts/percentages across all surveys  |

To reset and re-populate:

```bash
Remove-Item survey.db   # or: rm survey.db
python harvester.py --once
```

---

### `index.php` — Survey Viewer

Full web viewer. Shows:

- An **interactive Leaflet map** with survey location markers.
- Per-survey **question/answer breakdowns** with bar charts (Chart.js).
- A **group scores panel** (radar chart + horizontal bars) summarising all surveys.

Reads directly from `survey.db` via PHP's `SQLite3` extension.

---

### `geojson.php` — GeoJSON REST Endpoint

Returns a `FeatureCollection` where each feature is a survey location with group scores as properties.

```
GET /geojson.php
GET /geojson.php?survey_id=1
GET /geojson.php?round=1        # round scores to integers
```

Used by external map integrations (e.g. Urbreath platform).

---

### `scores-embed.php` / `scores-embed.html` — Embeddable Widget

A minimal iframe-embeddable widget showing only the radar chart and group score bars.

- **`scores-embed.php`** — Live version, reads from `survey.db` directly.
- **`scores-embed.html`** — Static version, reads from `snapshot.js` (no PHP/SQLite needed).

Embed with:

```html
<iframe
  src="https://yoursite.com/scores-embed.php"
  width="100%"
  height="540"
  frameborder="0"
  style="border:none;overflow:hidden"
></iframe>
```

---

### `export_snapshot.py` — Static Export

Exports the full contents of `survey.db` to `snapshot.js` — a plain JS file assigning all data to `window.SURVEY_DATA`. Enables the static `scores-embed.html` to work without any server-side processing.

```bash
python export_snapshot.py
python export_snapshot.py --db path/to/survey.db --out path/to/snapshot.js
```

---

## Setup

### Requirements

- **Python 3.10+** with `requests` installed (`pip install requests`)
- **PHP 8+** with the `sqlite3` extension enabled
- A web server (Apache/nginx) pointing at the project directory

### First Run

```bash
# 1. Fetch data and populate the database
python harvester.py --once

# 2. (Optional) Export a static snapshot for the HTML widget
python export_snapshot.py

# 3. Open index.php in your browser
```

### Configuration

| File            | Setting                 | Description                                   |
| --------------- | ----------------------- | --------------------------------------------- |
| `harvester.py`  | `DATASET_API`           | NGSI-LD dataset catalog URL                   |
| `harvester.py`  | `DISTRIBUTION_API`      | NGSI-LD distribution catalog URL              |
| `harvester.py`  | `POLL_INTERVAL_SECONDS` | Polling interval (default: 3600s)             |
| `db_config.php` | `DB_PATH`               | Path to `survey.db` (default: same directory) |

---

## Thematic Groups

The seven scoring groups used in the current configuration:

1. **Attractiveness & Well-being**
2. **Green & Nature Quality**
3. **Urban Design & Heritage**
4. **Functionality & Inclusion**
5. **Social Life & Participation**
6. **Mobility & Transport**
7. **Citizens Engagement**

Group membership is defined entirely in `control.csv` and can be changed without touching any code.
