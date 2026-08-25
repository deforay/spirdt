# How to administer the platform

The management console is where the registry is built, accounts are handed out
and audits are read back. It is online only.

Everything here assumes you already have an administrator account. The first one
on a new installation is created from the command line by
[`bin/provision-org`](cli.md#bin-provision-org), not from this screen.

## Build the registry first

An assessor cannot start a visit against a testing site that does not exist, and
the registry is three tiers deep. Build it in this order.

1. **Geography** — the country's own hierarchy. Provinces, districts, or
   whatever this country calls its tiers. Add a top-level place first, then add
   the tier below it under that one.
2. **Facilities** — hospitals, clinics and health centres. Each one sits in a
   place and carries a facility code, its type, level and affiliation, an
   address and a contact.
3. **Testing sites** — the bench, room or counter where testing actually
   happens. Each one belongs to a facility. Name it what people at the facility
   call it: "TB clinic", "ART corner".

An audit is made against a testing site, not against a facility. A hospital
running rapid tests at three benches is three testing sites.

Every list is search-first and paginated, because a national registry runs to
thousands of facilities. Search places by any part of their path — "copper kit"
finds Kitwe in Copperbelt.

To record where a facility is on the map, fill in its coordinates in decimal
degrees. The dashboard map draws audits that carry a position, and falls back to
the facility's when the audit has none.

### When the field creates a duplicate

An assessor who arrives at a facility that is not in the registry can add it,
and those rows are marked **added in the field**. Where two rows are the same
facility, open the one to discard and choose **Merge into another**. Its testing
sites move across and nothing is deleted, so audits already made against it keep
resolving.

## Decide who covers which site

**Assignments** is the standing plan for the coming visits. Choose a place, then
assign its testing sites.

- To leave a site open to anyone in the organisation, assign it to **Anyone in
  the organisation**. This is the common case.
- To narrow it to one person, name an assessor or an administrator. The site
  then leaves everybody else's default list.

Assignment is a default, not a lock. An assessor who arrives somewhere unplanned
can still work — every site in the programme is one tap away behind **Show all
sites** on the device.

To move several sites at once, select them on the page and use **Assign all
selected**.

## Give people accounts

**People** lists who can sign in and as what. Use **Add someone** to create an
account.

| Role | What it is for |
|---|---|
| Administrator | Day-to-day administration of the organisation |
| Assessor | Conducting audits |
| Viewer | Reading dashboards and reports |
| Site staff | Viewing and closing the findings raised against their own facility |

The new account's password is shown **once**, on the screen that creates it.
Hand it over in person rather than by email. The account is required to replace
it at first sign-in.

To give somebody a new password, use **Reset password** on their row. To stop an
account signing in without deleting the record it is attached to, use
**Deactivate**.

You cannot change your own role, and you cannot edit an account that outranks
yours.

## Change what a role may do

**Roles** is a matrix — roles across the top, capabilities down the side — so
"who can change the registry?" is one row rather than five pages.

Three rules bound what you can edit, and the screen greys out what it would
refuse rather than accepting a click and failing a second later.

1. You cannot grant a capability you do not hold yourself.
2. You cannot edit a role that outranks your own.
3. You cannot take **Manage roles** off your own role. Every other change is
   reversible by whoever made it. That one is not.

A withdrawn capability applies on the account's next request, not when their
session expires. A role holding nothing reaches nothing — there is no fallback
to the defaults.

Custom roles do not exist. An organisation reshapes the five it has. The
reasoning is in [Architecture](architecture.md#permissions).

## Read what came back

**Reports** lists every audit, newest first. Filter by status, certification
level, date range, or search for a site or a facility.

Open one for the full record of a visit. It is written to be handed to the
laboratory it describes, and it reads top to bottom in the order a laboratory
manager needs: the overall score and level, then the section profile, then the
corrective action plan, then every question and its answer. Every question in
the instrument appears whether or not it was answered, because a report that
quietly omits what was skipped is the one way it could mislead.

Use **Print** for the paper copy. Colour is never the only carrier on this page,
so a photocopy still reads.

**Download PDF** writes the same visit out as a file, from the list as well as
from the report itself. It carries everything the screen shows and Part A
besides — the facility's type, level and staffing, as the assessor recorded
them.

Choose **With photographs** or **Without photographs** when you download. Five
pictures a section at a phone camera's resolution makes a file too large to
email, so a report meant for a mailbox is better sent without them. Signatures
are in both.

Anything the file leaves out, it says: a picture dropped to keep the download a
size that can be sent, or a mark this server has no image library to draw. Both
are still in the system and still on the report screen.

A draft is a visit somebody is part-way through. It is marked as one and its
figures are not final.

## Watch the programme

**Dashboard** is where a management account lands. It answers how many
laboratories have been audited, how they sit across the certification levels,
whether that is moving, and which section of the standard is dragging the scores
down.

Submitted audits only, everywhere. A draft would report a laboratory at 12%
because eleven of fifty-nine questions have been answered.

Narrow it by period and by place. Every panel offers **View data** for the
figures behind it and **Save image** for the chart itself.

The map draws audits that carry a position. It stays empty until assessors file
audits with coordinates, or until facility coordinates are filled in.

## Check who did what

**Activity** is the audit trail: sign-ins and sign-outs, password changes and
resets, account and organisation changes, role changes, facility merges and
submitted audits. Filter by action, by person or by date.

Reading is deliberately not recorded here, and neither are ordinary registry
edits. A facility merge is the exception, because it moves every testing site
and audit off one facility and cannot be undone from the screen that did it.

Dates are bucketed in the organisation's timezone, which is set under Settings.

## Set up the installation

**Settings** holds three blocks, and they do not all belong to the same thing.

| Block | Whose it is |
|---|---|
| This installation | The whole installation. Its name replaces SPI-RDT in the header, and its contact is offered to anybody who signs in with no access yet |
| Localisation | One organisation. Timezone, starting language and country code |
| Email delivery | The whole installation. Kept for the messages the platform does not send yet |

The mail password is write-only. The server never sends it back, so the box is
always empty and leaving it empty keeps the stored password. Use **Remove the
stored password** to clear it.

Storing a mail password needs an `APP_KEY` on the server. Without one the screen
says so and everything else still saves.

## Add another organisation

On an installation serving several organisations, **Country** lists the
organisations auditing there and **Add an organisation** creates one.

An organisation is created together with its first administrator, so it is never
left with nobody able to administer it. That administrator's password is shown
once.

Organisations share the registry and keep their audits to themselves. Today an
organisation cannot see another organisation's score on a site they both audit.
