<?php
/**
 * Serialization-safe search/replace across every text-like column of every
 * track_* table in the local dev DB. Plain str_replace on a raw SQL dump
 * corrupts PHP-serialized strings (their length prefixes go stale), which
 * silently breaks widgets/options/postmeta on unserialize(). This walks rows
 * so composite serialized values (arrays, objects) get re-serialized after
 * the replace instead of just having their bytes mangled.
 *
 * CLI only. Usage:
 *   php serialize-safe-replace.php <host> <port> <user> <pass> <db> <table_prefix> <old> <new>
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

[, $host, $port, $user, $pass, $db, $prefix, $old, $new] = $argv + array_fill(0, 9, null);

if ($old === null || $new === null) {
    fwrite(STDERR, "Usage: php serialize-safe-replace.php <host> <port> <user> <pass> <db> <table_prefix> <old> <new>\n");
    exit(1);
}

$mysqli = mysqli_connect($host, $user, $pass, $db, (int) $port);
if (!$mysqli) {
    fwrite(STDERR, 'Connect failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

function recursive_replace(string $from, string $to, $data)
{
    if (is_string($data)) {
        $unserialized = @unserialize($data);
        if ($unserialized !== false || $data === 'b:0;') {
            return serialize(recursive_replace($from, $to, $unserialized));
        }
        return str_replace($from, $to, $data);
    }
    if (is_array($data)) {
        $out = [];
        foreach ($data as $k => $v) {
            $out[is_string($k) ? str_replace($from, $to, $k) : $k] = recursive_replace($from, $to, $v);
        }
        return $out;
    }
    if (is_object($data)) {
        foreach ($data as $k => $v) {
            $data->$k = recursive_replace($from, $to, $v);
        }
        return $data;
    }
    return $data;
}

$textTypes = '/char|text|blob|json|enum|set/i';

$tables = [];
$res = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($prefix) . "%'");
while ($row = $res->fetch_row()) {
    $tables[] = $row[0];
}

$totalRows = 0;
$totalCells = 0;

foreach ($tables as $table) {
    $pkCols = [];
    $keyRes = $mysqli->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
    while ($row = $keyRes->fetch_assoc()) {
        $pkCols[] = $row['Column_name'];
    }
    if (!$pkCols) {
        fwrite(STDERR, "Skipping {$table}: no primary key.\n");
        continue;
    }

    $textCols = [];
    $colRes = $mysqli->query("SHOW COLUMNS FROM `{$table}`");
    while ($row = $colRes->fetch_assoc()) {
        if (preg_match($textTypes, $row['Type'])) {
            $textCols[] = $row['Field'];
        }
    }
    if (!$textCols) {
        continue;
    }

    $selectCols = array_unique(array_merge($pkCols, $textCols));
    $colList = '`' . implode('`, `', $selectCols) . '`';
    $dataRes = $mysqli->query("SELECT {$colList} FROM `{$table}`", MYSQLI_USE_RESULT);
    if (!$dataRes) {
        fwrite(STDERR, "Skipping {$table}: {$mysqli->error}\n");
        continue;
    }

    $updates = [];
    while ($row = $dataRes->fetch_assoc()) {
        $set = [];
        foreach ($textCols as $col) {
            $val = $row[$col];
            if ($val === null || strpos($val, $old) === false) {
                continue;
            }
            try {
                $newVal = recursive_replace($old, $new, $val);
            } catch (\Throwable $e) {
                // Serialized blob references a class not loaded outside a full WP
                // bootstrap (e.g. a cached WP_Query in a transient) - leave that cell
                // as-is rather than aborting the whole run.
                fwrite(STDERR, "Skipping {$table}.{$col}: {$e->getMessage()}\n");
                continue;
            }
            if ($newVal !== $val) {
                $set[$col] = $newVal;
            }
        }
        if ($set) {
            $where = [];
            foreach ($pkCols as $pk) {
                $where[] = "`{$pk}` = '" . $mysqli->real_escape_string((string) $row[$pk]) . "'";
            }
            $updates[] = [$set, implode(' AND ', $where)];
        }
    }

    foreach ($updates as [$set, $where]) {
        $assignments = [];
        foreach ($set as $col => $val) {
            $assignments[] = "`{$col}` = '" . $mysqli->real_escape_string($val) . "'";
            $totalCells++;
        }
        $sql = "UPDATE `{$table}` SET " . implode(', ', $assignments) . " WHERE {$where}";
        if (!$mysqli->query($sql)) {
            fwrite(STDERR, "UPDATE failed on {$table}: {$mysqli->error}\n");
        } else {
            $totalRows++;
        }
    }
}

echo "Replaced \"{$old}\" -> \"{$new}\" in {$totalCells} cell(s) across {$totalRows} row(s).\n";
