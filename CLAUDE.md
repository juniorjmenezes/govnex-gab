# Claude Code Instructions

@AGENTS.md

## Cost-aware routing

The imported `AGENTS.md` contains the canonical project rules.

The default Claude model for this project is Sonnet with Low effort.

Use the lowest-cost execution path that can reliably complete the task,
but do not sacrifice understanding for theoretical savings.

### MICRO — execute directly in Sonnet Low

Use for genuinely mechanical, explicit, localized changes such as:
- one exact color change;
- one exact spacing value;
- one exact text replacement;
- one explicit class change;
- one small border, radius, or font change;
- one tiny single-file visual correction.

Do not spawn a subagent.

### FRONTEND — execute directly in Sonnet Low

Normal frontend work should also stay in the current Sonnet Low conversation.

Includes:
- modals and dialogs;
- forms;
- React components;
- shadcn/ui;
- tables, filters, cards, tabs;
- page sections and dashboards;
- responsive layouts;
- frontend state and interactions;
- moderate HTML/CSS/Tailwind/Bootstrap work;
- visual fixes requiring understanding of surrounding structure.

Do NOT treat ambiguous requests such as:
- "fix this modal";
- "adjust this form";
- "improve this component";
- "make this page match the rest of the app";
- "correct this alignment";
- "reorganize these fields";

as MICRO merely because the requested result sounds small.

Handle them directly in Sonnet Low.

### FEATURE — use `feature`

Delegate normal application functionality to `feature`:
- CRUD;
- models, migrations, controllers;
- validation;
- APIs;
- business rules;
- frontend/backend integration;
- moderate debugging.

`feature` uses Sonnet Medium.

### COMPLEX — use `architect`

Delegate architectural work to `architect`:
- new modules/subsystems;
- complex data modeling;
- banking/payment integrations;
- complex workflows;
- structural refactoring;
- cross-domain changes.

`architect` uses Opus Medium.

### DEEP — use `deep-reasoning`

Use only for genuinely difficult problems:
- concurrency/race conditions;
- transactional integrity;
- serious data-integrity issues;
- complex security architecture;
- persistent multi-layer bugs.

`deep-reasoning` uses Opus High.

### Haiku

Haiku is not part of automatic routing.

`ui-fast` exists only for explicit use on unquestionably mechanical edits.
Do not downgrade normal modal, form, component, or page work to Haiku.

### Cost/context rules

- Do not spawn a subagent for MICRO or FRONTEND.
- Use at most one implementation subagent by default.
- Do not create automatic reviewer/tester/research agents.
- Do not parallelize routine work.
- Start with directly relevant files.
- Expand context only when necessary.
- Avoid repository-wide exploration for localized work.
- Do not refactor unrelated code.
- Prefer the smallest reliable diff.
- Keep routine completion responses concise.

### Validation

- MICRO: no full build/test suite by default.
- FRONTEND: validate the affected area.
- FEATURE: run targeted relevant tests/checks.
- COMPLEX/DEEP: broaden validation according to impact.

### User override

Explicit user instructions about model, effort, delegation, or depth override automatic routing.
