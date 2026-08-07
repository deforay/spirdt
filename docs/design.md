# Design

The app is used on a bench. An assessor stands, sometimes wears gloves, works
through 59 questions, and is usually offline. Every decision below follows from
that.

A static prototype of the section screen and the dashboard is circulated
separately. This page is the source of truth for the tokens and the rules.

## Stack

| Layer | Choice |
|---|---|
| Framework | Vue 3, Vite, TypeScript |
| Styling | Tailwind v4 |
| Primitives | Reka UI, headless |
| Components | About fifteen, hand-built |
| Local store | Dexie over IndexedDB |

Reka UI covers only what is hard to get right: dialog, popover, select and the
segmented control. Everything else is ours.

We do not use a full component library. Vuetify and PrimeVue each carry their
own design language, and bending one of them toward this one costs more than
building the fifteen components we need.

## Colour

One accent. It never means status. Green, amber and red mean a response and
nothing else, so the accent had to be the one hue that never signals anything.

| Token | Light | Dark |
|---|---|---|
| `ground` | `#F2F2F7` | `#000000` |
| `surface` | `#FFFFFF` | `#1C1C1E` |
| `label` | `#111114` | `#F5F5F7` |
| `label-2` | `#55555D` | `#9A9AA0` |
| `label-3` | `#6E6E76` | `#86868E` |
| `accent` | `#0A6ECB` | `#4C9DF5` |
| `yes` | `#1E7B34` | `#4FD16B` |
| `partial` | `#9A5B00` | `#E0A030` |
| `no` | `#B3261E` | `#FF6B60` |
| `na` | `#6B6B73` | `#98989F` |

Every one of these clears WCAG AA on both the surface and the ground, in both
themes. Measure a replacement before you make one — the first draft of this
palette put tertiary text at 2.51:1, which is the text an assessor reads most.

Both themes are built from the same tokens. Define the palette on `:root`,
redefine the tokens for dark, and style components through the tokens only.
Never write a colour inside a media query: the `prefers-color-scheme` block and
the manual override then drift apart, and dark-on-boot stops matching
dark-on-toggle.

The app follows the system by default and offers Auto, Light and Dark in
settings.

## Type

The system font stack. On Apple hardware that is SF, which is the look we want,
and it downloads nothing. Bench connections are the reason.

Weights 400, 600 and 700. Nothing else. Use `tabular-nums` wherever digits line
up — scores, percentages, question codes, table columns.

Question codes are set in the monospace stack. They are join keys that appear
in exports, reports and findings, so they should read as identifiers.

## Layout

Two radii, and which one to use follows from what the thing is.

| | Radius | What |
|---|---|---|
| Control | 11px | Segmented controls, inputs, badges, buttons — anything a thumb lands on |
| Surface | 20px | Cards, sheets, panels — anything that *contains* controls |

A control and the panel around it sharing one radius makes the control read as a slot cut into the panel rather than a thing resting on it. On a phone the surface radius is held back to 11px, because at 430px wide the panel is the screen and rounding it says nothing.

Hairline separators. Shadows you have to look for — and on the dark ground, less than that: depth there comes from the surface being lighter than what is behind it, not from a shadow nobody can see.

Scrollbars are thin, set in CSS. No scrollbar plugin: they break momentum
scrolling on iOS and hide the scroll area from assistive technology. Thin, but
never hidden — a scrollbar that disappears removes the only cue that there is
more below.

Bento tiles are welcome on the dashboard when tile size means importance. A
grid of four identical cards means nothing.

## Wider than a phone

The app is designed for a phone in one hand and then allowed to grow. Assessors
also use tablets and laptops, and a 430px column stranded in the middle of a
1440px screen reads as an unfinished port rather than a considered choice.

| Width | What changes |
|---|---|
| ≥640px | Surfaces take the 20px radius and a shadow. The site list widens to 620px |
| ≥768px | The section pill row becomes a rail down the left with the section **titles** restored. Setup splits into two columns |
| ≥900px | Review splits: score and unanswered on the left, sticky; gaps and signatures on the right |

Three rules hold across all of it.

**Touch targets never shrink.** A mouse tolerates a large target; a finger does not tolerate a small one, and the same build serves both. Width buys more information, never denser controls.

**One navigation in the accessibility tree at a time.** The pill row and the rail are the same list. Both being present is two things to tab through and two places to announce the current section, so the one that is not shown is not rendered.

**Reading order does not change with width.** The review screen splits along a seam that already existed in its order, so the phone reads exactly as it did. A layout that reorders content when it widens is a layout that reads differently to a screen reader than to an eye.

## Icons

[Phosphor](https://phosphoricons.com), as Vue components rather than the icon font. Only the icons actually imported reach the bundle, which is the whole argument on a bench connection — the font ships every glyph and three weights to draw four marks.

Icons accompany words, they do not replace them. The response control is the case that matters: Yes, Partial, No and Not applicable differ by colour, and colour alone is not a distinction — roughly one man in twelve cannot rely on the green/amber difference carrying most of the meaning. A check, a dash, a cross and a circle-slash carry it for everyone.

Decorative icons are `aria-hidden`. "Check Yes" is worse than "Yes".

## The answering screen

A grouped list, in the style of iOS Settings. The whole section is on one
scrollable screen. Each question is a row with an inline segmented control.

This beats one question per screen because an assessor has to check a section
before submitting, and 59 separate screens make that expensive.

Two rules the screen enforces, both from the template rather than from code:

- **Not applicable appears only where `na_allowed` is set.** Five questions in
  the instrument allow it. The other 54 do not offer the option at all.
- **Partial, No and Not applicable open a note field**, and the row says a note
  is required until one is written. The rule is shown where the decision is
  made, not at submit.

The running score updates on every tap. It uses the same rules as the server:
Not applicable leaves both the numerator and the denominator, and the
percentage is rounded before it is banded. See [Scoring](scoring.md).

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
- No corner larger than 20px, and never above 11px on a control.
- No colour that means nothing.
- No colour carrying a meaning on its own, without a word or a shape beside it.
- No animation that does not explain a change of state.
- No downloaded typeface. The system stack is the look we want and it costs nothing on a bench connection.
