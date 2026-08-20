# SPI-RDT Assessment Platform

A web platform for conducting **SPI-RDT** audits — *Stepwise Process for Improving the Quality of Rapid Diagnostic Testing*.

SPI-RDT is a quality audit instrument for point-of-care (POC) rapid diagnostic testing sites in decentralised health settings. An assessor visits a testing site, works through a 59-question checklist across five sections, and the site receives a percentage score and a certification level from 0 to 4.

## Where to start

| If you want to | Read |
|---|---|
| Put this on a server, or update one | [Installation](getting-started.md), then [Deployment](deployment.md) |
| Run an audit on a tablet | [Conducting an audit](conducting-an-audit.md) |
| Manage the registry, accounts and reports | [Administering the platform](administering.md) |
| Understand how it is built | [Architecture](architecture.md) |

## What it does

**Assessor app** — offline-first. Assessors work in labs and clinics where connectivity is unreliable. They complete the checklist, score the site, record gaps and signatures, and sync when back in coverage.

**Management console** — online only. Accounts and roles, the facility and testing-site registry, assignments, and the settings for the installation.

**Reporting** — a per-visit report written to be handed to the laboratory, and a dashboard covering certification levels, the weakest sections and where audits have happened.

## Why it exists

Rapid diagnostic testing has expanded across many disease programmes, and disease-specific assessment tools have multiplied with it. SPI-RDT is deliberately **pathogen-agnostic**: one site visit and one checklist can cover every rapid test performed at a testing site, so assessors train once and gaps affecting several tests surface together.

The platform adds what paper cannot:

- **Findings become tracked items.** Every *Partial* or *No* produces a gap with a recommendation, an owner, a responsibility level and an urgency — rather than a table filled in after the visit that nobody revisits.
- **Question 1.8 answers itself.** *"Have gaps from the last assessment been addressed?"* is derived from the previous audit's findings instead of relying on recall.
- **Escalation is visible.** Gaps outside a site's control are routed to district, regional or national level rather than counted against the site and forgotten.

## The instrument

| Section | Name | Questions | Scope |
|---|---|---|---|
| 1 | Organization and Management | 8 | Facility |
| 2 | Physical Facility and Equipment | 10 | Facility |
| 3 | Safety | 9 | Facility |
| 4 | Testing | 23 | **Per pathogen** |
| 5 | Specimen Referral | 9 | Site (optional) |

Section 4 repeats for each pathogen tested at the site, so a site running HIV, malaria and syphilis rapid tests answers those 23 questions three times. The same question can legitimately score differently per pathogen.

The two source documents are tracked in `resources/source/`, alongside the extracted text the base template is generated from. See [Scoring](scoring.md) for how responses become a certification level, and [Templates](templates.md) for how the instrument is versioned.
