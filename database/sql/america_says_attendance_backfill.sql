-- America Says attendance — one-time data setup & backfill.
--
-- Schema (the game_session_players table) is created by the migration
-- 2026_08_12_000001_create_game_session_players_table. THIS file is the DATA:
-- question usage, the Williams Family roster, the owner's player, and who
-- played the first four tracked games.
--
-- Safe to re-run: every statement is idempotent (guarded by NOT EXISTS /
-- INSERT IGNORE / recomputed SET). Run it once locally and once on prod, e.g.
--   mysql keeler_games < database/sql/america_says_attendance_backfill.sql
-- or via tinker: DB::unprepared(file_get_contents('database/sql/america_says_attendance_backfill.sql'));

-- 1) Backfill questions.times_used = number of DISTINCT completed games each
--    question appeared in (matches the live "on completion" counter). SET, so
--    re-running lands on the same authoritative value.
UPDATE questions q
SET q.times_used = (
    SELECT COUNT(DISTINCT sq.game_session_id)
    FROM session_questions sq
    JOIN games gs ON gs.id = sq.game_session_id AND gs.kind = 'online'
    WHERE sq.question_id = q.id
      AND gs.status = 'completed'
);

-- 2) Repurpose the owner's placeholder household as the Williams-side group.
--    No-op once already renamed.
UPDATE households SET name = 'Williams Family' WHERE name = 'Bert''s Household';

-- 3) Williams Family roster — the 18 hand-entered players (no logins).
INSERT INTO players (household_id, name, is_guest, created_at, updated_at)
SELECT h.id, n.name, 0, NOW(), NOW()
FROM households h
CROSS JOIN (
    SELECT 'Avery' AS name UNION ALL SELECT 'Jordy' UNION ALL SELECT 'Luke'
    UNION ALL SELECT 'Macy'  UNION ALL SELECT 'Nick'  UNION ALL SELECT 'Ben'
    UNION ALL SELECT 'Papa'  UNION ALL SELECT 'Becky' UNION ALL SELECT 'Rylee'
    UNION ALL SELECT 'Holly' UNION ALL SELECT 'Erin'  UNION ALL SELECT 'Drew'
    UNION ALL SELECT 'Tiff'  UNION ALL SELECT 'Tate'  UNION ALL SELECT 'Jackson'
    UNION ALL SELECT 'Tricia' UNION ALL SELECT 'Nana' UNION ALL SELECT 'Addi'
) n
WHERE h.name = 'Williams Family'
  AND NOT EXISTS (
      SELECT 1 FROM players p WHERE p.household_id = h.id AND p.name = n.name
  );

-- 4) Owner's own player, under their FIRST name (e.g. "Bert", not "Bert Keele").
--    First normalise any existing owner player (Scorekeeper auto-creates it from
--    the full account name), then create it if missing.
UPDATE players p
JOIN households h ON h.id = p.household_id AND h.name = 'Williams Family'
JOIN users u ON u.id = p.user_id AND u.id = h.owner_user_id
SET p.name = COALESCE(NULLIF(TRIM(u.first_name), ''), TRIM(CONCAT_WS(' ', u.first_name, u.last_name)));

INSERT INTO players (household_id, name, user_id, is_guest, created_at, updated_at)
SELECT h.id, COALESCE(NULLIF(TRIM(u.first_name), ''), TRIM(CONCAT_WS(' ', u.first_name, u.last_name))), u.id, 0, NOW(), NOW()
FROM households h
JOIN users u ON u.id = h.owner_user_id
WHERE h.name = 'Williams Family'
  AND NOT EXISTS (
      SELECT 1 FROM players p WHERE p.household_id = h.id AND p.user_id = u.id
  );

-- 5) Attendance for the first four tracked games (session ids 13/14/16/17).
--    Matched by roster NAME within Williams Family (so the owner's player, which
--    never played, is never included). INSERT IGNORE dedupes on re-run.

-- Games 16 & 17: everyone (all 18).
INSERT IGNORE INTO game_session_players (game_session_id, player_id)
SELECT s.sid, p.id
FROM (SELECT 16 AS sid UNION ALL SELECT 17) s
JOIN players p ON p.household_id = (SELECT id FROM households WHERE name = 'Williams Family')
WHERE p.name IN ('Avery','Jordy','Luke','Macy','Nick','Ben','Papa','Becky','Rylee',
                 'Holly','Erin','Drew','Tiff','Tate','Jackson','Tricia','Nana','Addi');

-- Game 14: all except Ben, Becky, Macy, Drew (14 players).
INSERT IGNORE INTO game_session_players (game_session_id, player_id)
SELECT 14, p.id
FROM players p
WHERE p.household_id = (SELECT id FROM households WHERE name = 'Williams Family')
  AND p.name IN ('Avery','Jordy','Luke','Nick','Papa','Rylee','Holly','Erin',
                 'Tiff','Tate','Jackson','Tricia','Nana','Addi');

-- Game 13: all except Ben, Becky, Macy, Drew, Nick, Tricia (12 players).
INSERT IGNORE INTO game_session_players (game_session_id, player_id)
SELECT 13, p.id
FROM players p
WHERE p.household_id = (SELECT id FROM households WHERE name = 'Williams Family')
  AND p.name IN ('Avery','Jordy','Luke','Papa','Rylee','Holly','Erin',
                 'Tiff','Tate','Jackson','Nana','Addi');
