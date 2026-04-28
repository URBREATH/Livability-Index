"""
harvester.py — Survey Harvester for Urbreath Livability Index
=============================================================
Polls the NGSI-LD catalog every hour, discovers survey datasets
(keyword: Livability + index + survey), downloads new CSVs, parses
them into SQLite and recalculates the cross-survey summary table.

Survey scoring is driven entirely by control.csv (located alongside the
survey file in the files array).  No question texts are hardcoded here.

control.csv format (semicolon-delimited, no header row):
    Survey cluster;              <location name>
    Open Text;                   <comma-separated question IDs>
    reverse 5-point Likert scale;<comma-separated question IDs>
    binary score;                <comma-separated question IDs>
    do not score;                <comma-separated question IDs>
    <Group Name> Group;          <comma-separated question IDs>
    ...

Survey CSV format (4 columns, semicolon-delimited):
    <question_id>;<question_text_or_blank>;<percent>;<respondents>

    - The first column carries the numeric question ID for question rows.
    - Answer rows have an empty first column.
    - Open-text answer rows have an empty first column and no numeric columns.

Scoring rules (applied per answer position within a question, 0-based):
    Standard 5-pt Likert : scores  -2, -1,  0,  1,  2
    Reversed 5-pt Likert : scores   2,  1,  0, -1, -2
    Binary (Ja/Nej/3)    : scores   1, -1,  0  (by position)

Usage:
    python harvester.py            # run continuously (hourly)
    python harvester.py --once     # run once and exit (for testing)
"""

import argparse
import csv
import io
import logging
import os
import sqlite3
import sys
import time
from datetime import datetime, timezone

import requests

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

DATASET_API        = "https://urbreath.virtualcitymap.de/idraproxy/api/datasetngsi/dataset"
DISTRIBUTION_API   = "https://urbreath.virtualcitymap.de/idraproxy/api/datasetngsi/distributiondcatap"
CONTROL_CSV_NAME   = "control.csv"
DB_PATH            = "survey.db"
POLL_INTERVAL_SECONDS = 3600      # 1 hour
SURVEY_KEYWORDS    = {"livability", "index", "survey"}
CSV_ENCODINGS      = ("utf-8-sig", "utf-8", "cp1252")
REQUEST_TIMEOUT    = 30           # seconds

# Scores assigned by answer-position for each question type
SCORES_STANDARD = [-2.0, -1.0, 0.0, 1.0, 2.0]
SCORES_REVERSED = [ 2.0,  1.0, 0.0,-1.0,-2.0]
SCORES_BINARY   = [ 1.0, -1.0, 0.0]          # Ja / Nej / 3

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler("harvester.log", encoding="utf-8"),
    ],
)
log = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Database setup
# ---------------------------------------------------------------------------

