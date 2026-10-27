# SmartClass documentation

| Document | Source | PDF |
| --- | --- | --- |
| User Manual (teachers and students) | `user-manual/user-manual.tex` | `user-manual/user-manual.pdf` |
| Administrator and Operations Manual | `admin-manual/admin-manual.tex` | `admin-manual/admin-manual.pdf` |
| Installation Guide | `installation/installation-guide.tex` | `installation/installation-guide.pdf` |

Shared LaTeX parts are in `common/` (`preamble.tex`, `glossary.tex`). Screenshots are in `*/figures/`; they were taken from a demonstration school with invented names (throw-away database, never real data).

Rebuild all PDFs: `sh docs/build.sh` (XeLaTeX, makeglossaries, fonts DejaVu and Noto Sans Arabic).

Developer and feature notes: `ARCHITECTURE.md`, `CUSTOMERS.md`, `WHATSAPP.md`, `CAPACITY.md`, `PRIVACY.md`, `AI_POLICY_TEMPLATE.md`. Test, defect and release reports: `../qa/`.
