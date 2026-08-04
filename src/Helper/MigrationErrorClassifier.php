<?php

declare(strict_types=1);

namespace App\Helper;

/**
 * Decides whether a PDOException from a migration statement is a WARN
 * (idempotent re-run, file marks applied, runner continues) or a FAIL
 * (loud error, file still marks applied but the operator sees red).
 *
 * Historically bin/migrate treated a fixed set of error codes as WARN
 * regardless of statement shape. That swallowed real typos: a fresh-
 * apply migration with `INSERT INTO permissions (..., wrong_col, ...)`
 * raised 1054 "Unknown column", which got logged as WARN and the
 * permission row never landed. Caught 2026-05-21 when 1.5.221 used
 * `category` instead of `module` and the gateway-audit page 403'd on
 * Super Admin even though DB.app_version advanced to 1.5.221.
 *
 * Tightened rule: 1054 (Unknown column) and 1146 (Unknown table) are
 * only WARN when the statement is a schema-change that legitimately may
 * target a no-longer-existing column or table — ALTER TABLE … CHANGE /
 * MODIFY / RENAME / DROP, or DROP TABLE / RENAME TABLE. On INSERT,
 * UPDATE, DELETE, SELECT, or CREATE statements, the same error code
 * almost certainly means a typo and the operator needs to see it.
 *
 * Other warn codes (1050 already exists table, 1060 dup column, 1061
 * dup key, 1068 multi PK, 1091 can't drop nonexistent, 1826 dup FK)
 * stay unconditionally lenient — they're idempotent-DDL safety nets
 * for paths handle_idempotent_ddl() doesn't pre-check.
 */
class MigrationErrorClassifier
{
    /** Always-warn codes — pure "already exists / no longer there" idempotency. */
    private const UNCONDITIONAL_WARN = [
        '1050', // ER_TABLE_EXISTS_ERROR
        '1060', // ER_DUP_FIELDNAME
        '1061', // ER_DUP_KEYNAME
        '1068', // ER_MULTIPLE_PRI_KEY
        '1091', // ER_CANT_DROP_FIELD_OR_KEY (DROP INDEX/COLUMN/FK that's gone)
        '1826', // ER_DUP_CONSTRAINT_NAME
    ];

    /**
     * Codes that are only warnable when the statement shape is one where
     * "missing" is a legitimate idempotent state (the rename/drop family).
     * On data-mutation statements (INSERT/UPDATE/DELETE) or pure SELECTs,
     * these mean typo and must FAIL.
     */
    private const CONDITIONAL_WARN = [
        '1054', // ER_BAD_FIELD_ERROR (Unknown column)
        '1146', // ER_NO_SUCH_TABLE
    ];

    /**
     * Returns true when the runner should treat this (errorCode, statement)
     * combination as a WARN. False means FAIL (loud, exit code 1).
     */
    public static function isWarnable(string $errorCode, string $statement): bool
    {
        $errorCode = trim($errorCode);
        if (in_array($errorCode, self::UNCONDITIONAL_WARN, true)) {
            return true;
        }
        if (in_array($errorCode, self::CONDITIONAL_WARN, true)) {
            return self::isRenameOrDropStatement($statement);
        }
        return false;
    }

    /**
     * Schema-change statements where "thing doesn't exist" is a normal
     * idempotency outcome: ALTER TABLE … CHANGE / MODIFY / RENAME / DROP,
     * DROP TABLE [IF EXISTS], RENAME TABLE. Anything else (INSERT /
     * UPDATE / DELETE / CREATE / SELECT) treating 1054 or 1146 as a
     * warning would mask a typo.
     */
    private static function isRenameOrDropStatement(string $statement): bool
    {
        $q = preg_replace('/\s+/', ' ', trim($statement)) ?? '';
        // ALTER TABLE … with CHANGE / MODIFY / RENAME COLUMN / DROP COLUMN / DROP …
        if (preg_match('/^ALTER\s+TABLE\b/i', $q)) {
            return (bool) preg_match('/\b(CHANGE|MODIFY|RENAME|DROP)\b/i', $q);
        }
        // DROP TABLE / DROP INDEX / DROP VIEW — the target legitimately may be gone
        if (preg_match('/^DROP\s+(TABLE|INDEX|VIEW|TRIGGER|PROCEDURE|FUNCTION|FOREIGN\s+KEY)\b/i', $q)) {
            return true;
        }
        // RENAME TABLE foo TO bar
        if (preg_match('/^RENAME\s+TABLE\b/i', $q)) {
            return true;
        }
        return false;
    }
}