DDL = """
PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;

CREATE TABLE IF NOT EXISTS locations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    dataset_id  TEXT    UNIQUE NOT NULL,
    name        TEXT,
    latitude    REAL    NOT NULL,
    longitude   REAL    NOT NULL
);

CREATE TABLE IF NOT EXISTS surveys (
    id                           INTEGER PRIMARY KEY AUTOINCREMENT,
    location_id                  INTEGER NOT NULL REFERENCES locations(id),
    dataset_title                TEXT,
    distribution_id              TEXT    UNIQUE NOT NULL,
    csv_url                      TEXT,
    release_date                 TEXT,
    distribution_modified_date   TEXT,
    ingested_at                  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS questions (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    survey_id      INTEGER NOT NULL REFERENCES surveys(id),
    question_id    INTEGER NOT NULL,
    question_text  TEXT    NOT NULL,
    section        TEXT    NOT NULL DEFAULT 'survey',
    question_type  TEXT    NOT NULL DEFAULT 'choice',
    order_index    INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS choice_answers (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id       INTEGER NOT NULL REFERENCES questions(id),
    answer_text       TEXT    NOT NULL,
    percentage        REAL,
    respondents_count INTEGER,
    order_index       INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS open_text_answers (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id          INTEGER NOT NULL REFERENCES questions(id),
    answer_text          TEXT    NOT NULL,
    order_index          INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS question_summaries (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id       INTEGER NOT NULL,
    question_text     TEXT    NOT NULL,
    answer_text       TEXT    NOT NULL,
    total_respondents INTEGER NOT NULL DEFAULT 0,
    total_percentage  REAL    NOT NULL DEFAULT 0,
    survey_count      INTEGER NOT NULL DEFAULT 0,
    last_updated      TEXT    NOT NULL,
    UNIQUE(question_id, answer_text)
);

CREATE TABLE IF NOT EXISTS question_scores (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    survey_id        INTEGER NOT NULL REFERENCES surveys(id),
    question_id      INTEGER NOT NULL,
    question_text    TEXT    NOT NULL,
    group_name       TEXT    NOT NULL,
    weighted_score   REAL    NOT NULL,
    respondent_count INTEGER NOT NULL,
    is_indicator     INTEGER NOT NULL DEFAULT 0,
    UNIQUE(survey_id, question_id)
);

CREATE TABLE IF NOT EXISTS group_scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    survey_id      INTEGER NOT NULL REFERENCES surveys(id),
    group_name     TEXT    NOT NULL,
    group_score    REAL    NOT NULL,
    question_count INTEGER NOT NULL,
    UNIQUE(survey_id, group_name)
);

CREATE TABLE IF NOT EXISTS group_score_summaries (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    group_name      TEXT    NOT NULL UNIQUE,
    aggregate_score REAL    NOT NULL,
    survey_count    INTEGER NOT NULL,
    last_updated    TEXT    NOT NULL
);
"""


def get_db() -> sqlite3.Connection:
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.executescript(DDL)
    return conn


# ---------------------------------------------------------------------------
# control.csv parsing
# ---------------------------------------------------------------------------

def parse_ids(value: str) -> list[int]:
    """Parse a comma-separated list of question IDs, ignoring non-integer tokens."""
    result = []
    for token in value.split(","):
        token = token.strip()
        try:
            result.append(int(token))
        except ValueError:
            pass  # e.g. "Samlet status" in do-not-score
    return result


class SurveyControl:
    """
    Holds all configuration parsed from control.csv for a single survey cluster.
    """
    def __init__(self, raw_bytes: bytes) -> None:
        text   = _decode(raw_bytes)
        reader = csv.reader(io.StringIO(text), delimiter=";")
        rows   = [row for row in reader if row and row[0].strip()]

        self.location_name: str        = ""
        self.open_text_ids:  set[int]  = set()
        self.reversed_ids:   set[int]  = set()
        self.binary_ids:     set[int]  = set()
        self.no_score_ids:   set[int]  = set()
        self.groups: dict[str, list[int]] = {}

        for row in rows:
            key   = row[0].strip()
            value = row[1].strip() if len(row) > 1 else ""
            lower = key.lower()

            if lower == "survey cluster":
                self.location_name = value
            elif lower == "open text":
                self.open_text_ids = set(parse_ids(value))
            elif lower == "reverse 5-point likert scale":
                self.reversed_ids = set(parse_ids(value))
            elif lower == "binary score":
                self.binary_ids = set(parse_ids(value))
            elif lower == "do not score":
                self.no_score_ids = set(parse_ids(value))
            elif key.endswith(" Group"):
                group_name = key[: -len(" Group")].strip()
                self.groups[group_name] = parse_ids(value)

        # Reverse lookup: question_id -> group_name
        self._id_to_group: dict[int, str] = {}
        for group_name, ids in self.groups.items():
            for qid in ids:
                self._id_to_group[qid] = group_name

    def group_for(self, question_id: int) -> str | None:
        return self._id_to_group.get(question_id)

    def score_type(self, question_id: int) -> str:
        """Return 'reversed', 'binary', 'standard', or 'none'."""
        if question_id in self.no_score_ids:
            return "none"
        if question_id in self.binary_ids:
            return "binary"
        if question_id in self.reversed_ids:
            return "reversed"
        return "standard"

    def is_indicator(self, question_id: int) -> bool:
        return question_id in self.binary_ids

    def is_open_text(self, question_id: int) -> bool:
        return question_id in self.open_text_ids


