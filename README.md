# Urbreath Livability Index

**Provided by:** URBREATH project

## Description

The Urbreath Livability Index collects, scores, and visualizes citizen survey data about urban public spaces. It harvests survey datasets from a remote NGSI-LD open data catalog, applies a configurable scoring schema, stores the results in SQLite, and serves them through a PHP web interface.

## Installation Prerequisites

- Python 3.10 or later, with the `requests` package.
- PHP 8 or later, with the `sqlite3` extension enabled.
- An Apache or nginx web server pointing to the project directory.
- Network access to the configured NGSI-LD catalog APIs.

## Installation Instructions

1. Install the prerequisites listed above.
2. Configure the catalog API URLs and polling interval in `harvester.py`, and the database path in `db_config.php`.
3. Fetch survey data and populate the database by running `python harvester.py --once`.
4. Optionally create a static data snapshot for the HTML widget by running `python export_snapshot.py`.
5. Serve the project directory through Apache or nginx and open `index.php` in a browser.
6. To keep the database updated, run `python harvester.py` to poll the catalog hourly.

## Built Image Registry

Not specified in the provided documentation.

## License

This project is licensed under the MIT License.

Copyright 2026 tadolphi tadolphi@vc.systems

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

## External technical resources

- [Requests documentation](https://requests.readthedocs.io/)
- [PHP SQLite3 documentation](https://www.php.net/manual/en/book.sqlite3.php)
- [Leaflet](https://leafletjs.com/)
- [Chart.js](https://www.chartjs.org/)

The NGSI-LD catalog API URLs are configured in `harvester.py`; specific endpoint URLs were not included in the provided documentation.

## User Guide References

No separate user guide or FAQ links were provided. Setup and usage details are included under [Additional Information](#additional-information).

## Additional Information

### Architecture

```text
NGSI-LD Catalog (remote API)
        |
        v
  harvester.py             Python: polls API, downloads CSVs, populates SQLite
        |
        v
   survey.db               SQLite database
        |
        +--> index.php             Full survey viewer: map, questions, charts
        +--> scores-embed.php      Live embeddable widget
        +--> geojson.php           REST endpoint returning GeoJSON scores
        |
        +--> export_snapshot.py    Exports survey.db to snapshot.js
                    |
                    v
               snapshot.js
                    |
                    +--> scores-embed.html  Static widget; no PHP required
```

### Components

#### `harvester.py` — data pipeline

The harvester polls the NGSI-LD catalog API hourly, or once when run with `--once`, for datasets tagged with the keywords **livability**, **index**, and **survey**.

For each matching dataset, it:

1. Finds and loads `control.csv`, the scoring configuration for the location.
2. Iterates over the dataset’s distributions, skipping `control.csv` and non-CSV files such as `.xlsx` files.
3. Downloads each survey CSV and parses its questions and answers.
4. Scores the answers and writes the results to `survey.db`.

```bash
python harvester.py          # Run continuously; poll hourly
python harvester.py --once   # Run one pass, useful for testing
```

#### `control.csv` — scoring configuration

This semicolon-delimited file has no header row. Each row contains a key in column 1 and a value in column 2.

| Key | Value | Meaning |
| --- | --- | --- |
| `Survey cluster` | Location name | Human-readable name for the survey location. |
| `Open Text` | Comma-separated question IDs | Questions are free-text and are not scored. |
| `reverse 5-point Likert scale` | Comma-separated question IDs | Answer positions are scored `2`, `1`, `0`, `-1`, `-2`. |
| `binary score` | Comma-separated question IDs | Scores `1` (Ja), `-1` (Nej), and `0` (third option). |
| `do not score` | Comma-separated question IDs | Questions are excluded from all calculations. |
| `<Name> Group` | Comma-separated question IDs | Assigns questions to a named thematic group. |

Example:

```text
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

The harvester looks for `control.csv` in this order:

1. In the dataset’s `files` array, by filename.
2. Among the dataset’s distributions, by title containing `control.csv`.
3. As a local fallback file alongside `harvester.py`, primarily for development.

#### Survey CSV format

Survey files are semicolon-delimited, have four columns, and do not have a header row:

```text
<question_id>;<question_text>;<percent>;<respondents>
```

- **Question rows:** column 0 is an integer question ID; column 1 is the question text.
- **Answer rows:** column 0 is empty; column 1 is the answer label; columns 2–3 contain the percentage and respondent count.
- **Open-text rows:** column 0 is empty; columns 2–3 are also empty.

#### Scoring rules

Scores are assigned by answer position, starting at position 0.

| Question type | Position 0 | Position 1 | Position 2 | Position 3 | Position 4 |
| --- | ---: | ---: | ---: | ---: | ---: |
| Standard 5-point Likert | -2 | -1 | 0 | +1 | +2 |
| Reversed 5-point Likert | +2 | +1 | 0 | -1 | -2 |
| Binary (Ja / Nej / third option) | +1 | -1 | 0 | — | — |

The weighted score for a question is:

```math
\text{weighted\_score} = \sum_i \text{percentage}_i \times \text{score}_i
```

A group score is the mean weighted score of its non-indicator questions for one survey. An aggregate group score, as shown in the dashboard, is the mean group score across all surveys.

#### `survey.db` — SQLite database

| Table | Description |
| --- | --- |
| `locations` | One row per survey location (dataset), with coordinates. |
| `surveys` | One row per ingested distribution (survey CSV). |
| `questions` | Questions linked to a survey. |
| `choice_answers` | Answer options with percentage and respondent count. |
| `open_text_answers` | Free-text responses. |
| `question_scores` | Weighted score per question per survey. |
| `group_scores` | Mean score per thematic group per survey. |
| `group_score_summaries` | Aggregate scores across all surveys, shown in the dashboard. |
| `question_summaries` | Aggregated answer counts and percentages across all surveys. |

To reset and repopulate the database, delete `survey.db` and run the harvester again. **Deleting this file removes the current database contents.**

```powershell
Remove-Item survey.db
python harvester.py --once
```

#### `index.php` — survey viewer

The full web viewer includes:

- An interactive Leaflet map with survey location markers.
- Per-survey question and answer breakdowns with bar charts using Chart.js.
- A group scores panel with a radar chart and horizontal bars summarizing all surveys.

The viewer reads directly from `survey.db` using PHP’s `SQLite3` extension.

#### `geojson.php` — GeoJSON REST endpoint

Returns a `FeatureCollection` where each feature is a survey location with group scores as properties.

```text
GET /geojson.php
GET /geojson.php?survey_id=1
GET /geojson.php?round=1
```

The `round=1` parameter rounds scores to integers. The endpoint can be used by external map integrations, such as the Urbreath platform.

#### `scores-embed.php` and `scores-embed.html` — embeddable widgets

These minimal widgets show the radar chart and group score bars:

- `scores-embed.php` is the live version and reads directly from `survey.db`.
- `scores-embed.html` is the static version and reads from `snapshot.js`; it does not require PHP or SQLite.

Example embed:

```html
<iframe
  src="https://yoursite.com/scores-embed.php"
  width="100%"
  height="540"
  frameborder="0"
  style="border:none;overflow:hidden"
></iframe>
```

#### `export_snapshot.py` — static export

Exports the contents of `survey.db` to `snapshot.js`, a JavaScript file that assigns the data to `window.SURVEY_DATA`. This enables `scores-embed.html` to run without server-side processing.

```bash
python export_snapshot.py
python export_snapshot.py --db path/to/survey.db --out path/to/snapshot.js
```

### Configuration

| File | Setting | Description |
| --- | --- | --- |
| `harvester.py` | `DATASET_API` | NGSI-LD dataset catalog URL. |
| `harvester.py` | `DISTRIBUTION_API` | NGSI-LD distribution catalog URL. |
| `harvester.py` | `POLL_INTERVAL_SECONDS` | Polling interval; defaults to `3600` seconds. |
| `db_config.php` | `DB_PATH` | Path to `survey.db`; defaults to the same directory. |

### Thematic groups

The seven scoring groups in the current configuration are:

1. **Attractiveness & Well-being**
2. **Green & Nature Quality**
3. **Urban Design & Heritage**
4. **Functionality & Inclusion**
5. **Social Life & Participation**
6. **Mobility & Transport**
7. **Citizens Engagement**

Group membership is defined in `control.csv` and can be changed without modifying application code.
