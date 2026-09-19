# Linking roster players to the people who log in

A roster player and the account that plays as them live in different tables, and
that is deliberate rather than an accident of the merge:

- **`users`** is the credential record — anyone who authenticates. That includes
  PropOff party guests, who hold a magic link and enter their own answers.
- **`players`** is a name on one household's roster, entered by the household
  owner. A roster player never logs in; someone else records their scores.

One human is legitimately both. They are also several players if they play in
several households — Bert appears on three rosters, one per household, and that
is the correct shape, not duplication.

What was missing is the join. "Hazel on the Williams roster" and "Hazel who
played the Super Bowl" were the same person with nothing saying so.
`players.user_id` has always existed for exactly this and nothing populated it
except each household owner's own player.

## Linking is cheap; merging is not

A link writes one foreign key and `--unlink` takes it back. Nothing is deleted
to make one. That is the opposite of a guest merge, where getting it wrong means
restoring from a dump — so the bar for confirming a link is lower.

## How to run it

```
php artisan players:link-users --candidates
```

Confidence is based on more than the name, because names alone proved unreliable
during the merge work — there are two different people called Hazel.

| Confidence | Meaning |
|---|---|
| `strong` | Name matches **and** that account is a member of this household |
| `possible` | Full name matches, but they are not in this household |
| `ambiguous` | The player matches two or more people — usually unmerged guest rows |
| `weak` | First name only, nothing else |

**Resolve `ambiguous` rows by merging first.** A player matching two accounts
normally means two guest rows for one human that `propoff:merge-guests` has not
folded together yet. Merge them and the choice disappears.

Then link, record, and replay:

```
php artisan players:link-users --link=PLAYER:USER --dry-run
php artisan players:link-users --link=PLAYER:USER
php artisan players:link-users --from=database/merges/roster-links.json
```

The decision file follows the same convention as guest merges: decisions are
made once, replayed per environment, skipped if already applied, and refused if
the names no longer match the ids.

## What it refuses

- **A player already linked to someone else.** Unlink first, so replacing a link
  is always deliberate.
- **Putting one person on the same roster twice.** The app enforces this when
  adding players by hand; linking respects the same rule.

## If you linked by hand

Links made directly on an environment leave no record, and unlike a merge there
is no missing row to notice afterwards — the two databases just quietly disagree.
Capture whatever was done and commit it:

```
php artisan players:link-users --export=database/merges/roster-links.json
```

Then apply it everywhere else. Replaying a link that already exists is skipped,
so it is safe to run against an environment that was linked by hand. Household
owners' own players are left out: the app creates those links itself in
`ensureDefaultHousehold`.

## Order of work

1. `propoff:merge-guests --candidates` — fold duplicate people together
2. `players:link-users --candidates` — connect what remains to the rosters

Doing it the other way round means linking to rows that are about to be merged.