# ---------------------------------------------------------------------------
# CSV helpers
# ---------------------------------------------------------------------------

def _decode(raw_bytes: bytes) -> str:
    for enc in CSV_ENCODINGS:
        try:
            return raw_bytes.decode(enc)
        except UnicodeDecodeError:
            continue
    raise ValueError("Could not decode CSV with any known encoding")


def normalise_number(value: str) -> float | None:
    v = value.strip()
    if not v:
        return None
    try:
        return float(v.replace(",", "."))
    except ValueError:
        return None


# ---------------------------------------------------------------------------
# Survey CSV parsing
# ---------------------------------------------------------------------------

def parse_survey_csv(raw_bytes: bytes, control: SurveyControl) -> list[dict]:
    """
    Parse the 4-column survey CSV (question_id; text; percent; respondents).

    Returns a list of question dicts:
    {
        "question_id":   int,
        "question_text": str,
        "section":       "survey" | "summary",
        "question_type": "choice" | "open_text",
        "answers":       [{"answer_text": str, "percentage": float|None,
                           "respondents_count": int, "score": float|None}],
        "open_texts":    [{"answer_text": str}],
    }
    """
    text   = _decode(raw_bytes)
    # Normalise every row to exactly 4 stripped columns
    raw_rows = list(csv.reader(io.StringIO(text), delimiter=";"))
    rows = [([c.strip() for c in r] + ["", "", "", ""])[:4] for r in raw_rows]
    n = len(rows)

    questions:  list[dict] = []
    current_q:  dict | None = None
    section = "survey"

    def flush():
        nonlocal current_q
        if current_q is not None:
            questions.append(current_q)
            current_q = None

    i = 0
    while i < n:
        col0, col1, col2, col3 = rows[i]

        # ── New question row: col0 is a non-empty integer ──────────────────
        qid_int = None
        if col0:
            try:
                qid_int = int(col0)
            except ValueError:
                pass

        if qid_int is not None:
            flush()
            if col1.lower() == "samlet status":
                section = "summary"
            q_type = "open_text" if control.is_open_text(qid_int) else "choice"
            # col1 may be a sub-question context label (e.g. "Parent q - ").
            # If the very next row is a text-only row followed by a header,
            # that row IS the real question text — consume it now.
            q_text = col1
            if i + 1 < n:
                n1 = rows[i + 1]
                if not n1[0] and n1[1] and not n1[2] and not n1[3]:
                    if i + 2 < n:
                        n2 = rows[i + 2]
                        if not n2[0] and not n2[1] and n2[2].lower() in ("procent", "%"):
                            q_text = n1[1]
                            i += 1  # consume the text row
            current_q = {
                "question_id":   qid_int,
                "question_text": q_text,
                "section":       section,
                "question_type": q_type,
                "answers":       [],
                "open_texts":    [],
            }
            i += 1
            continue

        # ── Column header row ──────────────────────────────────────────────
        if not col0 and not col1 and col2.lower() in ("procent", "%"):
            i += 1
            continue

        # ── Answer / open-text row: col0 empty, col1 has text ─────────────
        if not col0 and col1:
            if current_q is None:
                i += 1
                continue
            if col1.lower() == "i alt":
                i += 1
                continue
            if col1.lower() == "samlet status":
                flush()
                section = "summary"
                i += 1
                continue

            # Skip e-mail label and any e-mail addresses
            if col1.lower() == "e-mail" or "@" in col1:
                i += 1
                continue

            # Skip sub-question context rows: a text-only row (no numbers)
            # immediately followed by a Procent/Respondenter header.
            pct = normalise_number(col2)
            cnt = normalise_number(col3)
            if pct is None and cnt is None and i + 1 < n:
                nxt = rows[i + 1]
                if not nxt[0] and not nxt[1] and nxt[2].lower() in ("procent", "%"):
                    i += 1
                    continue

            if current_q["question_type"] == "open_text" or (pct is None and cnt is None):
                current_q["open_texts"].append({"answer_text": col1})
            else:
                pos   = len(current_q["answers"])
                score = _position_score(current_q["question_id"], pos, control)
                current_q["answers"].append({
                    "answer_text":       col1,
                    "percentage":        pct,
                    "respondents_count": int(cnt) if cnt is not None else 0,
                    "score":             score,
                })

        i += 1

    flush()
    return questions


