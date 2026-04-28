"""
export_snapshot.py — Export survey.db to a static JS snapshot
==============================================================
Writes snapshot.js, a plain JS file that assigns all survey data to
window.SURVEY_DATA.  viewer.html loads it directly, so the static
viewer works from the filesystem (file://) or any HTTP server without
needing PHP or SQLite.

Usage:
    python export_snapshot.py
    python export_snapshot.py --db path/to/survey.db --out path/to/snapshot.js
"""

import argparse
import json
import sqlite3
from datetime import datetime, timezone

DB_PATH     = "survey.db"
DEFAULT_OUT = "snapshot.js"

# Preferred canonical order — used as sort key; groups in the DB not listed here
# are appended alphabetically after the known ones.
_PREFERRED_ORDER = [
    "Attractiveness & Well-being",
    "Green & Nature Quality",
    "Urban Design & Heritage",
    "Functionality & Inclusion",
    "Social Life & Participation",
    "Mobility & Transport",
    "Citizens Engagement",
]


def _ordered_groups(names: list) -> list:
    """Return names sorted by preferred canonical order; unknowns appended alphabetically."""
    name_set = set(names)
    known = [n for n in _PREFERRED_ORDER if n in name_set]
    extra = sorted(n for n in names if n not in set(_PREFERRED_ORDER))
    return known + extra


