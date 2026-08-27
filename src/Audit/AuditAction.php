<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * The things worth keeping a permanent record of.
 *
 * These strings are stored and then read back by people asking a question
 * months later, so they are `noun.verb` and they never change. Renaming one
 * does not rewrite history: it splits it, and the half under the old name
 * stops matching any filter anybody thinks to apply.
 *
 * WHAT BELONGS HERE IS NARROW ON PURPOSE. `api_logs` already records every
 * request, and it is pruned because it is exhaust. This table is evidence, is
 * never pruned, and answers "who did this, and when" about actions where the
 * answer has consequences — somebody's access, somebody's account, or a
 * submitted assessment.
 *
 * Reading is not audited. Recording every list view would bury the seven
 * actions here under a hundred thousand rows that say nothing happened, and
 * `api_logs` already knows who fetched what.
 */
final class AuditAction
{
    // ─── access ───

    /** A session began. The row carries where from and on what. */
    public const SIGNED_IN = 'auth.signed_in';

    /** A session was ended deliberately. */
    public const SIGNED_OUT = 'auth.signed_out';

    /** Somebody changed their own password. */
    public const PASSWORD_CHANGED = 'auth.password_changed';

    /**
     * A refresh token was presented twice, so every session for that account
     * was revoked. Evidence of a copied token, and the one entry here that
     * nobody performed on purpose.
     */
    public const TOKEN_REPLAYED = 'auth.token_replayed';

    // ─── who can do what ───

    public const USER_CREATED = 'user.created';
    public const USER_UPDATED = 'user.updated';

    /** An administrator set somebody else's password. The account changed hands. */
    public const USER_PASSWORD_RESET = 'user.password_reset';

    /** A role's permissions changed. The action this table was finally built for. */
    public const ROLE_PERMISSIONS_CHANGED = 'role.permissions_changed';

    public const ORGANIZATION_CREATED = 'organization.created';
    public const ORGANIZATION_UPDATED = 'organization.updated';

    /**
     * The installation's own configuration changed.
     *
     * Names the keys that changed and never their values. One of them is an
     * SMTP password, and an audit trail that records the secret alongside who
     * set it is a second copy of the secret in a table that is never pruned.
     */
    public const SETTINGS_UPDATED = 'settings.updated';

    // ─── the instrument ───

    /** An assessment arrived as submitted rather than as a draft. */
    public const ASSESSMENT_SUBMITTED = 'assessment.submitted';

    /**
     * A report was emailed out of the system.
     *
     * The one action here that leaves the installation. Who sent it, which
     * visit, which of the documents, and the address it went to — because a
     * laboratory's score arriving in the wrong mailbox is a question somebody
     * asks months later, and "who sent this" is the whole of the answer.
     *
     * The address is metadata rather than a secret. It is a business contact,
     * it is already in the registry, and a trail that records a send without
     * recording where it went records nothing worth having.
     */
    public const REPORT_SENT = 'report.sent';

    /**
     * A report was not emailed, having been asked for.
     *
     * Kept for the same reason the successful one is. An administrator asking
     * why the laboratory never received their report needs to see the attempt
     * and the reason the mail server gave, and an audit trail holding only the
     * sends that worked cannot answer it.
     */
    public const REPORT_SEND_FAILED = 'report.send_failed';

    /**
     * Two facility records were folded into one.
     *
     * The only registry action here. Adding and correcting a record is
     * ordinary work and `api_logs` covers it; a merge moves every testing site
     * and every assessment from one facility onto another and cannot be undone
     * from the screen that did it.
     */
    public const FACILITY_MERGED = 'facility.merged';
}
