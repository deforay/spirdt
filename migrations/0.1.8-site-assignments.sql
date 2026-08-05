-- ─────────────────────────────────────────────
-- SITE ASSIGNMENTS: who covers which site, and when
-- ─────────────────────────────────────────────
--
-- Three independent axes, because the answer differs by programme and by
-- round, and a model that fixes any one of them has to be rebuilt:
--
--   organization_id  WHO is responsible.          Always set.
--   user_id          WHICH assessor, if named.    Optional.
--   campaign_id      WHICH round, if round-specific. Optional.
--
-- Every arrangement asked for falls out of the combinations:
--
--   B, —,      —        organisation B covers this site, permanently
--   B, —,      round 3  organisation B covers it this round
--   B, Joseph, —        Joseph covers it, permanently
--   B, Joseph, round 3  Joseph covers it this round only
--
-- organization_id is set even when a person is named. The organisation owns
-- the audit and the person may leave; naming an assessor NARROWS an
-- assignment rather than replacing it, so a departure leaves the site covered
-- rather than orphaned.
--
-- RESOLUTION: a row for the current round beats a standing row for the same
-- site. Assign the whole country once, then override the handful that move
-- each round — otherwise annual planning is a re-entry exercise. The rule
-- lives in AssignmentService, because "for the same site" is not something a
-- constraint can express.
--
-- TWO ORGANISATIONS ON ONE SITE IS LEGAL, and deliberately so: it is the
-- independent-audit case the programme layer exists for. It is also what lets
-- a coverage view later answer "who is auditing what, and who is auditing
-- nothing".
--
-- Scoped by programme rather than organisation, because testing_sites is —
-- an assignment names a site from the shared registry.

CREATE TABLE IF NOT EXISTS site_assignments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    programme_id        INT UNSIGNED NOT NULL,
    testing_site_id     BINARY(16) NOT NULL,
    organization_id     INT UNSIGNED NOT NULL,
    user_id             INT UNSIGNED NULL,
    campaign_id         INT UNSIGNED NULL,
    -- For a deadline that is not a whole round. A plan with no date is a plan
    -- nobody is accountable for, and inventing a campaign to express "by the
    -- end of Q3" is worse than a column.
    due_on              DATE NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,

    -- Generated, because MySQL treats nulls in a unique key as distinct: a
    -- plain UNIQUE over the four columns would happily accept the same
    -- standing assignment twice, since user_id and campaign_id are null in the
    -- commonest case of all. An administrator clicking Assign twice must be a
    -- no-op, not a duplicate.
    --
    -- VIRTUAL, not STORED, and the difference is not a preference. MySQL
    -- refuses a foreign key with ON DELETE SET NULL or CASCADE on a column
    -- that an indexed STORED generated column depends on — the table simply
    -- will not create, with nothing more helpful than "Cannot add foreign key
    -- constraint". Virtual columns carry no such restriction, index the same
    -- way for this purpose, and cost nothing to store.
    user_key            INT UNSIGNED AS (IFNULL(user_id, 0)) VIRTUAL,
    campaign_key        INT UNSIGNED AS (IFNULL(campaign_id, 0)) VIRTUAL,

    UNIQUE KEY uq_site_assignments (testing_site_id, organization_id, user_key, campaign_key),
    INDEX idx_site_assignments_programme (programme_id, campaign_id),
    INDEX idx_site_assignments_org (organization_id, campaign_id),
    INDEX idx_site_assignments_user (user_id, campaign_id),

    FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE,
    FOREIGN KEY (testing_site_id) REFERENCES testing_sites(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    -- The assignment survives the person: it falls back to the organisation,
    -- which is exactly what a null user_id already means.
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