def _position_score(question_id: int, position: int,
                    control: SurveyControl) -> float | None:
    stype = control.score_type(question_id)
    if stype == "none":
        return None
    scale = (SCORES_BINARY   if stype == "binary"   else
             SCORES_REVERSED if stype == "reversed" else
             SCORES_STANDARD)
    return scale[position] if position < len(scale) else None


# ---------------------------------------------------------------------------
# API helpers
# ---------------------------------------------------------------------------

def fetch_json(url: str) -> list:
    resp = requests.get(url, timeout=REQUEST_TIMEOUT)
    resp.raise_for_status()
    return resp.json()


def distribution_key_from_urn(urn: str) -> str:
    return urn.rsplit(":", 1)[-1]


def distribution_id_from_key(key: str) -> str:
    return f"urn:ngsi-ld:DistributionDCAT-AP:id:{key}"


def has_survey_keywords(entry: dict) -> bool:
    raw = entry.get("keyword", [])
    if isinstance(raw, str):
        kws = {raw.lower()}
    else:
        kws = {k.lower() for k in raw}
    return SURVEY_KEYWORDS.issubset(kws)


def extract_datetime_value(field) -> str | None:
    if field is None:
        return None
    if isinstance(field, dict):
        return field.get("@value") or field.get("value")
    return str(field)


# ---------------------------------------------------------------------------
# Database write operations
# ---------------------------------------------------------------------------

def upsert_location(conn: sqlite3.Connection, dataset_id: str,
                    name: str, latitude: float, longitude: float) -> int:
    conn.execute(
        "INSERT INTO locations (dataset_id, name, latitude, longitude) "
        "VALUES (?, ?, ?, ?) "
        "ON CONFLICT(dataset_id) DO UPDATE SET "
        "name=excluded.name, latitude=excluded.latitude, longitude=excluded.longitude",
        (dataset_id, name, latitude, longitude),
    )
    return conn.execute(
        "SELECT id FROM locations WHERE dataset_id = ?", (dataset_id,)
    ).fetchone()["id"]


def insert_survey(conn: sqlite3.Connection, location_id: int,
                  dataset_title: str, distribution_id: str,
                  csv_url: str, release_date: str | None,
                  distribution_modified_date: str | None) -> int:
    ingested_at = datetime.now(timezone.utc).isoformat()
    conn.execute(
        "INSERT INTO surveys "
        "(location_id, dataset_title, distribution_id, csv_url, "
        " release_date, distribution_modified_date, ingested_at) "
        "VALUES (?, ?, ?, ?, ?, ?, ?)",
        (location_id, dataset_title, distribution_id, csv_url,
         release_date, distribution_modified_date, ingested_at),
    )
    return conn.execute(
        "SELECT id FROM surveys WHERE distribution_id = ?", (distribution_id,)
    ).fetchone()["id"]


