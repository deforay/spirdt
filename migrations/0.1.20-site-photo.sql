-- ─────────────────────────────────────────────
-- WHAT THE TESTING SITE LOOKS LIKE
-- ─────────────────────────────────────────────
--
-- A testing site is a bench, a room or a counter, and its name is whatever the
-- people who work there call it. "Lab 2", "ART corner", "the back room". An
-- assessor arriving at a hospital with four benches and a registry row reading
-- "Lab 2" is relying on somebody at reception knowing which one that is, and
-- the row beside it — location_description — is one line of free text written
-- by somebody who may have been describing a different building.
--
-- A photograph settles it. It is the one piece of a site record that cannot be
-- typed wrong, and it is what makes "is this the bench that was audited last
-- round?" answerable rather than argued.
--
-- ON THE SITE, NOT IN attachments. That table is organisation-scoped audit
-- data — signatures and evidence photographs belonging to one visit by one
-- organisation, and correctly invisible to everybody else. This is registry
-- data: the national list two organisations auditing in the same country have
-- to agree on, shared across the programme like the site's name and its
-- facility. Filing it in attachments would either leak audit data across the
-- programme or hide the site's own photograph from half the people looking at
-- the site. See BelongsToProgramme, which says the same thing at greater
-- length.
--
-- ONE PHOTOGRAPH, REPLACED RATHER THAN ACCUMULATED. The question it answers is
-- "which bench is this", and that has one current answer. A gallery would
-- invite a second question — which of these is it now — that nothing here can
-- answer, and the bytes of the superseded image are deleted with the row so
-- the disk does not grow on every correction.
--
-- Columns mirror what attachments records about a stored image, because the
-- checks on the way in are the same ones and the same facts come out of them:
-- the type is sniffed from the bytes rather than believed, the name on disk is
-- minted by the server, and the checksum is computed from what arrived so a
-- re-upload of the identical image is a no-op rather than a second file.

ALTER TABLE testing_sites
    -- Relative to var/uploads, like an attachment's. Never a client-supplied
    -- name: that is how a traversal lands, and nothing about the original name
    -- is information.
    ADD COLUMN photo_path        VARCHAR(500) NULL AFTER location_description,
    -- Sniffed from the bytes and confirmed by decoding them. PNG or JPEG.
    ADD COLUMN photo_mime        VARCHAR(100) NULL AFTER photo_path,
    -- Of what arrived, so sending the same image twice costs one lookup.
    ADD COLUMN photo_checksum    CHAR(64) NULL AFTER photo_mime,
    ADD COLUMN photo_byte_size   INT UNSIGNED NULL AFTER photo_checksum,
    -- When it was stored, which is not when the shutter closed. EXIF says the
    -- latter and EXIF is written by the client, so it is not believed here.
    ADD COLUMN photo_taken_at    DATETIME NULL AFTER photo_byte_size,
    -- Provenance rather than display: who added it, for whoever later has to
    -- ask why a bench in this district is photographed and its neighbours are
    -- not. No foreign key, for the reason organization_id on this table has
    -- none any more — the row is shared across the programme and the user is
    -- not, so a cascade from one organisation's account would delete a fact
    -- belonging to all of them.
    ADD COLUMN photo_by_user_id  INT UNSIGNED NULL AFTER photo_taken_at;
