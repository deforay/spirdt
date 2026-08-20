# How to conduct an audit

An SPI-RDT audit is one assessor, one testing site, one visit. This is what to
do on the device, from the sign-in before you leave to the moment the visit
reaches the server.

The checklist itself — what each of the 59 questions is asking and what counts
as a Yes — is the instrument's own User's Guide, not this page. What follows is
the software.

## Before you leave

Sign in while you still have a connection. Signing in is the one thing that
needs one. After that the whole visit works offline, including a browser
refresh, a flat battery and a day with no signal.

1. Open the app and sign in with your email address and password.
2. If the app asks for an **Org code**, your address exists in more than one
   organisation. Enter the code you were given.
3. If the app asks you to choose a password, somebody else set the one you used.
   Replace it. This signs you out on every other device.
4. Wait for the testing site list to appear at least once. It is cached from
   there, and that cache is what you work from in the field.

On a phone or tablet, add the app to your home screen. Browsers reclaim storage
from ordinary tabs and leave installed apps alone, and the app says so on screen
if it is running somewhere its work might not survive. Private browsing saves
nothing at all — the app refuses to let you start rather than losing an hour.

## Start the visit

The site list opens on the sites assigned to you. Everything else is one tap
away under **Show all sites**, because arriving somewhere unplanned should not
be a wasted visit.

To resume a visit you have already begun, take it from **Unfinished audits** at
the top of the list. Every answer is written to the device as you give it, so
what you resume is what you left.

To start a new one, search for the testing site and choose it.

## Set up the audit

Three things are asked before the checklist, and two of them change what the
checklist is.

| Field | What it does |
|---|---|
| **Audit round** | Labels this visit — a number, or a word like Baseline. Leave it blank if the programme does not run rounds. |
| **Tests performed here** | Names every pathogen tested at this site. Section 4 repeats once for each. |
| **About the site** | Part A. One of its answers decides whether Section 5 applies at all. |

Name every rapid test performed at the site, not every product. A three-test HIV
algorithm is one pathogen naming all three tests and their manufacturers.

You can come back to this screen from **Site details** on the checklist, but
changing the pathogens or a Part A answer after you have started changes what
counts as a complete visit. Get both right before you begin.

## Work the checklist

One section at a time, with the section rail on the left on anything wider than
a phone. Every answer saves to the device as you tap it. The footer says
**Saved** and the time.

Each question takes one of four responses.

| Response | Points | Note |
|---|---|---|
| **Yes** | 2 | Optional |
| **Partial** | 1 | Required |
| **No** | 0 | Required |
| **N/A** | Excluded from the score | Required, and only offered where the instrument allows it |

To see what the instrument expects for a question, open **What to look for**.
To record something that is not a gap, use **Comments**.

The running score at the top is marked **Provisional** for as long as questions
are unanswered, because unanswered questions score zero and the figure rises as
you fill them in. See [Scoring](scoring.md) for how points become a level.

## Record the gaps

**Review** collects every Partial, No and N/A into a list of gaps, and it is the
part of the visit the site actually acts on. Work through it with the site in
the room, before you leave.

For each gap, record:

- what is missing or is not being done
- the recommended action
- **Who acts on this** — site, facility, district, regional or national
- **How urgent** — immediate, or follow-up
- the responsible person, where there is one

One question can carry several gaps. Use **Add another gap** rather than
crowding two problems into one description.

Responsibility is asked for because many gaps are not the site's to fix, and one
filed against a site that cannot act on it never closes. A gap routed to
district level is a gap somebody at district level is asked about.

## Sign

Signatures sit at the end of the review, after the gaps have been read out.
Three marks are collected and two of the names are already known.

| Signer | Name comes from |
|---|---|
| Assessor | Whoever is signed in |
| Second assessor | Typed, because nothing in the system knows a colleague attended |
| Site representative | The interviewee recorded in Part A |

No signature blocks submission. Signatures upload on their own channel, so a
dead canvas or a bad connection cannot strand a finished visit on the tablet.

## Submit

**Submit assessment** is refused until two things are true.

1. Every expected question has an answer.
2. Every Partial, No and N/A has a note.

The review screen counts both back to you, and the server checks the same two
when the visit arrives. A visit that passes on the device and fails on the
server has not been lost — it stays on the device and the reason appears on
screen.

## Get it to the server

Submitting files the visit on the device. Sync sends it.

Sync runs by itself in the background, retries on a widening interval while
there is no connection, and tries immediately the moment one appears. The badge
in the header says where the work has got to.

| Badge | Meaning |
|---|---|
| **Synced** | Everything on this device has reached the server |
| **{n} waiting** | Work is queued. Tap to try now |
| **Syncing** | In progress |
| **Not synced** | This device has not reached the server yet |
| **Needs attention** | The server refused something. This does not clear on its own — tap it |

Nothing in any of those states deletes anything. The device holds the record
until the server acknowledges it.

Signatures and photographs upload after the visit itself lands, so a signature
can still say **Waiting to upload** on a visit that has already synced.

## Confirm the visit landed

The badge reads **Synced**, and the visit is gone from **Unfinished audits**.

On the management side the audit appears under **Reports**, newest first, with
its score and certification level. If it is there, it is on the server.

## On a shared tablet

Sign out when you hand the device on. The next person to pick it up is signed in
as you otherwise, and every visit they record is filed against your name.
