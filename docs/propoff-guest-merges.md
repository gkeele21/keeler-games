# Folding PropOff guest credentials into real people

PropOff creates a `users` row for every party guest, so one human accumulates
several — 136 guest rows exist for roughly 120 people, and some of those people
also hold a real account.

Which guest is which person **cannot be determined automatically**. The name
overlap is mostly first-names-only, and "Megan" appears three times in the live
data. So the decisions are made by someone who knows these people, recorded
once, and replayed identically in every environment.

## Why this is not a migration

Following the convention set in `docs/america-says-attendance-note.md`: schema
belongs in migrations, data fixes belong in a one-off idempotent script run once
per environment. A deploy that silently deletes user rows is the wrong shape —
these merges should be run deliberately, watched, and checked afterwards.

## How to run it

**1. See what looks mergeable.** Suggestions only; nothing acts on its own.

```
php artisan propoff:merge-guests --candidates
```

Full-name matches are strong. First-name-only matches are a weak hint and are
labelled as such — two different people called Tara is exactly as likely as one.

**2. Record the ones you're sure about** in
`database/merges/propoff-guest-merges.json`:

```json
{
  "source": 123,
  "target": 142,
  "source_name": "Krista",
  "target_name": "Krista Keele",
  "note": "confirmed — same person, joined by link then logged in"
}
```

`source` is always the guest credential being folded away; `target` is the
person who remains. The names are not decoration: they are checked before
anything is written, and a mismatch aborts the whole run.

**3. Dry-run, then apply — locally first, then production.**

```
php artisan propoff:merge-guests --from=database/merges/propoff-guest-merges.json --dry-run
php artisan propoff:merge-guests --from=database/merges/propoff-guest-merges.json
```

Re-running is a no-op: a source that no longer exists has already been merged
and is skipped. That is what makes it safe to run on production after local.

## What a merge does

All 22 foreign key columns pointing at `users` are moved. Where the target
already holds an equivalent row under a uniqueness rule — both already in the
same group, say — the guest's row is **removed**, and the output says so
explicitly.

## What it refuses

- **Both hold an entry in the same group.** They played it separately, so either
  they are not the same person or one entry is a duplicate whose answers
  somebody has to choose between.
- **The source is a real account.** Guests merge into accounts, never the
  reverse.
- **The names no longer match the ids.** Ids are only stable because every
  environment is restored from the same production data. If that stops being
  true, this catches it instead of merging strangers.

Refusals happen inside the transaction, so nothing is written.

## Ids and environments

Local ids match production because local is a restore of it. The name check is
the backstop if that ever stops holding — it aborts rather than guessing.