def insert_questions(conn: sqlite3.Connection, survey_id: int,
                     parsed_questions: list[dict]) -> None:
    for qi, q in enumerate(parsed_questions):
        conn.execute(
            "INSERT INTO questions "
            "(survey_id, question_id, question_text, section, question_type, order_index) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (survey_id, q["question_id"], q["question_text"],
             q["section"], q["question_type"], qi),
        )
        db_q_id = conn.execute(
            "SELECT id FROM questions WHERE survey_id=? AND order_index=?",
            (survey_id, qi),
        ).fetchone()["id"]

        for ai, ans in enumerate(q.get("answers", [])):
            conn.execute(
                "INSERT INTO choice_answers "
                "(question_id, answer_text, percentage, respondents_count, order_index) "
                "VALUES (?, ?, ?, ?, ?)",
                (db_q_id, ans["answer_text"], ans["percentage"],
                 ans["respondents_count"], ai),
            )

        for oi, ot in enumerate(q.get("open_texts", [])):
            conn.execute(
                "INSERT INTO open_text_answers (question_id, answer_text, order_index) "
                "VALUES (?, ?, ?)",
                (db_q_id, ot["answer_text"], oi),
            )


def compute_scores(conn: sqlite3.Connection, survey_id: int,
                   parsed_questions: list[dict],
                   control: SurveyControl) -> None:
    """Compute question_scores, group_scores, and group_score_summaries for one survey."""
    now = datetime.now(timezone.utc).isoformat()

    for q in parsed_questions:
        qid   = q["question_id"]
        gname = control.group_for(qid)
        stype = control.score_type(qid)

        if gname is None or stype == "none" or not q.get("answers"):
            continue

        weighted = sum(
            (ans["percentage"] or 0.0) * ans["score"]
            for ans in q["answers"]
            if ans["score"] is not None
        )
        total_respondents = max(
            (ans["respondents_count"] for ans in q["answers"]), default=0
        )

        conn.execute("""
            INSERT OR REPLACE INTO question_scores
                (survey_id, question_id, question_text, group_name,
                 weighted_score, respondent_count, is_indicator)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        """, (survey_id, qid, q["question_text"], gname,
              weighted, total_respondents,
              1 if control.is_indicator(qid) else 0))

    # group_scores: mean of non-indicator scores for this survey
    for row in conn.execute("""
        SELECT group_name, AVG(weighted_score) AS group_score, COUNT(*) AS question_count
        FROM question_scores
        WHERE survey_id = ? AND is_indicator = 0
        GROUP BY group_name
    """, (survey_id,)).fetchall():
        conn.execute("""
            INSERT OR REPLACE INTO group_scores
                (survey_id, group_name, group_score, question_count)
            VALUES (?, ?, ?, ?)
        """, (survey_id, row["group_name"], row["group_score"], row["question_count"]))

    # group_score_summaries: aggregate across all surveys
    for row in conn.execute("""
        SELECT group_name, AVG(group_score) AS aggregate_score, COUNT(*) AS survey_count
        FROM group_scores GROUP BY group_name
    """).fetchall():
        conn.execute("""
            INSERT OR REPLACE INTO group_score_summaries
                (group_name, aggregate_score, survey_count, last_updated)
            VALUES (?, ?, ?, ?)
        """, (row["group_name"], row["aggregate_score"], row["survey_count"], now))

    log.info("Scores computed for survey_id=%d", survey_id)


def rebuild_summaries(conn: sqlite3.Connection) -> None:
    """Rebuild question_summaries by aggregating choice_answers across all surveys."""
    now = datetime.now(timezone.utc).isoformat()

    rows = conn.execute("""
        SELECT q.question_id, q.question_text, ca.answer_text,
               ca.respondents_count, q.survey_id
        FROM choice_answers ca
        JOIN questions q ON q.id = ca.question_id
        WHERE ca.respondents_count IS NOT NULL
    """).fetchall()

    agg:                   dict[tuple, dict] = {}
    survey_ids_per_key:    dict[tuple, set]  = {}
    total_per_qid:         dict[int, int]    = {}

    for row in rows:
        qid = row["question_id"]
        key = (qid, row["answer_text"])
        if key not in agg:
            agg[key] = {"question_text": row["question_text"], "total_respondents": 0}
            survey_ids_per_key[key] = set()
        agg[key]["total_respondents"] += row["respondents_count"]
        survey_ids_per_key[key].add(row["survey_id"])
        total_per_qid[qid] = total_per_qid.get(qid, 0) + row["respondents_count"]

    for (qid, at), data in agg.items():
        total_q = total_per_qid.get(qid, 0)
        pct     = (data["total_respondents"] / total_q) if total_q > 0 else 0.0
        conn.execute("""
            INSERT INTO question_summaries
                (question_id, question_text, answer_text,
                 total_respondents, total_percentage, survey_count, last_updated)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(question_id, answer_text) DO UPDATE SET
                question_text     = excluded.question_text,
                total_respondents = excluded.total_respondents,
                total_percentage  = excluded.total_percentage,
                survey_count      = excluded.survey_count,
                last_updated      = excluded.last_updated
        """, (qid, data["question_text"], at,
              data["total_respondents"], pct,
              len(survey_ids_per_key[(qid, at)]), now))


