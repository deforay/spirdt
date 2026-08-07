-- ─────────────────────────────────────────────
-- WHERE THE VISIT HAPPENED
-- ─────────────────────────────────────────────
--
-- The predecessor collected a geopoint on every submission — "Collect the GPS
-- coordinates of this site" — and its dashboard plotted one marker per
-- assessment, coloured by the score band. That map is the certification
-- picture of a country at a glance, and it is the single most useful thing the
-- old tool produced that this one cannot yet.
--
-- On the ASSESSMENT rather than only on the facility, and the distinction is
-- worth stating because facilities already carry coordinates.
--
--   A facility's pin says where a record claims the facility is. It is
--   entered by an administrator who may never have been there, and a facility
--   holds several testing sites, so two labs in one hospital collapse to one
--   marker.
--
--   An assessment's pin says where the assessor was standing on the day. That
--   is evidence of the visit, and it is the only one of the two that can be
--   checked against a dispute about whether a visit happened.
--
-- The facility remains the fallback. A reading is often impossible — indoors,
-- in a basement, on a laptop with no GPS, or where permission is refused — and
-- a map with holes in it is worse than one that falls back to the registry.
-- What the fallback must never do is pretend: `location_source` records which
-- of the two a row's coordinates came from, so a report can say so and an
-- analysis can exclude the inherited ones.
--
-- NEVER REQUIRED. The predecessor's geopoint carried no required bind, and
-- that was right: a visit refused for want of a satellite fix is a visit that
-- does not happen. Everything here is nullable and nothing gates on it.
--
-- accuracy_m is kept because a coordinate without it is not interpretable. A
-- fix good to five metres and one good to two kilometres plot identically and
-- mean entirely different things, and only one of them says which building.

ALTER TABLE assessments
    ADD COLUMN latitude        DECIMAL(10,7) NULL AFTER device_id,
    ADD COLUMN longitude       DECIMAL(10,7) NULL AFTER latitude,
    -- Metres, as the browser reports it. Nullable even when coordinates are
    -- present: a fallback from the registry has no accuracy to report.
    ADD COLUMN accuracy_m      SMALLINT UNSIGNED NULL AFTER longitude,
    ADD COLUMN location_source ENUM('device', 'facility') NULL AFTER accuracy_m,
    -- When the fix was taken on the device, which is not when it was synced.
    ADD COLUMN located_at      DATETIME NULL AFTER location_source;

-- What the map query asks: every scored visit in a place, with a position.
-- Ordered so the organisation narrows first, since that is the scope every
-- read is already inside.
CREATE INDEX idx_assessments_located ON assessments (organization_id, latitude, longitude);
