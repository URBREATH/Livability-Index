<?php
/**
 * db_config.php — Database configuration for survey viewer
 *
 * Adjust DB_PATH to the absolute path of survey.db on your server.
 * Using __DIR__ makes it relative to this file's directory.
 */

define('DB_PATH', __DIR__ . '/survey.db');

/**
 * Return an open SQLite3 connection with foreign keys enabled.
 * Throws an Exception on failure.
 */
function get_db() {
    if (!class_exists('SQLite3')) {
        throw new RuntimeException(
            'The SQLite3 PHP extension is not enabled. ' .
            'In D:\\xampp\\php\\php.ini, uncomment: extension=sqlite3, then restart Apache.'
        );
    }
    if (!file_exists(DB_PATH)) {
        throw new RuntimeException(
            'Database not found at ' . DB_PATH .
            '. Please run: python harvester.py --once'
        );
    }
    $db = new SQLite3(DB_PATH, SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
    $db->exec('PRAGMA foreign_keys = ON;');

    // Migrations — safe to run on every request (no-op if column already exists)
    $loc_cols = [];
    $res = $db->query("PRAGMA table_info(locations)");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $loc_cols[] = $r['name']; }
    if (!in_array('name', $loc_cols, true)) {
        $db->exec("ALTER TABLE locations ADD COLUMN name TEXT");
    }

    $qs_cols = [];
    $res = $db->query("PRAGMA table_info(question_scores)");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $qs_cols[] = $r['name']; }
    if (!in_array('is_indicator', $qs_cols, true)) {
        $db->exec("ALTER TABLE question_scores ADD COLUMN is_indicator INTEGER NOT NULL DEFAULT 0");
    }

    $q_cols = [];
    $res = $db->query("PRAGMA table_info(questions)");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $q_cols[] = $r['name']; }
    if (!in_array('question_id', $q_cols, true)) {
        $db->exec("ALTER TABLE questions ADD COLUMN question_id INTEGER NOT NULL DEFAULT 0");
    }

    return $db;
}
