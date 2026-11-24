# Keeler Games

Laravel + Inertia + Vue 3 (TypeScript) app. Dark-themed design system ported from PropOff.

## UI color palette — STRICT

All UI colors MUST come from the semantic design tokens defined in
[tailwind.config.js](tailwind.config.js) (`theme.extend.colors`), which are backed by CSS
variables in [resources/css/app.css](resources/css/app.css) scoped to the `.keeler-app` /
`.propoff-app` wrappers.

**Do NOT use any color outside this list.** That means no raw Tailwind color utilities such as
`bg-white`, `text-gray-*`, `bg-gray-*`, `border-gray-*`, `*-blue-*`, `*-purple-*`, `*-pink-*`,
`*-yellow-*`, `*-indigo-*`, `*-teal-*`, `*-cyan-*`, etc.

**If a design needs a color that isn't in this list, STOP and ask before adding it.** Do not
reach for a raw Tailwind color or invent a new token on your own.

### Allowed tokens

- **Surfaces:** `bg-bg`, `bg-surface`, `bg-surface-elevated`, `bg-surface-overlay`,
  `bg-surface-inset`, `bg-surface-header`
- **Text:** `text-body`, `text-muted`, `text-subtle`
- **Borders:** `border-border`, `border-border-strong`
- **Primary (brand green):** `primary`, `primary-hover` — e.g. `bg-primary`, `hover:bg-primary-hover`, `text-primary`
- **Semantic:** `success`, `warning`, `danger`, `info`, `gold`
- **Opacity modifiers are fine:** `bg-primary/10`, `border-info/30`, `text-warning/80`, etc.

Standard input styling (matches `resources/js/Components/Form/*`):
`w-full rounded-lg border-border bg-surface-inset text-body placeholder:text-muted focus:border-primary focus:ring-primary`

### Button color conventions

- **Primary / confirm actions** (Create, Add, Start, Save) → `bg-primary` / `hover:bg-primary-hover`
- **Secondary** → `bg-surface-overlay text-body border border-border`
- **Informational / neutral action** → `bg-info`
- **Destructive** (Remove, Delete, Cancel game) → `text-danger` / `bg-danger`

### Sanctioned exceptions (already in codebase — keep as-is, don't extend)

- **Game-type gradient tiles** on the Create New Game screen use `from-*`/`to-*` brand
  gradients as each game's visual identity.
- **Team colors** are per-team hex values stored in data and applied via inline `:style`
  (not palette classes).

## Ticket workflow

"Work on GAM-123" runs the `work-on-ticket` skill from the shared `team-workflow` plugin
(github.com/gkeele21/claude-team, enabled in `.claude/settings.json` along with `dev-workflow`).
Settings for this repo:

- **Jira:** site `grantkeele-topdawgsports.atlassian.net`, project key `GAM`. Use your own
  Atlassian connection for that site; name it in `CLAUDE.local.md` if you have more than one.
- **Main branch:** `main`
- **Pull requests:** GitHub (`gkeele21/keeler-games`). After the developer approves the push, open one
  with `gh pr create --base main`, titled `<KEY>: <what changed>`.
- **Checks before done:** `php artisan test` and `npm run build`
