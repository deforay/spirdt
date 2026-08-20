# Design

The app is used on a bench. An assessor stands, sometimes wears gloves, works
through 59 questions, and is usually offline. It is also read at a desk by a
programme office deciding where to spend a budget. Every decision below follows
from one of those two, and where they disagree the bench wins.

## Stack

| Layer | Choice |
|---|---|
| Framework | Vue 3, Vite, TypeScript |
| Styling | Tailwind v4 |
| Primitives | Reka UI, headless |
| Components | About twenty, hand-built |
| Local store | Dexie over IndexedDB |

Reka UI covers only what is hard to get right: dialog, popover, select and the
segmented control. Everything else is ours.

We do not use a full component library. Vuetify and PrimeVue each carry their
own design language, and bending one of them toward this one costs more than
building the twenty components we need.

The palette, the shadow scale and the face are derived from
[TailAdmin](https://github.com/TailAdmin/tailadmin-free-tailwind-dashboard-template)
(MIT, © 2023 TailAdmin), translated into the tokens below. No markup was taken.

## Colour

Colours are declared in `web/src/styles/app.css` and nowhere else. A colour
written inside a component, or inside a media query, is how the two themes
drift apart — and when they do, dark-on-boot stops matching dark-on-toggle,
which is the kind of bug nobody reports because it only shows up on someone
else's machine.

Three families live there and they never trade places.

**Response** — green, amber and red mean Yes, Partial and No on a question, and
nothing else anywhere in the application.

| Token | Light | Dark |
|---|---|---|
| `yes` | `#1B7A32` | `#4FD16B` |
| `partial` | `#9A5B00` | `#E0A030` |
| `no` | `#B3261E` | `#FF6B60` |
| `na` | `#6B6B73` | `#98989F` |

This is the rule the rest of the palette exists to protect, and it is broken by
accident rather than on purpose. Sync state borrowed green for synced and amber
for waiting; certification levels ran a red-to-green ramp; the dashboard
painted its donuts in all three; a facility added in the field was tagged in
amber. Each looked reasonable alone. Together they taught a reader that red
means bad, which is a different claim from *somebody answered No*, on screens
one click apart.

Red is still the colour of a genuine failure — a validation error, a blocked
sync — because that is what a failure has always looked like and nothing else
reads as one. It is never the colour of a state that is merely lower, later or
optional.

**Level** — certification levels 0 to 4 are drawn on a sequential navy ramp,
`level-0` through `level-4`, each with its own ink. A level is a position on a
scale, so it is drawn as one: the same hue, deepening. Red to green is the
encoding for opposition, which is not what Level 1 and Level 3 are to each
other.

If a client expects red for Level 0, that is a conversation to have before it
is drawn. The level is always written out beside the colour, so the colour
never carries the meaning alone.

**Accent, chrome and brass** — `accent` is the brand blue and marks anything
interactive. `chrome` is the near-black behind the sign-in panel and the score
tile. `brass` marks a thing of record: the rule under a report title, a draft
badge, a provenance note. None of the three can be mistaken for a response.

| Token | Light | Dark |
|---|---|---|
| `ground` | `#F9FAFB` | `#101828` |
| `surface` | `#FFFFFF` | `#1D2939` |
| `surface-2` | `#F9FAFB` | `#182130` |
| `hairline` | `#E4E7EC` | `#344054` |
| `label` | `#1D2939` | `#F2F4F7` |
| `label-2` | `#667085` | `#98A2B3` |
| `label-3` | `#98A2B3` | `#7A879C` |
| `accent` | `#3641F5` | `#7592FF` |
| `brass` | `#966B1E` | `#E0AC55` |

Every one of these clears WCAG AA on both the surface and the ground, in both
themes. Measure a replacement before you make one — an early draft of this
palette put tertiary text at 2.51:1, which is the text an assessor reads most.

Inks are tokens too, because they flip. `accent-ink` is white in light and
near-black in dark, since the accent itself inverts; `on-brass` is dark in
both, because brass is a mid tone in both. Writing `text-white` on an accent
button is correct in one theme and unreadable in the other.

## Theme

Light, dark, or the device's own setting. The choice belongs to the device
rather than the account — a shared tablet on a bench in daylight wants light,
the same person's laptop at home may not — so it lives in `localStorage` and
never goes near the server.

`auto` removes the `data-theme` attribute rather than writing a resolved value,
which hands the question back to `prefers-color-scheme`. An inline script in
`index.html` stamps the stored choice before the first paint; a deferred module
runs after the first frame, and the flash of the wrong palette is exactly what
somebody who chose dark did not ask for. The key is shared with
`composables/useTheme.ts` — change both or neither.

Two surfaces are white in both themes and deliberately so: the signature pad
and the signature thumbnail. What is drawn there is stored as an image and
printed on a report, and a signature captured on a dark ground arrives as pale
ink on a sheet nobody can read.

## Type

[Outfit](https://fonts.google.com/specimen/Outfit) for everything, JetBrains
Mono for question codes and identifiers. Both are variable fonts, self-hosted
via `@fontsource`, and shipped with the bundle.

The rule this replaces said no downloaded typeface, and the reason behind it
still stands: nothing may block first paint on a bench connection. Self-hosting
keeps that — the files are cached with the rest of the build, so an assessor
who has opened the app once has them offline forever, and there is still no
third-party request. What the system stack cost was the thing the client could
not sell: every screen looked like an unstyled form, because SF and Segoe are
what unstyled forms look like.

Weights 400, 500, 600, 700 and 800. Use `tabular-nums` wherever digits line up
— scores, percentages, question codes, table columns.

Question codes are set in the monospace stack. They are join keys that appear
in exports, reports and findings, so they should read as identifiers.

## Density

Body text is 14–16px, table cells are padded 16px by 20px, cards are padded
20–24px, and content runs to 1536px inside the rail.

This is written down because the previous scale was a step smaller at every
level, and the effect was not "compact" but "unfinished" — a layout drawn for a
430px phone column and never revisited when the app grew a desktop. Nothing
about it was individually wrong, which is why it survived so long.

## Layout

Two radii, and which one to use follows from what the thing is.

| | Radius | What |
|---|---|---|
| Control | 10px | Segmented controls, inputs, badges, buttons — anything a thumb lands on |
| Surface | 16px | Cards, sheets, panels — anything that *contains* controls |

A control and the panel around it sharing one radius makes the control read as
a slot cut into the panel rather than a thing resting on it.

Hairline borders carry separation; shadows are almost nothing. On the dark
ground depth comes from the surface being lighter than what is behind it, not
from a shadow nobody can see.

Scrollbars are thin, set in CSS. No scrollbar plugin: they break momentum
scrolling on iOS and hide the scroll area from assistive technology. Thin, but
never hidden — a scrollbar that disappears removes the only cue that there is
more below.

## Chrome

The management side is a console: a 290px rail down the left listing every
screen the account may open, and a sticky header carrying the account. Below
1024px the rail becomes a drawer behind one button.

It was a top bar with dropdowns, and it had already outgrown itself once —
"Testing sites" broke mid-word, and the fix then was to hide seven screens
behind menus. That works and it costs the thing a console is for: nobody could
see what the application held without opening three menus to find out.

The assessor side wears the same header — mark on the left, account on the
right — because the two halves are one product and somebody moving between them
should not feel the join.

The navigation lists only what the account can DO. Each entry names the
permission its route names, so a link never leads somewhere that refuses, and a
group whose screens are all closed disappears rather than opening onto an empty
menu. Recording a visit is one of those entries: an administrator has always
held `assessments.submit`, and until it was listed the only way to reach the
assessor app was to type the address.

## Lists

`.data-card`, `.data-table`, `.chip` and `.field` are defined once in
`app.css`. Nine screens draw a list and each used to style its own cells; they
drifted into three row heights, two header treatments and a search box that was
round on one screen and square on the next.

A table has a sticky grey header band, hairline row rules, a hover state, and
its own horizontal scroller so a wide table never takes the page sideways.
Pagination shows what is on screen out of what exists, then numbered pages with
a window around the current one — the numbers let somebody return to the page
they were on before they opened a record, which is the actual journey through a
registry.

Status is a column, not an opacity. A disabled row used to be drawn at 50%
opacity, which mostly said "unreadable".

## Forms

Label above, help text under the label, control below. Inputs carry the
`.field` treatment — a border, a background and a focus colour — because a
transparent box on a white card is not visibly somewhere to type.

A single choice is a list of option cards, each with a check mark, and the
chosen one is filled and outlined. It used to be a row list with the word
"Selected" in the right margin: the only state in the application announced in
prose, and unfindable at a glance on a list of six.

## The answering screen

The screen that carries the product, and the one place where the phone and the
desktop are designed separately rather than one being a narrowing of the other.

**On a phone** the cards run edge to edge, the response control is 56px tall,
and a bar pinned to the bottom — clear of the home indicator — carries progress
and the way out. That is the screen worked standing up, one-handed, sometimes
gloved. The controls run the full width of the card: the 46px that lines them
up under the first word of the question is a tenth of a phone screen, and the
switch is what wants it. Only the guidance link keeps the indent, because it
belongs to the sentence above it.

**On a desktop** there are three panes: a rail with per-section progress and
the visit's score at its foot, the questions, and "What to look for" standing
open in a column of its own, clamped to five lines so a section still scans as
a list of questions rather than a wall of prose.

Guidance is shown, not linked to. Every one of the 59 questions carries a
paragraph in the template saying what evidence to look for, and it used to sit
behind a button that emitted an event nobody handled — the assessor tapped it
and nothing happened at all. The judgement it supports is the one the whole
record rests on: whether what is in front of you is a Yes or a Partial.

The response control is the one component drawn twice, because a phone and a
desk are not the same instrument.

**At a desk** it is three separate buttons with air between them, tinted and
outlined in the response colour when chosen. **On a phone** it is a switch: one
track, and the answer is a chip raised out of it, ringed in the response
colour. The choice is a thumb travelling in a groove — the shape a phone has
used for a set of exclusive options for a decade — and the lifted chip says
which one is true from further away than a tint does. A row of grey tracks is
furniture on a desk screen showing a dozen questions at once, which is why the
desk does not get it.

Both carry the colour in the same three places: the outline, the mark and the
word. Tint alone is invisible on a bench in daylight; the outline survives
that, a projector and a photocopy. Solid fill was tried and was too loud at 59
rows — a finished section became a wall of colour with the questions lost
inside it.

The section jumper is a row of numbers on a phone, each under a fill bar. A
number alone says which section this is, which the title above it already says;
the bar says which sections are still owed, which is what somebody scanning
that row is asking. Moving on in order is not its job — that is a full-width
button at the end of the section, where the assessor finishes.

The note field appears when there is something in it, when the response obliges
one, or when the assessor asks for it. An empty text box under all 59 questions
is 59 invitations to write nothing, and it doubled the height of a section.

Two rules the screen enforces, both from the template rather than from code:

- **Not applicable appears only where `na_allowed` is set.** Five questions in
  the instrument allow it. The other 54 do not offer the option at all.
- **A note may be written against any response and none of them obliges one.**
  What a gap obliges is a corrective action, and that is asked for at the end of
  the section, where it can be given an owner and a date.

The running score updates on every tap and uses the same rules as the server.
See [Scoring](../docs/scoring.md).

## Icons

[Phosphor](https://phosphoricons.com), as Vue components rather than the icon
font. Only the icons actually imported reach the bundle, which is the whole
argument on a bench connection — the font ships every glyph and three weights
to draw four marks.

Icons accompany words, they do not replace them. The response control is the
case that matters: Yes, Partial, No and Not applicable differ by colour, and
colour alone is not a distinction — roughly one man in twelve cannot rely on
the green/amber difference carrying most of the meaning. A check, a half-filled
circle, a cross and a circle-slash carry it for everyone.

Decorative icons are `aria-hidden`. "Check Yes" is worse than "Yes".

## Copy

Simple sentences. Direct voice. One noun per thing, every time.

| Use | Not |
|---|---|
| assessment | audit, survey, visit |
| site | location, facility (a facility is a different record) |
| assessor | auditor, inspector, user |
| question | item, check |
| finding | issue, gap, problem |
| level | grade, rating, band |
| score | points, marks |

**Level** is the band from 0 to 4. **Score** is points. They are never swapped.

A button says what happens. Then a message says it happened. Publish, then
"Published".

An error says what went wrong and what to do about it. No apology, no vague
wording. `preflight` is the model: it names the target it is pointed at, then
prints the fix.

## What we do not do

- No gradients, frosted glass or glow.
- No emoji standing in for icons.
- No corner larger than 16px, and never above 10px on a control.
- No colour that means nothing.
- No colour carrying a meaning on its own, without a word or a shape beside it.
- No green, amber or red for anything that is not a response.
- No colour written inside a component, and never inside a media query.
- No animation that does not explain a change of state.
- No typeface fetched at runtime. Ours ship with the bundle.