# ---------------------------------------------------------------------------
# Core harvest logic
# ---------------------------------------------------------------------------

def already_ingested(conn: sqlite3.Connection, distribution_id: str) -> bool:
    return conn.execute(
        "SELECT 1 FROM surveys WHERE distribution_id = ?", (distribution_id,)
    ).fetchone() is not None


def _find_control_url(ds: dict, dist_by_id: dict) -> str | None:
    """
    Search for control.csv in the dataset's file/distribution list.
    Strategy 1: ds["files"] list with name/downloadURL entries.
    Strategy 2: Additional distributions whose title contains "control.csv".
    Strategy 3: Local control.csv file alongside this script (development fallback).
    """
    for f in ds.get("files", []):
        name = (f.get("name") or f.get("title") or "").lower()
        if name == CONTROL_CSV_NAME:
            url = f.get("downloadURL") or f.get("accessURL") or ""
            if url.startswith("http"):
                return url

    raw_dist = ds.get("datasetDistribution", [])
    if isinstance(raw_dist, str):
        raw_dist = [raw_dist]
    for urn in raw_dist:
        key   = distribution_key_from_urn(urn)
        entry = dist_by_id.get(distribution_id_from_key(key), {})
        title = (entry.get("title") or entry.get("name") or "").lower()
        if CONTROL_CSV_NAME in title:
            url = entry.get("downloadURL", "")
            if url.startswith("http"):
                return url

    # Local fallback: control.csv sitting next to this script
    local_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), CONTROL_CSV_NAME)
    if os.path.exists(local_path):
        log.info("Using local %s as control file", local_path)
        return "file://" + local_path

    return None


