# Signatures

At the end of the review, once the gaps have been read out, the assessor and
the site representative sign on the screen with a finger. The mark is a PNG.

Signing is deliberately **not** required to submit. Media uploads on its own
channel and can fail on its own, so requiring a signature here would let a dead
canvas or a full disk strand a finished assessment on a tablet.

## What is captured

| Role | Name printed beside the mark | Where the name comes from |
|---|---|---|
| `assessor_1` | The assessor | Whoever is signed in |
| `site_representative` | The person debriefed | Part A's `interviewee_name` |

Neither name is typed twice. `assessor_2` exists in the server's vocabulary for
a two-person team; nothing offers it yet.

A signature can be **redrawn** but not removed. The new mark replaces the old
one on the device and on the server, file and row. There is no un-sign: on an
audit instrument, "this was signed and then unsigned" is a question worth
having to answer deliberately rather than by tapping Clear.

## Why a separate channel

An image is orders of magnitude larger than the assessment payload it belongs
to, and on the connections this tool exists for, the large thing is the one
that fails. Keeping them apart means:

- a signature that will not upload leaves a **synced assessment** behind it,
  rather than holding a whole site visit hostage to a few kilobytes of PNG;
- an assessment that will not sync does not **lose** the signature.

Uploads run after every assessment has been attempted, never before — an
attachment names an assessment the server must already have, so going first
would earn a 404 on every retry.

Images count towards the pending total on the sync badge. Reporting "Synced"
while a signature is still only on the tablet is exactly the false reassurance
that badge exists to avoid.

## The upload endpoint

```
POST /api/sync/attachments      multipart/form-data
     assessment_id, kind, role, question_code (photos only), file

GET  /api/attachments/{id}      the bytes, organisation-scoped
```

Nothing the caller says about the file is believed:

- **The type is sniffed from the bytes**, then confirmed by decoding the image.
  A PHP script named `.png` and declared `image/png` fails both.
- **The name on disk is minted by the server.** A client-supplied filename is
  how a path traversal lands, and nothing about the original name is
  information.
- **The checksum is computed from what arrived.** A checksum the caller can
  choose cannot detect anything — and it is the key idempotency turns on.
- **The size is checked against the bytes read**, not the size the upload
  declares. Limit is 5 MB.
- **The assessment must be the caller's.** Refused as 404 rather than 403, so
  the caller learns nothing about whether the id exists elsewhere.

Files live under `var/uploads/attachments/{org}/{assessment}/{uuid}.png`,
outside the document root, mode `0640`. They are served by the application
rather than the web server, because the organisation scope is the only thing
keeping one tenant's signatures away from another's.

Status codes mean what they do on the sync endpoint, because the device decides
from them whether to retry: **200** stored or already stored, **404** not this
organisation's, **422** the file or its metadata is wrong, **5xx** ours.

A refusal that would repeat is recorded on the row and not retried — each retry
is a real upload on a connection somebody is paying for — and shown against
that signature on the review screen, where the assessor can act on it by
drawing again.

## On the device

Strokes are kept as points, not only painted. That buys undo (a slipped finger
on a hand-held tablet is the normal case, and Clear-and-start-again is a
punishment for it), a redraw at the right resolution when the device is
rotated, and an emptiness test that does not involve reading pixels back.

The bitmap is transparent with dark ink; the white behind it is CSS on the
element. So the mark composites onto a report page without carrying a white
rectangle with it, and a dark-mode viewer still gets a white surface to sign
on. The canvas is sized to `devicePixelRatio` — without it the mark is visibly
soft on every phone made in the last decade, and a signature that looks like a
fax is not what anyone wants against their name.

## Not done yet

- **Photographs.** The `photo` kind, `question_code` and the 5 MB limit are all
  in place server-side; nothing captures one.
- **Signatures in the report.** No report exists yet.