def export(db_path: str, out_path: str) -> None:
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row

    # Migrations: add columns introduced after the initial schema
    loc_cols = {r[1] for r in conn.execute("PRAGMA table_info(locations)").fetchall()}
    if "name" not in loc_cols:
        conn.execute("ALTER TABLE locations ADD COLUMN name TEXT")

    qs_cols = {r[1] for r in conn.execute("PRAGMA table_info(question_scores)").fetchall()}
    if "is_indicator" not in qs_cols:
        conn.execute("ALTER TABLE question_scores ADD COLUMN is_indicator INTEGER NOT NULL DEFAULT 0")

    q_cols = {r[1] for r in conn.execute("PRAGMA table_info(questions)").fetchall()}
    if "question_id" not in q_cols:
        conn.execute("ALTER TABLE questions ADD COLUMN question_id INTEGER NOT NULL DEFAULT 0")

    conn.commit()

    data = {
        "exported_at":          datetime.now(timezone.utc).isoformat(),
        "group_order":          [],   # derived from DB after loading scores
        "surveys":              [],
        "group_score_summaries": {},
        "question_summaries":   {},
    }

    # ── surveys ──────────────────────────────────────────────────────────────
    surveys = conn.execute(
        "SELECT s.*, l.latitude, l.longitude, l.name AS location_name "
        "FROM surveys s JOIN locations l ON l.id = s.location_id "
        "ORDER BY s.release_date DESC, s.ingested_at DESC"
    ).fetchall()

    # Pre-load per-survey group scores & binary indicators (may not exist)
    gs_by_survey:  dict[int, dict[str, float]] = {}
    ind_by_survey: dict[int, list[dict]]       = {}
    try:
        for r in conn.execute(
            "SELECT * FROM group_scores ORDER BY survey_id, group_name"
        ).fetchall():
            gs_by_survey.setdefault(r["survey_id"], {})[r["group_name"]] = round(float(r["group_score"]), 4)

        for r in conn.execute("""
            SELECT survey_id, question_text, group_name, weighted_score
            FROM question_scores
            WHERE is_indicator = 1
            ORDER BY survey_id, group_name, question_text
        """).fetchall():
            ind_by_survey.setdefault(r["survey_id"], []).append({
                "question_text": r["question_text"],
                "group_name":    r["group_name"],
                "weighted_score": round(float(r["weighted_score"]), 4),
            })
    except Exception as exc:
        print(f"[warn] Scoring tables not available ({exc}); scores will be empty.")

    for s in surveys:
        sid = s["id"]
        survey_obj: dict = {
            "id":                         sid,
            "dataset_title":              s["dataset_title"],
            "location_name":              s["location_name"],
            "latitude":                   float(s["latitude"]),
            "longitude":                  float(s["longitude"]),
            "release_date":               s["release_date"],
            "distribution_modified_date": s["distribution_modified_date"],
            "ingested_at":                s["ingested_at"],
            "group_scores":               gs_by_survey.get(sid, {}),
            "indicators":                 ind_by_survey.get(sid, []),
            "questions":                  [],
        }

        for q in conn.execute(
            "SELECT * FROM questions WHERE survey_id = ? ORDER BY section, order_index",
            (sid,),
        ).fetchall():
            qid = q["id"]
            q_obj: dict = {
                "question_text": q["question_text"],
                "section":       q["section"],
                "question_type": q["question_type"],
                "order_index":   q["order_index"],
                "choice_answers":    [],
                "open_text_answers": [],
            }

            if q["question_type"] == "choice":
                for a in conn.execute(
                    "SELECT * FROM choice_answers WHERE question_id = ? ORDER BY order_index",
                    (qid,),
                ).fetchall():
                    q_obj["choice_answers"].append({
                        "answer_text":       a["answer_text"],
                        "percentage":        float(a["percentage"]) if a["percentage"] is not None else 0.0,
                        "respondents_count": a["respondents_count"] or 0,
                        "order_index":       a["order_index"],
                    })

            for ot in conn.execute(
                "SELECT * FROM open_text_answers WHERE question_id = ? ORDER BY order_index",
                (qid,),
            ).fetchall():
                q_obj["open_text_answers"].append({
                    "answer_text": ot["answer_text"],
                })

            survey_obj["questions"].append(q_obj)

        data["surveys"].append(survey_obj)

    # ── group_score_summaries ─────────────────────────────────────────────────
    try:
        for r in conn.execute(
            "SELECT * FROM group_score_summaries ORDER BY group_name"
        ).fetchall():
            data["group_score_summaries"][r["group_name"]] = {
                "aggregate_score": round(float(r["aggregate_score"]), 4),
                "survey_count":    r["survey_count"],
                "last_updated":    r["last_updated"],
            }
    except Exception:
        pass

    # ── group_order ───────────────────────────────────────────────────────────
    gs_names = list(data["group_score_summaries"].keys())
    if not gs_names:
        # Summaries may not exist yet on first run; fall back to group_scores rows
        try:
            gs_names = [r[0] for r in conn.execute(
                "SELECT DISTINCT group_name FROM group_scores"
            ).fetchall()]
        except Exception:
            pass
    data["group_order"] = _ordered_groups(gs_names) if gs_names else _PREFERRED_ORDER

    # ── question_summaries ────────────────────────────────────────────────────
    try:
        for r in conn.execute(
            "SELECT * FROM question_summaries "
            "ORDER BY question_text, total_respondents DESC"
        ).fetchall():
            data["question_summaries"].setdefault(r["question_text"], []).append({
                "answer_text":       r["answer_text"],
                "total_respondents": r["total_respondents"],
                "total_percentage":  round(float(r["total_percentage"]), 4),
                "survey_count":      r["survey_count"],
            })
    except Exception:
        pass

    conn.close()

    json_str = json.dumps(data, ensure_ascii=False, indent=2)
    with open(out_path, "w", encoding="utf-8") as f:
        f.write("/* Auto-generated by export_snapshot.py — do not edit manually */\n")
        f.write("/* Run: python export_snapshot.py  to refresh */\n")
        f.write("window.SURVEY_DATA = ")
        f.write(json_str)
        f.write(";\n")

    print(
        f"Exported {len(data['surveys'])} survey(s), "
        f"{len(data['group_score_summaries'])} group score(s) → {out_path}"
    )


def main() -> None:
    parser = argparse.ArgumentParser(description="Export survey.db to a static JS snapshot")
    parser.add_argument("--db",  default=DB_PATH,     help="Path to survey.db")
    parser.add_argument("--out", default=DEFAULT_OUT, help="Output path (default: snapshot.js)")
    args = parser.parse_args()
    export(args.db, args.out)


if __name__ == "__main__":
    main()