def harvest_once() -> None:
    log.info("Starting harvest run")

    try:
        datasets = fetch_json(DATASET_API)
    except Exception as exc:
        log.error("Failed to fetch dataset API: %s", exc)
        return

    survey_datasets = [d for d in datasets if has_survey_keywords(d)]
    log.info("Found %d survey dataset(s) with matching keywords", len(survey_datasets))
    if not survey_datasets:
        return

    try:
        distributions = fetch_json(DISTRIBUTION_API)
    except Exception as exc:
        log.error("Failed to fetch distribution API: %s", exc)
        return

    dist_by_id = {d["id"]: d for d in distributions}
    conn       = get_db()
    new_surveys = 0

    try:
        for ds in survey_datasets:
            raw_dist = ds.get("datasetDistribution", [])
            if isinstance(raw_dist, str):
                raw_dist = [raw_dist]
            if not raw_dist:
                log.warning("Dataset %s has no datasetDistribution, skipping", ds.get("id"))
                continue

            # ── Load control.csv once per dataset ─────────────────────────
            control_url = _find_control_url(ds, dist_by_id)
            if not control_url:
                log.warning("Dataset %s has no control.csv, skipping", ds.get("id"))
                continue

            try:
                if control_url.startswith("file://"):
                    local_path = control_url[7:]
                    with open(local_path, "rb") as fh:
                        ctrl_bytes = fh.read()
                else:
                    ctrl_resp = requests.get(control_url, timeout=REQUEST_TIMEOUT)
                    ctrl_resp.raise_for_status()
                    ctrl_bytes = ctrl_resp.content
                control = SurveyControl(ctrl_bytes)
            except Exception as exc:
                log.error("Failed to load control.csv for dataset %s: %s",
                          ds.get("id"), exc)
                continue

            # ── Spatial data ───────────────────────────────────────────────
            spatial = ds.get("spatial", {})
            coords  = spatial.get("coordinates", [None, None])
            if len(coords) < 2 or coords[0] is None:
                log.warning("Dataset %s has no valid spatial coordinates, skipping",
                            ds.get("id"))
                continue
            longitude, latitude = float(coords[0]), float(coords[1])

            # ── Iterate over all distributions except control.csv ──────────
            for urn in raw_dist:
                full_dist_id = distribution_id_from_key(
                    distribution_key_from_urn(urn)
                )

                dist_entry = dist_by_id.get(full_dist_id)
                if not dist_entry:
                    log.warning("Distribution %s not found in catalog, skipping", full_dist_id)
                    continue

                download_url = dist_entry.get("downloadURL", "").strip()
                if not download_url:
                    continue

                # Skip the control.csv distribution (by title, URL match, or filename)
                dist_title = (dist_entry.get("title") or dist_entry.get("name") or "").lower()
                url_filename = download_url.rsplit("/", 1)[-1].lower()
                if (CONTROL_CSV_NAME in dist_title
                        or download_url == control_url
                        or url_filename == CONTROL_CSV_NAME):
                    continue

                # Skip non-CSV files (e.g. .xlsx)
                if not url_filename.endswith(".csv"):
                    log.info("Skipping non-CSV distribution %s (%s)", full_dist_id, url_filename)
                    continue

                if not download_url.startswith("http"):
                    log.warning("Distribution %s has no valid downloadURL: %r",
                                full_dist_id, download_url)
                    continue

                if already_ingested(conn, full_dist_id):
                    log.info("Survey %s already in DB, skipping", full_dist_id)
                    continue

                # ── Download and parse survey CSV ──────────────────────────
                log.info("Downloading survey CSV from %s", download_url)
                try:
                    csv_resp = requests.get(download_url, timeout=REQUEST_TIMEOUT)
                    csv_resp.raise_for_status()
                except Exception as exc:
                    log.error("Failed to download CSV %s: %s", download_url, exc)
                    continue

                try:
                    parsed_qs = parse_survey_csv(csv_resp.content, control)
                except Exception as exc:
                    log.error("Failed to parse CSV from %s: %s", download_url, exc)
                    continue

                # ── Write to DB in a single transaction ────────────────────
                with conn:
                    loc_id    = upsert_location(conn, ds.get("id", full_dist_id),
                                                control.location_name, latitude, longitude)
                    survey_id = insert_survey(
                        conn, loc_id,
                        ds.get("title", "Untitled Survey"),
                        full_dist_id, download_url,
                        extract_datetime_value(ds.get("releaseDate")),
                        extract_datetime_value(dist_entry.get("modifiedDate")),
                    )
                    insert_questions(conn, survey_id, parsed_qs)
                    compute_scores(conn, survey_id, parsed_qs, control)
                    rebuild_summaries(conn)

                log.info(
                    "Ingested survey '%s' (id=%d): %d question(s), "
                    "location='%s' (%.6f, %.6f)",
                    ds.get("title", "Untitled Survey"), survey_id, len(parsed_qs),
                    control.location_name, latitude, longitude,
                )
                new_surveys += 1

    finally:
        conn.close()

    log.info("Harvest run complete. New surveys ingested: %d", new_surveys)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(description="Urbreath Survey Harvester")
    parser.add_argument(
        "--once", action="store_true",
        help="Run a single harvest pass and exit (default: run hourly)"
    )
    args = parser.parse_args()

    if args.once:
        harvest_once()
        return

    log.info("Harvester starting — polling every %d seconds", POLL_INTERVAL_SECONDS)
    while True:
        harvest_once()
        log.info("Sleeping for %d seconds until next poll...", POLL_INTERVAL_SECONDS)
        time.sleep(POLL_INTERVAL_SECONDS)


if __name__ == "__main__":
    main()
