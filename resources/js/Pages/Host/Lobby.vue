<script setup lang="ts">
import StandardLayout from '@/Layouts/StandardLayout.vue';
import GamePicker from '@/Components/GamePicker.vue';
import Card from '@/Components/Base/Card.vue';
import Button from '@/Components/Base/Button.vue';
import Modal from '@/Components/Base/Modal.vue';
import TextField from '@/Components/Form/TextField.vue';
import Select from '@/Components/Form/Select.vue';
import NumberInput from '@/Components/Form/NumberInput.vue';
import Toggle from '@/Components/Form/Toggle.vue';
import Confirm from '@/Components/Feedback/Confirm.vue';
import BlankText from '@/Components/BlankText.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';

interface TeamMember {
    id: number;
    user_id: number | null;
    guest_name: string | null;
    is_captain: boolean;
    display_name: string;
}

interface Team {
    id: number;
    name: string;
    color: string;
    display_order: number;
    members: TeamMember[];
}

interface Friend {
    id: number;
    name: string;
    first_name: string;
    nickname: string | null;
}

interface WaitingPlayer {
    id: number;
    user_id: number | null;
    guest_name: string | null;
    display_name: string;
}

interface GameSession {
    id: number;
    name: string | null;
    status: string;
    invite_code: string;
    game_type: { name: string; slug: string };
    teams: Team[];
}

interface GameTypeOption {
    id: number;
    name: string;
    slug: string;
}

interface BankQuestion { id: number; question_text: string; round_type: 'regular' | 'final'; is_official: boolean; answers_count: number; category: string | null; category_id: number | null; difficulty: string | null; times_used: number }
interface QuestionData { bank: BankQuestion[] }
interface Slot { id: number | null; pinned: boolean }
interface QSelection { regular: Slot[][]; final: Slot[] }

interface RosterPlayer { id: number; name: string }
interface AttendanceHousehold { id: number; name: string; players: RosterPlayer[] }
interface AttendanceData {
    households: AttendanceHousehold[];
    present: number[];
    seenByPlayer: Record<number, number[]>;
    defaultHouseholdId: number | null;
}

interface Props {
    gameSession: GameSession;
    config: Record<string, any>;
    friends: Friend[];
    waitingPlayers: WaitingPlayer[];
    gameTypes: GameTypeOption[];
    questionData: QuestionData | null;
    attendanceData: AttendanceData | null;
    wasStarted: boolean;
}

const props = defineProps<Props>();

const gameSlug = computed(() => props.gameSession.game_type.slug);

// Switch the game in place (resets settings to the new game's defaults; teams kept).
const changeGame = (gt: GameTypeOption) => {
    if (gt.slug === props.gameSession.game_type.slug) return;
    router.post(route('games.game-type.update', props.gameSession.id), { game_type_id: gt.id }, {
        preserveScroll: true,
        preserveState: false,
    });
};

// ---- Confirm dialog (shared) ----
const confirmState = ref<{ show: boolean; title: string; message: string; confirmText: string; action: (() => void) | null }>({
    show: false, title: '', message: '', confirmText: 'Confirm', action: null,
});
const askConfirm = (opts: { title: string; message: string; confirmText?: string }, action: () => void) => {
    confirmState.value = { show: true, title: opts.title, message: opts.message, confirmText: opts.confirmText ?? 'Confirm', action };
};
const runConfirm = () => {
    const action = confirmState.value.action;
    confirmState.value.show = false;
    action?.();
};

// ---- Number of teams (reconciles to the target count) ----
const teamCountInput = ref(props.gameSession.teams.length);
watch(() => props.gameSession.teams.length, (n) => (teamCountInput.value = n));
const applyTeamCount = (n: number) => {
    const count = Math.max(1, Math.min(8, Number(n) || 1));
    teamCountInput.value = count;
    if (count === props.gameSession.teams.length) return;
    router.post(route('games.teams.count', props.gameSession.id), { count }, { preserveScroll: true });
};
// America Says is strictly two teams — if a game lands here with a different count
// (e.g. switched game type), normalise it to 2 once.
if (props.gameSession.game_type.slug === 'america-says' && props.gameSession.teams.length !== 2) {
    applyTeamCount(2);
}

// ---- Team rename (inline, save on blur/Enter) ----
const renameTeam = (team: Team, event: Event) => {
    const el = event.target as HTMLInputElement;
    const name = el.value.trim();
    if (!name) {
        el.value = team.name; // don't allow a blank name — revert
        return;
    }
    if (name === team.name) return; // unchanged
    router.patch(route('games.teams.update', [props.gameSession.id, team.id]), { name }, { preserveScroll: true });
};
const removeTeam = (team: Team) => {
    askConfirm(
        { title: 'Remove team?', message: `Remove "${team.name}"? Any members on it will be removed too.`, confirmText: 'Remove' },
        () => router.delete(route('games.teams.remove', [props.gameSession.id, team.id]), { preserveScroll: true }),
    );
};

// ---- Team members ----
const activeTeamId = ref<number | null>(null);
const showAddMemberModal = ref(false);
const addMemberType = ref<'guest' | 'friend' | 'waiting'>('guest');
const guestNameInput = ref('');
const selectedFriendId = ref<number | null>(null);
const selectedWaitingPlayerId = ref<number | null>(null);
const memberForm = useForm({
    type: 'guest' as 'guest' | 'friend' | 'session_player',
    guest_name: '',
    user_id: null as number | null,
    session_player_id: null as number | null,
});
const openAddMember = (teamId: number) => {
    activeTeamId.value = teamId;
    showAddMemberModal.value = true;
    addMemberType.value = 'guest';
    guestNameInput.value = '';
    selectedFriendId.value = null;
    selectedWaitingPlayerId.value = null;
};
const closeAddMemberModal = () => {
    showAddMemberModal.value = false;
    activeTeamId.value = null;
};
const addTeamMember = () => {
    if (!activeTeamId.value) return;
    if (addMemberType.value === 'guest') {
        memberForm.type = 'guest';
        memberForm.guest_name = guestNameInput.value;
        memberForm.user_id = null;
        memberForm.session_player_id = null;
    } else if (addMemberType.value === 'friend') {
        memberForm.type = 'friend';
        memberForm.guest_name = '';
        memberForm.user_id = selectedFriendId.value;
        memberForm.session_player_id = null;
    } else {
        memberForm.type = 'session_player';
        memberForm.guest_name = '';
        memberForm.user_id = null;
        memberForm.session_player_id = selectedWaitingPlayerId.value;
    }
    memberForm.post(route('games.teams.members.add', [props.gameSession.id, activeTeamId.value]), {
        preserveScroll: true,
        onSuccess: () => {
            closeAddMemberModal();
            memberForm.reset();
        },
    });
};
const removeTeamMember = (team: Team, member: TeamMember) => {
    askConfirm(
        { title: 'Remove member?', message: `Remove "${member.display_name}" from ${team.name}?`, confirmText: 'Remove' },
        () => router.delete(route('games.teams.members.remove', [props.gameSession.id, team.id, member.id]), { preserveScroll: true }),
    );
};
const isFriendOnTeam = (friendId: number): boolean =>
    props.gameSession.teams.some((team) => team.members?.some((m) => m.user_id === friendId));
const availableFriends = computed(() => props.friends.filter((f) => !isFriendOnTeam(f.id)));
const addMemberDisabled = computed(() =>
    memberForm.processing
    || (addMemberType.value === 'guest' && !guestNameInput.value)
    || (addMemberType.value === 'friend' && !selectedFriendId.value)
    || (addMemberType.value === 'waiting' && !selectedWaitingPlayerId.value),
);

// ---- Settings (inline; saved via games.settings.update) ----
const settingsForm = useForm({
    name: props.gameSession.name ?? '',
    settings: { ...props.config } as Record<string, any>,
});
const justSaved = ref(false);
const saveSettings = (after?: () => void) => {
    settingsForm.patch(route('games.settings.update', props.gameSession.id), {
        preserveScroll: true,
        onSuccess: () => {
            justSaved.value = true;
            setTimeout(() => (justSaved.value = false), 2000);
            after?.();
        },
    });
};

// Auto-save name & settings shortly after they change (debounced), so there's
// no separate "Save" step — everything on this page persists on its own.
let saveTimer: ReturnType<typeof setTimeout> | null = null;
watch(
    () => settingsForm.data(),
    () => {
        if (!settingsForm.isDirty) return;
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            if (settingsForm.isDirty && !settingsForm.processing) saveSettings();
        }, 700);
    },
    { deep: true },
);

// ---- Rounds & scoring (America Says) ----
interface RoundScore { points_per_answer: number; bonus_points: number }
const teamsCount = computed(() => props.gameSession.teams.length || 1);
// Answers per question is a fixed rule of the game (7), not a host setting — the
// value still drives the sweep-bonus math below.
const answersPerQuestion = computed(() => Number(settingsForm.settings.answers_per_question) || 7);
const strikeOptions = [
    { value: 2, label: '2' },
    { value: 3, label: '3' },
    { value: 4, label: '4' },
];
const rounds = computed<RoundScore[]>({
    get: () => (settingsForm.settings.round_scoring ?? []) as RoundScore[],
    set: (v) => (settingsForm.settings.round_scoring = v),
});
const totalQuestions = computed(() => rounds.value.length * teamsCount.value);
const sweptTotal = (r: RoundScore) => r.points_per_answer * answersPerQuestion.value + r.bonus_points;

// Number-of-rounds stepper (reconciles the round_scoring list to the target).
const roundCountInput = ref(rounds.value.length);
watch(() => rounds.value.length, (n) => (roundCountInput.value = n));
const applyRoundCount = (n: number) => {
    const target = Math.max(1, Math.min(8, Number(n) || 1));
    roundCountInput.value = target;
    if (target === rounds.value.length) return;
    const next = rounds.value.slice(0, target);
    while (next.length < target) {
        const last = next[next.length - 1];
        const pts = last ? last.points_per_answer + 100 : 100;
        next.push({ points_per_answer: pts, bonus_points: pts * 10 });
    }
    rounds.value = next;
};
const removeRound = (index: number) => {
    rounds.value = rounds.value.filter((_, i) => i !== index);
};

// ---- Question selection (per-slot picker: America Says + Family Feud) ----
// The picker backs both games; Oodles stays random and has no picker.
const isPickerGame = computed(() => gameSlug.value === 'america-says' || gameSlug.value === 'family-feud');

// America Says defaults to 3 rounds when first set up.
if (gameSlug.value === 'america-says' && (!Array.isArray(settingsForm.settings.round_scoring) || !settingsForm.settings.round_scoring.length)) {
    settingsForm.settings.round_scoring = [
        { points_per_answer: 100, bonus_points: 1000 },
        { points_per_answer: 200, bonus_points: 2000 },
        { points_per_answer: 300, bonus_points: 3000 },
    ];
}
if (gameSlug.value === 'america-says' && settingsForm.settings.final_round_enabled === undefined) {
    settingsForm.settings.final_round_enabled = true;
}
if (!settingsForm.settings.question_selection || !Array.isArray((settingsForm.settings.question_selection as QSelection).regular)) {
    settingsForm.settings.question_selection = { regular: [], final: [] } as QSelection;
}
const qsel = computed(() => settingsForm.settings.question_selection as QSelection);

// Per-game slot shape. Regular grid = rows (rounds) × cols (America Says: one
// per team; Family Feud: one face-off per round). Final section = America Says'
// 4 answer-count tiers, or Family Feud's 5 flat Fast Money slots.
// Family Feud is played like the real show — first to 300 wins — so the round
// count isn't a setting: we always pre-pick the standard four (single, single,
// double, triple), and a game that stays tight past them pulls extra questions
// automatically at play time.
const FF_ROUNDS = 4;
const regularCols = computed(() => (gameSlug.value === 'family-feud' ? 1 : teamsCount.value));
const regularRowCount = computed(() =>
    gameSlug.value === 'family-feud' ? FF_ROUNDS : rounds.value.length,
);
const ffRoundMultiplier = (i: number) => (i < 2 ? '×1' : i === 2 ? '×2' : '×3');
const regularRowLabel = (i: number) =>
    gameSlug.value === 'family-feud' ? `Round ${i + 1} · ${ffRoundMultiplier(i)}` : `Round ${i + 1}`;

// Final-section config, driven by game.
const finalEnabledKey = computed(() => (gameSlug.value === 'family-feud' ? 'fast_money_enabled' : 'final_round_enabled'));
const finalTiered = computed(() => gameSlug.value !== 'family-feud'); // AS tiers by answer count; FF is flat
const finalToggleLabel = computed(() => (gameSlug.value === 'family-feud' ? 'Fast Money round' : 'Final round'));
const finalSectionTitle = computed(() => (gameSlug.value === 'family-feud' ? 'Fast Money' : 'Final round'));
const finalHint = computed(() => (gameSlug.value === 'family-feud' ? '5 questions · 2 players' : 'top 1 → 4 · 60s'));
const finalNote = computed(() => gameSlug.value === 'family-feud'
    ? 'Fast Money draws from Final-type questions — both players answer the same five. Author them in the library.'
    : 'Final slots use only Final-type questions with the exact answer count — author them in the library.');
const finalSlotBadge = (i: number) => (gameSlug.value === 'family-feud' ? `FM${i + 1}` : `F${i + 1}`);
const finalLabel = (i: number) => (gameSlug.value === 'family-feud' ? `Fast Money ${i + 1}` : `Final ${i + 1}`);
const finalEmptyText = (i: number) => (gameSlug.value === 'family-feud' ? 'No Fast Money questions yet' : `No ${i + 1}-answer Final questions yet`);

const qBank = computed(() => props.questionData?.bank ?? []);
const questionById = computed(() => {
    const m = new Map<number, BankQuestion>();
    qBank.value.forEach((q) => m.set(q.id, q));
    return m;
});

// Bank filters — each one shows only if the bank actually has that dimension
// populated, so it adapts per game (e.g. America Says isn't categorized/ranked).
const questionSearch = ref('');
const pickSource = ref('');
const pickType = ref<'regular' | 'final' | ''>('regular');
const pickCategory = ref<number | ''>('');
const pickDifficulty = ref('');
// Only meaningful when the type filter is "Final" — narrows to a specific answer
// count (Final questions come in fixed answer-count tiers).
const pickAnswers = ref<number | ''>('');
const pickSourceOptions = [
    { value: 'official', label: 'From the show' },
    { value: 'custom', label: 'Made up' },
];
const pickTypeOptions = [
    { value: 'regular', label: 'Standard' },
    { value: 'final', label: 'Final' },
];
const difficultyOptions = [
    { value: 'easy', label: 'Easy' },
    { value: 'medium', label: 'Medium' },
    { value: 'hard', label: 'Hard' },
];
const categoryOptions = computed(() => {
    const seen = new Map<number, string>();
    qBank.value.forEach((q) => { if (q.category_id != null && q.category) seen.set(q.category_id, q.category); });
    return Array.from(seen, ([value, label]) => ({ value, label }));
});
// Answer-count options for the Final type filter, drawn from the Final questions
// actually in this bank so it adapts to what's authored.
const answerCountOptions = computed(() => {
    const counts = new Set<number>();
    qBank.value.forEach((q) => { if (q.round_type === 'final') counts.add(q.answers_count); });
    return Array.from(counts).sort((a, b) => a - b).map((n) => ({ value: n, label: `${n} answer${n === 1 ? '' : 's'}` }));
});
// ---- Game category (America Says) ----
// America Says categories are themes (Halloween, Christmas…). With no category
// chosen, categorized questions are left out entirely; choosing one makes the
// picker prefer that category and fill any remaining slots from uncategorized.
const categoryEnabled = computed(() => gameSlug.value === 'america-says');
const gameCategoryId = computed<number | null>(() =>
    categoryEnabled.value ? (settingsForm.settings.question_category_id || null) : null,
);
const categoryName = (id: number | null) => (id == null ? null : categoryOptions.value.find((c) => c.value === id)?.label ?? null);
const gameBank = computed(() => {
    if (!categoryEnabled.value) return qBank.value;
    return qBank.value.filter((q) => q.category_id == null || q.category_id === gameCategoryId.value);
});
// Which filters to show, driven by the data present in this game's bank.
const showSource = computed(() => qBank.value.some((q) => q.is_official) && qBank.value.some((q) => !q.is_official));
const showType = computed(() => qBank.value.some((q) => q.round_type === 'final'));
const showCategory = computed(() => qBank.value.some((q) => q.category_id != null));
const showDifficulty = computed(() => qBank.value.some((q) => q.difficulty != null));
const regularPool = computed(() => {
    const s = questionSearch.value.trim().toLowerCase();
    // Filtering the bank to a category reaches past the game category, so the
    // host can hand-pick e.g. a Halloween question into an uncategorized game.
    const base = pickCategory.value ? qBank.value : gameBank.value;
    return base.filter((q) => {
        if (pickType.value && q.round_type !== pickType.value) return false;
        if (pickType.value === 'final' && pickAnswers.value !== '' && q.answers_count !== pickAnswers.value) return false;
        if (pickSource.value === 'official' && !q.is_official) return false;
        if (pickSource.value === 'custom' && q.is_official) return false;
        if (pickCategory.value && q.category_id !== pickCategory.value) return false;
        if (pickDifficulty.value && q.difficulty !== pickDifficulty.value) return false;
        if (s && !q.question_text.toLowerCase().includes(s)) return false;
        return true;
    });
});
const finalByCount = computed<Record<number, BankQuestion[]>>(() => {
    const g: Record<number, BankQuestion[]> = { 1: [], 2: [], 3: [], 4: [] };
    gameBank.value.forEach((q) => { if (q.round_type === 'final' && g[q.answers_count]) g[q.answers_count].push(q); });
    return g;
});
const allFinalPool = computed(() => gameBank.value.filter((q) => q.round_type === 'final'));
// Eligible bank for a given final slot (0-based): tiered by answer count for
// America Says, or the whole Final pool for Family Feud's flat Fast Money.
const finalPoolFor = (idx: number): BankQuestion[] =>
    finalTiered.value ? (finalByCount.value[idx + 1] ?? []) : allFinalPool.value;
const finalEnabled = computed(() => !!settingsForm.settings[finalEnabledKey.value]);
const finalSlotCount = computed(() =>
    gameSlug.value === 'family-feud' ? 5 : Number(settingsForm.settings.final_round_questions ?? 4),
);

// ---- Attendance: who's playing tonight (household roster) ----
// Drives the "already seen this question" signal — any question a present player
// has been served in a past game is flagged and de-prioritised by the picker.
const attendance = computed(() => props.attendanceData);
const attendanceHouseholds = computed(() => attendance.value?.households ?? []);
const hasRoster = computed(() => attendanceHouseholds.value.some((h) => h.players.length));
const selectedHouseholdId = ref<number | null>(
    attendance.value?.defaultHouseholdId ?? attendanceHouseholds.value[0]?.id ?? null,
);
const householdOptions = computed(() =>
    attendanceHouseholds.value.map((h) => ({ value: h.id, label: `${h.name} (${h.players.length})` })),
);
const activeRoster = computed<RosterPlayer[]>(() =>
    attendanceHouseholds.value.find((h) => h.id === selectedHouseholdId.value)?.players ?? [],
);
const presentIds = ref<Set<number>>(new Set(attendance.value?.present ?? []));
const presentCount = computed(() => presentIds.value.size);

const saveAttendance = () => {
    axios.post(route('host.attendance', props.gameSession.id), { player_ids: [...presentIds.value] });
};
const togglePresent = (playerId: number) => {
    const next = new Set(presentIds.value);
    next.has(playerId) ? next.delete(playerId) : next.add(playerId);
    presentIds.value = next;
    saveAttendance();
};
const allRosterPresent = computed(() =>
    activeRoster.value.length > 0 && activeRoster.value.every((p) => presentIds.value.has(p.id)),
);
const toggleAllRoster = () => {
    const next = new Set(presentIds.value);
    if (allRosterPresent.value) activeRoster.value.forEach((p) => next.delete(p.id));
    else activeRoster.value.forEach((p) => next.add(p.id));
    presentIds.value = next;
    saveAttendance();
};

// question_id -> how many present players have already seen it
const groupSeenCounts = computed<Map<number, number>>(() => {
    const seenByPlayer = attendance.value?.seenByPlayer ?? {};
    const counts = new Map<number, number>();
    presentIds.value.forEach((pid) => {
        (seenByPlayer[pid] ?? []).forEach((qid) => counts.set(qid, (counts.get(qid) ?? 0) + 1));
    });
    return counts;
});
const groupSeenIds = computed<Set<number>>(() => new Set(groupSeenCounts.value.keys()));
const seenBy = (id: number | null) => (id == null ? 0 : groupSeenCounts.value.get(id) ?? 0);

// ---- slot grid: regular = rounds x teams, final = N answer-count slots ----
// Prefer the least-used questions: pick at random among those tied at the lowest
// times_used, so the library rotates through everything before repeating. When a
// group is present, questions none of them have seen win over already-seen ones.
// A chosen game category beats both: its questions go first, uncategorized after.
// Next, a round's target difficulty: matching questions first, then unrated ones,
// and only then another difficulty.
const pickRandom = (pool: BankQuestion[], exclude: Set<number>, difficulty: string | null = null): number | null => {
    let avail = pool.filter((q) => !exclude.has(q.id));
    if (!avail.length) return null;
    const inCategory = avail.filter((q) => gameCategoryId.value != null && q.category_id === gameCategoryId.value);
    if (inCategory.length) avail = inCategory;
    if (difficulty) {
        const matching = avail.filter((q) => q.difficulty === difficulty);
        const unrated = avail.filter((q) => q.difficulty == null);
        if (matching.length) avail = matching;
        else if (unrated.length) avail = unrated;
    }
    const unseen = avail.filter((q) => !groupSeenIds.value.has(q.id));
    const pickFrom = unseen.length ? unseen : avail;
    const min = Math.min(...pickFrom.map((q) => q.times_used ?? 0));
    const leastUsed = pickFrom.filter((q) => (q.times_used ?? 0) === min);
    return leastUsed[Math.floor(Math.random() * leastUsed.length)].id;
};
// America Says ramps difficulty by round: easy → medium → hard (and hard after).
const roundDifficulty = (round: number): string | null => {
    if (gameSlug.value !== 'america-says') return null;
    return round === 0 ? 'easy' : round === 1 ? 'medium' : 'hard';
};
const usedRegular = () => {
    const s = new Set<number>();
    qsel.value.regular.forEach((r) => r.forEach((slot) => { if (slot.id != null) s.add(slot.id); }));
    return s;
};
const usedFinal = () => {
    const s = new Set<number>();
    qsel.value.final.forEach((slot) => { if (slot.id != null) s.add(slot.id); });
    return s;
};
// Keep the slot grid sized to rows x cols (+ final slots), filling empties at random.
const reconcileSlots = () => {
    if (!isPickerGame.value) return;
    const prev = qsel.value;
    const regular: Slot[][] = [];
    for (let i = 0; i < regularRowCount.value; i++) {
        const row: Slot[] = [];
        for (let j = 0; j < regularCols.value; j++) row.push(prev.regular?.[i]?.[j] ?? { id: null, pinned: false });
        regular.push(row);
    }
    const final: Slot[] = [];
    for (let n = 0; n < finalSlotCount.value; n++) final.push(prev.final?.[n] ?? { id: null, pinned: false });

    const ur = new Set<number>();
    regular.forEach((r) => r.forEach((s) => { if (s.id != null) ur.add(s.id); }));
    regular.forEach((r, i) => r.forEach((s) => { if (s.id == null) { const id = pickRandom(regularPool.value, ur, roundDifficulty(i)); if (id != null) { s.id = id; ur.add(id); } } }));

    const uf = new Set<number>();
    final.forEach((s) => { if (s.id != null) uf.add(s.id); });
    final.forEach((s, idx) => { if (s.id == null) { const id = pickRandom(finalPoolFor(idx), uf); if (id != null) { s.id = id; uf.add(id); } } });

    settingsForm.settings.question_selection = { regular, final };
};
watch([regularRowCount, regularCols, finalSlotCount], reconcileSlots);
onMounted(reconcileSlots);
// Re-roll every slot the host didn't hand-pick. Clearing them all first, then
// refilling in round order, lets each round claim its difficulty — re-rolling
// one at a time would leave Round 1 unable to take an easy question parked in
// Round 3.
const rerollUnpinned = (groups: { regular?: boolean; final?: boolean } = { regular: true, final: true }) => {
    if (groups.regular) qsel.value.regular.forEach((r) => r.forEach((s) => { if (!s.pinned) s.id = null; }));
    if (groups.final) qsel.value.final.forEach((s) => { if (!s.pinned) s.id = null; });
    reconcileSlots();
};
// Changing the game category re-rolls every slot the host didn't hand-pick.
watch(gameCategoryId, () => {
    pickCategory.value = '';
    rerollUnpinned();
});

// ---- active slot + assignment ----
type ActiveSlot = { group: 'regular'; round: number; team: number } | { group: 'final'; index: number };
// Starts unset — nothing is armed on load, so browsing the bank can't silently
// overwrite a slot. The host picks a slot first, which arms the bank to fill it.
const activeSlot = ref<ActiveSlot | null>(null);
const hasActiveSlot = computed(() => activeSlot.value !== null);
const teamName = (j: number) => props.gameSession.teams[j]?.name ?? `Team ${j + 1}`;
const teamColor = (j: number) => props.gameSession.teams[j]?.color ?? '#888888';
const isRegularActive = (i: number, j: number) => activeSlot.value?.group === 'regular' && activeSlot.value.round === i && activeSlot.value.team === j;
const isFinalActive = (i: number) => activeSlot.value?.group === 'final' && activeSlot.value.index === i;
// Clicking a slot arms the bank to fill it; clicking the armed slot again
// disarms it (bank empties) so the action reads as a clean toggle.
const setRegularActive = (i: number, j: number) => { activeSlot.value = isRegularActive(i, j) ? null : { group: 'regular', round: i, team: j }; };
const setFinalActive = (i: number) => { activeSlot.value = isFinalActive(i) ? null : { group: 'final', index: i }; };
const regularSlotLabel = (i: number, j: number) =>
    regularCols.value > 1 ? `Round ${i + 1} · ${teamName(j)}` : `Round ${i + 1}`;
const activeSlotLabel = computed(() => {
    const a = activeSlot.value;
    if (!a) return null;
    return a.group === 'final' ? finalLabel(a.index) : regularSlotLabel(a.round, a.team);
});
const activeList = computed<BankQuestion[]>(() => {
    const a = activeSlot.value;
    if (!a) return [];
    return a.group === 'final' ? finalPoolFor(a.index) : regularPool.value;
});
const activeCurrentId = computed(() => {
    const a = activeSlot.value;
    if (!a) return null;
    return a.group === 'regular' ? (qsel.value.regular[a.round]?.[a.team]?.id ?? null) : (qsel.value.final[a.index]?.id ?? null);
});
// Every question already assigned to a slot → its slot label (shown in the bank).
const assignedLabels = computed(() => {
    const m = new Map<number, string>();
    qsel.value.regular.forEach((r, i) => r.forEach((s, j) => { if (s.id != null) m.set(s.id, regularSlotLabel(i, j)); }));
    qsel.value.final.forEach((s, idx) => { if (s.id != null) m.set(s.id, finalLabel(idx)); });
    return m;
});
const slotOf = (a: ActiveSlot): Slot | undefined =>
    a.group === 'regular' ? qsel.value.regular[a.round]?.[a.team] : qsel.value.final[a.index];
const assignToActive = (id: number) => {
    if (!activeSlot.value) return;
    const s = slotOf(activeSlot.value);
    if (s) { s.id = id; s.pinned = true; }
    activeSlot.value = null; // replace complete → deselect, so the action reads as done (mirrors swap)
};
const swapSlot = (a: ActiveSlot) => {
    const s = slotOf(a);
    if (!s) return;
    const id = a.group === 'regular'
        ? pickRandom(regularPool.value, usedRegular(), roundDifficulty(a.round))
        : pickRandom(finalPoolFor(a.index), usedFinal());
    if (id != null) { s.id = id; s.pinned = false; }
};
const swapRegular = (i: number, j: number) => swapSlot({ group: 'regular', round: i, team: j });
const swapFinal = (i: number) => swapSlot({ group: 'final', index: i });
const shuffleRegular = () => rerollUnpinned({ regular: true });
const shuffleFinal = () => rerollUnpinned({ final: true });

// ---- Swap mode: exchange two already-slotted regular questions (reorder by
// difficulty across rounds). Distinct from per-slot Shuffle (random from bank)
// and from clicking a bank question (Replace). Regular grid only — the tiered
// final slots are answer-count-locked, so trading them isn't valid.
const swapMode = ref(false);
const swapSource = ref<{ round: number; team: number } | null>(null);
const toggleSwapMode = () => { swapMode.value = !swapMode.value; swapSource.value = null; };
const exitSwapMode = () => { swapMode.value = false; swapSource.value = null; };
const isSwapSource = (i: number, j: number) =>
    !!swapSource.value && swapSource.value.round === i && swapSource.value.team === j;
// Every slot click routes through here: assign-target selection normally, or
// pick source/target while swapping.
const handleRegularClick = (i: number, j: number) => {
    if (!swapMode.value) { setRegularActive(i, j); return; }
    if (!swapSource.value) { swapSource.value = { round: i, team: j }; return; }
    if (isSwapSource(i, j)) { swapSource.value = null; return; } // clicked source again → deselect
    const a = qsel.value.regular[swapSource.value.round]?.[swapSource.value.team];
    const b = qsel.value.regular[i]?.[j];
    if (a && b) {
        // Trade the questions; pinned state travels with each question.
        [a.id, b.id] = [b.id, a.id];
        [a.pinned, b.pinned] = [b.pinned, a.pinned];
    }
    exitSwapMode(); // one swap completes the action and leaves swap mode
};
const regularSlotClass = (i: number, j: number): string => {
    const base = 'flex cursor-pointer items-start gap-2.5 rounded-md border px-2.5 py-2';
    if (swapMode.value) {
        if (isSwapSource(i, j)) return `${base} border-primary bg-primary/10 ring-2 ring-primary`;
        return swapSource.value
            ? `${base} border-info bg-info/10 hover:border-info`       // a source is picked → valid target
            : `${base} border-border-strong bg-surface hover:border-primary`; // awaiting first pick
    }
    return isRegularActive(i, j)
        ? `${base} border-primary bg-primary/10`
        : `${base} border-border bg-surface hover:border-border-strong`;
};
const onKeydown = (e: KeyboardEvent) => { if (e.key === 'Escape' && swapMode.value) exitSwapMode(); };
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
const slotText = (id: number | null) => (id != null ? questionById.value.get(id)?.question_text ?? '(missing)' : null);
const slotMeta = (id: number | null) => {
    const q = id != null ? questionById.value.get(id) : null;
    if (!q) return '';
    return `${categoryEnabled.value && q.category ? `${q.category} · ` : ''}${q.answers_count} answers · used ${q.times_used}×`;
};
// Difficulty badge — same colors as the Question Library.
const slotDifficulty = (id: number | null) => (id != null ? questionById.value.get(id)?.difficulty ?? null : null);
const difficultyClass = (d: string) =>
    d === 'easy' ? 'text-primary border-primary/40'
    : d === 'hard' ? 'text-danger border-danger/40'
    : 'text-warning border-warning/40';
const difficultyLabel = (d: string) => d.charAt(0).toUpperCase() + d.slice(1);
// Usage count alone — the tiered final slots already state the answer count via
// their "Needs N answers" hint, so we only append how often it's been played.
const slotUsed = (id: number | null) => {
    const q = id != null ? questionById.value.get(id) : null;
    if (!q) return '';
    return `${categoryEnabled.value && q.category ? `${q.category} · ` : ''}used ${q.times_used}×`;
};

const questionSelectionReady = computed(() => {
    if (!isPickerGame.value) return true;
    return qsel.value.regular.length > 0 && qsel.value.regular.every((r) => r.length > 0 && r.every((s) => s.id != null));
});

// ---- Start / resume / restart / cancel ----
// A fresh start (re)builds questions from setup and begins at round one.
const startGame = () => {
    if (settingsForm.isDirty) saveSettings(() => router.post(route('games.start', props.gameSession.id)));
    else router.post(route('games.start', props.gameSession.id));
};
// Return to the live game exactly where it was left — no rebuild, no reset.
const resumeGame = () => {
    // POST (not visit) so the server flips the game back to 'playing' before the
    // game screen loads — otherwise the TV display, which gates on 'playing',
    // stays on the lobby "waiting" screen while you play.
    router.post(route('games.resume', props.gameSession.id));
};
// Explicit, destructive re-start of a game that's already been played.
const restartGame = () => {
    askConfirm(
        {
            title: 'Restart from setup?',
            message: 'This wipes the current game — scores and progress — and rebuilds the questions from your setup. This cannot be undone.',
            confirmText: 'Restart game',
        },
        startGame,
    );
};
const cancelGame = () => {
    askConfirm(
        { title: 'Cancel game?', message: 'This deletes the setup and cannot be undone.', confirmText: 'Cancel game' },
        () => router.delete(route('games.destroy', props.gameSession.id)),
    );
};

// ---- Copy helpers ----
const codeCopied = ref(false);
const urlCopied = ref(false);
const displayUrlCopied = ref(false);
const joinUrl = computed(() => `${window.location.origin}/play?code=${props.gameSession.invite_code}`);
const displayUrl = computed(() => `${window.location.origin}/display/${props.gameSession.invite_code}`);
const copyInviteCode = () => {
    navigator.clipboard.writeText(props.gameSession.invite_code);
    codeCopied.value = true;
    setTimeout(() => (codeCopied.value = false), 2000);
};
const copyJoinUrl = () => {
    navigator.clipboard.writeText(joinUrl.value);
    urlCopied.value = true;
    setTimeout(() => (urlCopied.value = false), 2000);
};
const copyDisplayUrl = () => {
    navigator.clipboard.writeText(displayUrl.value);
    displayUrlCopied.value = true;
    setTimeout(() => (displayUrlCopied.value = false), 2000);
};
</script>

<template>
    <Head :title="`Set up — ${gameSession.game_type.name}`" />

    <StandardLayout sticky-header>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-body">Set up your game</h1>
                    <p class="mt-1 text-sm text-muted">Pick a game, name it, set teams &amp; scoring, then start.</p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <span v-if="settingsForm.processing" class="text-sm text-muted">Saving…</span>
                    <span v-else-if="justSaved" class="text-sm text-primary">Saved ✓</span>
                    <Button variant="outline" size="md" @click="cancelGame">Cancel</Button>
                    <template v-if="wasStarted">
                        <Button variant="danger" size="md" :disabled="!gameSession.teams.length || !questionSelectionReady" @click="restartGame">Restart</Button>
                        <Button variant="success" size="md" @click="resumeGame">Resume Game →</Button>
                    </template>
                    <Button v-else variant="success" size="md" :disabled="!gameSession.teams.length || !questionSelectionReady" @click="startGame">Start Game →</Button>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-[1440px] space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <!-- Game & name (+ share / display links) -->
            <Card title="Game">
                <div class="grid gap-5 lg:grid-cols-2">
                    <!-- Game type -->
                    <div>
                        <label class="mb-2 block text-sm font-medium text-muted">Game type</label>
                        <GamePicker :game-types="gameTypes" :model-value="gameSlug" @select="changeGame" />
                    </div>
                    <!-- Share links -->
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-muted">Join link</label>
                            <div class="flex items-center gap-2">
                                <span class="min-w-0 flex-1 truncate rounded-lg border border-border bg-surface-inset px-4 py-2 font-mono text-sm text-muted">{{ joinUrl }}</span>
                                <Button variant="outline" size="md" @click="copyJoinUrl">{{ urlCopied ? 'Copied!' : 'Copy' }}</Button>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-muted">TV / projector display</label>
                            <div class="flex items-center gap-2">
                                <span class="min-w-0 flex-1 truncate rounded-lg border border-info/30 bg-surface-inset px-4 py-2 font-mono text-sm text-info">{{ displayUrl }}</span>
                                <Button variant="outline" size="md" @click="copyDisplayUrl">{{ displayUrlCopied ? 'Copied!' : 'Copy' }}</Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Session name + game code on one line -->
                <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <TextField v-model="settingsForm.name" label="Session name" placeholder="e.g., Sunday Family Night" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-muted">Game code</label>
                        <div class="flex items-center gap-2">
                            <span class="rounded-lg border border-border bg-surface-inset px-4 py-2 font-mono text-xl font-bold tracking-widest text-body">{{ gameSession.invite_code }}</span>
                            <Button variant="outline" size="md" @click="copyInviteCode">{{ codeCopied ? 'Copied!' : 'Copy' }}</Button>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Teams -->
            <Card title="Teams">
                <template #headerActions>
                    <!-- America Says is a fixed head-to-head: exactly two teams, no stepper. -->
                    <div v-if="gameSlug === 'america-says'" class="text-sm text-muted">Head-to-head · 2 teams</div>
                    <div v-else class="flex items-center gap-3">
                        <span class="text-sm text-muted">Number of teams</span>
                        <NumberInput :model-value="teamCountInput" :min="1" :max="8" @update:model-value="applyTeamCount" />
                    </div>
                </template>

                <p class="mb-4 text-xs text-subtle">Members optional — a team can play with just a name</p>

                <div class="space-y-3">
                    <div v-for="team in gameSession.teams" :key="team.id" class="rounded-lg border border-border">
                        <div class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <input
                                    :value="team.name"
                                    type="text"
                                    aria-label="Team name"
                                    :style="{ color: team.color }"
                                    class="min-w-0 max-w-[16rem] flex-1 rounded bg-transparent px-2 py-1 text-lg font-semibold transition hover:bg-surface-inset focus:bg-surface-inset focus:outline-none focus:ring-1 focus:ring-primary"
                                    @change="renameTeam(team, $event)"
                                    @keyup.enter="($event.target as HTMLInputElement).blur()"
                                />
                                <span class="whitespace-nowrap text-sm text-muted">({{ team.members?.length || 0 }} members)</span>
                            </div>
                            <div class="flex flex-none items-center gap-2">
                                <Button variant="secondary" size="xs" icon="plus" @click="openAddMember(team.id)">Members</Button>
                                <Button variant="danger" size="xs" @click="removeTeam(team)">Remove</Button>
                            </div>
                        </div>
                        <div v-if="team.members && team.members.length" class="flex flex-wrap gap-2 p-3">
                            <span v-for="member in team.members" :key="member.id" class="flex items-center gap-2 rounded-full bg-surface-inset px-3 py-1.5 text-sm text-body">
                                {{ member.display_name }}
                                <button class="text-muted hover:text-danger" @click="removeTeamMember(team, member)">&times;</button>
                            </span>
                        </div>
                    </div>

                    <div v-if="!gameSession.teams.length" class="py-6 text-center text-muted">No teams yet — use the control above.</div>
                </div>

                <div class="mt-4 border-t border-border pt-4">
                    <Toggle v-model="settingsForm.settings.allow_team_selection" label="Let players pick their own team when they join by code" />
                </div>
            </Card>

            <!-- Who's playing? (attendance → drives the "already seen" question signal) -->
            <Card v-if="isPickerGame && attendance" title="Who's playing?">
                <template #headerActions>
                    <span class="text-sm text-muted">{{ presentCount }} {{ presentCount === 1 ? 'person' : 'people' }}</span>
                </template>

                <div v-if="!hasRoster" class="text-sm text-muted">
                    No roster yet.
                    <Link :href="route('scorekeeper.home')" class="font-semibold text-primary hover:underline">Add the people you play with</Link>
                    to a household, then check who's here — we'll flag questions the group has already been asked.
                </div>
                <template v-else>
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <Select v-if="householdOptions.length > 1" v-model="selectedHouseholdId" :options="householdOptions" />
                        <span class="text-xs text-subtle">Check everyone at the table — questions this group has already been asked get flagged and skipped when filling slots at random.</span>
                        <Button variant="muted" size="xs" class="ml-auto" @click="toggleAllRoster">{{ allRosterPresent ? 'Clear all' : 'Select all' }}</Button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="p in activeRoster"
                            :key="p.id"
                            type="button"
                            :class="['rounded-full border px-3 py-1.5 text-sm transition', presentIds.has(p.id) ? 'border-primary bg-primary/10 text-body' : 'border-border bg-surface-inset text-muted hover:border-border-strong']"
                            @click="togglePresent(p.id)"
                        >
                            <span v-if="presentIds.has(p.id)" class="mr-1 text-primary">✓</span>{{ p.name }}
                        </button>
                    </div>
                </template>
            </Card>

            <!-- Rounds & scoring (America Says) -->
            <Card v-if="gameSlug === 'america-says'" title="Rounds & scoring">
                <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-3 border-b border-border pb-4">
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-muted">Rounds</span>
                        <NumberInput :model-value="roundCountInput" :min="1" :max="8" @update:model-value="applyRoundCount" />
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-muted">Round Timer</span>
                        <NumberInput v-model="settingsForm.settings.control_timer_seconds" :min="10" :max="60" />
                        <span class="text-sm text-muted">sec</span>
                    </div>
                </div>

                <p class="mb-4 text-sm text-muted">
                    A round plays one question per team ({{ teamsCount }} {{ teamsCount === 1 ? 'team' : 'teams' }}). Each correct answer scores its round's points; sweep the whole board for the bonus.
                    <span class="text-body">{{ rounds.length }} rounds × {{ teamsCount }} = {{ totalQuestions }} questions.</span>
                </p>

                <label class="mb-2 block text-sm font-medium text-body">Points per round</label>
                <div class="space-y-2">
                    <div v-for="(round, i) in rounds" :key="i" class="rounded-lg border border-border bg-surface-inset p-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="font-semibold text-body">Round {{ i + 1 }}</span>
                            <Button v-if="rounds.length > 1" variant="ghost" size="xs" class="!text-danger" @click="removeRound(i)">Remove</Button>
                        </div>
                        <div class="mt-3 flex flex-wrap items-end gap-x-5 gap-y-3">
                            <NumberInput v-model="round.points_per_answer" label="Pts / answer" :min="0" input-class="w-20" />
                            <NumberInput v-model="round.bonus_points" label="Sweep bonus" :min="0" input-class="w-24" />
                            <span class="self-center text-xs text-primary">Sweep all {{ answersPerQuestion }} = {{ sweptTotal(round).toLocaleString() }} pts</span>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Family Feud rules -->
            <Card v-if="gameSlug === 'family-feud'" title="Rules">
                <!-- Strikes, the Fast Money toggle, and its two pass clocks on one
                     row; the timers only appear when Fast Money is on. -->
                <div class="flex flex-wrap items-end gap-x-5 gap-y-4">
                    <div class="w-40"><Select v-model="settingsForm.settings.max_strikes" :options="strikeOptions" label="Strikes before steal" /></div>
                    <div class="flex items-center gap-2 pb-2"><Toggle v-model="settingsForm.settings[finalEnabledKey]" :label="finalToggleLabel" /></div>
                    <template v-if="finalEnabled">
                        <div class="w-36"><NumberInput v-model="settingsForm.settings.fast_money_player1_seconds" label="Player 1 timer (s)" :min="5" :max="60" /></div>
                        <div class="w-36"><NumberInput v-model="settingsForm.settings.fast_money_player2_seconds" label="Player 2 timer (s)" :min="5" :max="60" /></div>
                    </template>
                </div>
            </Card>

            <!-- Questions (per-slot picker: America Says + Family Feud) -->
            <Card v-if="isPickerGame" title="Questions">
                <template #headerActions>
                    <span v-if="gameSlug === 'family-feud'" class="text-sm text-muted">First to 300 · 1×/1×/2×/3×</span>
                    <span v-else class="text-sm text-muted">{{ rounds.length }} rounds × {{ teamsCount }} {{ teamsCount === 1 ? 'team' : 'teams' }}</span>
                </template>

                <div class="grid gap-5 lg:grid-cols-[1fr_1.35fr]">
                    <!-- Slots — left column on desktop, and first when stacked on mobile so
                         clicking a slot keeps your scroll position (the bank grows below it). -->
                    <div class="min-w-0">
                        <div v-if="categoryEnabled && categoryOptions.length" class="mb-4 border-b border-border pb-4">
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-semibold text-body">Category</span>
                                <div class="w-48"><Select v-model="settingsForm.settings.question_category_id" :options="categoryOptions" allow-empty empty-label="None" /></div>
                            </div>
                            <p class="mt-1.5 text-xs text-subtle">
                                <template v-if="gameCategoryId">{{ categoryName(gameCategoryId) }} questions fill the slots first; any left over use regular questions.</template>
                                <template v-else>Categorized questions are left out. Pick a category for a themed game.</template>
                            </p>
                        </div>
                        <div class="mb-2 flex items-center gap-2">
                            <h4 class="text-sm font-semibold text-body">Rounds</h4>
                            <Button :variant="swapMode ? 'primary' : 'muted'" size="xs" class="ml-auto" @click="toggleSwapMode">{{ swapMode ? '⇄ Swapping…' : '⇄ Swap' }}</Button>
                            <Button variant="muted" size="xs" :disabled="swapMode" @click="shuffleRegular">Shuffle all</Button>
                        </div>
                        <p v-if="swapMode" class="mb-2 rounded-md border border-info/30 bg-info/10 px-2.5 py-1.5 text-xs text-info">
                            Swapping positions — click a question, then click another to trade their spots. Press Esc or ⇄ Swap to finish.
                        </p>
                        <div class="space-y-3">
                            <div v-for="(round, i) in qsel.regular" :key="i" class="rounded-lg border border-border bg-surface-inset p-2">
                                <div class="mb-1.5 px-1 text-xs font-semibold text-muted">{{ regularRowLabel(i) }}</div>
                                <div class="space-y-1.5">
                                    <div
                                        v-for="(slot, j) in round"
                                        :key="j"
                                        :class="regularSlotClass(i, j)"
                                        @click="handleRegularClick(i, j)"
                                    >
                                        <span v-if="regularCols > 1" class="mt-1.5 h-2.5 w-2.5 flex-none rounded-full" :style="{ backgroundColor: teamColor(j) }"></span>
                                        <span class="min-w-0 flex-1">
                                            <span :class="['block text-sm font-medium', slot.id ? 'text-body' : 'text-warning']"><BlankText v-if="slot.id" :text="slotText(slot.id)" /><template v-else>— pick a question —</template></span>
                                            <span class="block text-xs text-subtle">
                                                <template v-if="regularCols > 1">{{ teamName(j) }}<template v-if="slot.id"> · {{ slotMeta(slot.id) }}</template></template>
                                                <template v-else>{{ slot.id ? slotMeta(slot.id) : 'Face-off — both teams play' }}</template>
                                            </span>
                                        </span>
                                        <span v-if="slotDifficulty(slot.id)" :class="['mt-0.5 flex-none rounded border px-1.5 py-0.5 text-[10px] font-bold uppercase', difficultyClass(slotDifficulty(slot.id)!)]">{{ difficultyLabel(slotDifficulty(slot.id)!) }}</span>
                                        <span v-if="seenBy(slot.id)" class="flex-none rounded-full border border-warning/50 bg-warning/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-warning" :title="seenBy(slot.id) + ' of tonight\'s players have already been asked this'">Seen · {{ seenBy(slot.id) }}</span>
                                        <Button v-if="!swapMode" variant="muted" size="xs" class="flex-none" @click.stop="swapRegular(i, j)">⟳ Shuffle</Button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- America Says toggles its Final round here (beside the slots).
                             Family Feud's Fast Money toggle lives in the Rules card. -->
                        <div v-if="gameSlug === 'america-says'" class="mt-4 flex items-center gap-2 border-t border-border pt-4">
                            <Toggle v-model="settingsForm.settings[finalEnabledKey]" :label="finalToggleLabel" />
                            <span class="ml-auto text-xs text-subtle">{{ finalHint }}</span>
                        </div>

                        <div v-if="finalEnabled" class="mt-3" :class="{ 'border-t border-border pt-4': gameSlug === 'family-feud' }">
                            <div class="mb-2 flex items-center gap-2">
                                <h4 class="text-sm font-semibold text-body">{{ finalSectionTitle }}</h4>
                                <span class="text-xs text-subtle">{{ finalSlotCount }} slots</span>
                                <Button variant="muted" size="xs" class="ml-auto" @click="shuffleFinal">Shuffle all</Button>
                            </div>
                            <div class="space-y-1.5">
                                <div
                                    v-for="(slot, i) in qsel.final"
                                    :key="i"
                                    :class="['flex cursor-pointer items-start gap-2.5 rounded-md border px-2.5 py-2', isFinalActive(i) ? 'border-primary bg-primary/10' : 'border-border bg-surface-inset hover:border-border-strong']"
                                    @click="setFinalActive(i)"
                                >
                                    <span class="flex-none rounded-md bg-surface-elevated px-2 py-1 text-[10px] font-bold text-info">{{ finalSlotBadge(i) }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span :class="['block text-sm font-medium', slot.id ? 'text-body' : 'text-warning']"><BlankText v-if="slot.id" :text="slotText(slot.id)" /><template v-else>{{ finalEmptyText(i) }}</template></span>
                                        <span v-if="finalTiered" class="block text-xs text-subtle">Needs {{ i + 1 }} answer{{ i === 0 ? '' : 's' }}<template v-if="slot.id"> · {{ slotUsed(slot.id) }}</template></span>
                                        <span v-else-if="slot.id" class="block text-xs text-subtle">{{ slotMeta(slot.id) }}</span>
                                    </span>
                                    <span v-if="seenBy(slot.id)" class="flex-none rounded-full border border-warning/50 bg-warning/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-warning" :title="seenBy(slot.id) + ' of tonight\'s players have already been asked this'">Seen · {{ seenBy(slot.id) }}</span>
                                    <Button v-if="slot.id" variant="muted" size="xs" class="flex-none" @click.stop="swapFinal(i)">⟳ Shuffle</Button>
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-subtle">{{ finalNote }}</p>
                        </div>
                    </div>

                    <!-- Question bank — right column on desktop; below the slots when stacked
                         on mobile so arming a slot grows the list below your viewport. -->
                    <div class="min-w-0 lg:border-l lg:border-border lg:pl-5">
                        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-subtle">
                            Question bank
                            <span v-if="hasActiveSlot" class="ml-1 font-normal normal-case text-muted">— filling <span class="rounded-full border border-primary/50 bg-primary/10 px-2 py-0.5 text-primary">{{ activeSlotLabel }}</span></span>
                            <span v-else class="ml-1 font-normal normal-case text-muted">— select a slot to choose its question</span>
                        </p>
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <template v-if="activeSlot?.group === 'regular'">
                                <Select v-if="showSource" v-model="pickSource" :options="pickSourceOptions" allow-empty empty-label="Any source" />
                                <Select v-if="showType" v-model="pickType" :options="pickTypeOptions" allow-empty empty-label="Any type" />
                                <Select v-if="pickType === 'final' && answerCountOptions.length" v-model="pickAnswers" :options="answerCountOptions" allow-empty empty-label="Any # answers" />
                                <Select v-if="showCategory" v-model="pickCategory" :options="categoryOptions" allow-empty empty-label="All categories" />
                                <Select v-if="showDifficulty" v-model="pickDifficulty" :options="difficultyOptions" allow-empty empty-label="Any difficulty" />
                            </template>
                            <div class="min-w-[110px] flex-1"><TextField v-model="questionSearch" placeholder="Search…" /></div>
                        </div>
                        <div class="max-h-[42rem] overflow-y-auto rounded-lg border border-border">
                            <button
                                v-for="q in activeList"
                                :key="q.id"
                                type="button"
                                :class="['flex w-full flex-col gap-1.5 border-t border-border/60 px-3 py-2.5 text-left first:border-t-0 sm:flex-row sm:items-center sm:gap-3', q.id === activeCurrentId ? 'bg-primary/10' : 'hover:bg-surface-inset']"
                                @click="assignToActive(q.id)"
                            >
                                <span class="min-w-0 flex-1 text-sm text-body"><BlankText :text="q.question_text" /></span>
                                <span class="flex flex-none flex-wrap items-center gap-x-2 gap-y-1 sm:flex-nowrap">
                                    <span v-if="q.difficulty" :class="['flex-none rounded border px-1.5 py-0.5 text-[10px] font-bold uppercase', difficultyClass(q.difficulty)]">{{ difficultyLabel(q.difficulty) }}</span>
                                    <span v-if="categoryEnabled && q.category" class="flex-none whitespace-nowrap rounded border border-gold/40 px-1.5 py-0.5 text-[10px] font-bold uppercase text-gold">{{ q.category }}</span>
                                    <span v-if="q.round_type === 'final'" class="flex-none rounded border border-info/40 px-1.5 py-0.5 text-[10px] font-bold uppercase text-info">Final</span>
                                    <span v-if="q.id === activeCurrentId" class="flex-none rounded-full border border-primary/50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-primary">Current</span>
                                    <span v-else-if="assignedLabels.has(q.id)" class="flex-none whitespace-nowrap rounded-full border border-primary/50 px-2 py-0.5 text-[10px] font-semibold text-primary">{{ assignedLabels.get(q.id) }}</span>
                                    <span class="flex-none whitespace-nowrap text-xs text-subtle">{{ q.answers_count }} ans</span>
                                    <span class="flex-none whitespace-nowrap text-xs text-muted" title="Times used in completed games">{{ q.times_used }}× used</span>
                                    <span v-if="seenBy(q.id)" class="flex-none whitespace-nowrap rounded-full border border-warning/50 bg-warning/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-warning" :title="seenBy(q.id) + ' of tonight\'s players have already been asked this'">Seen · {{ seenBy(q.id) }}</span>
                                </span>
                            </button>
                            <p v-if="!hasActiveSlot" class="px-3 py-8 text-center text-sm text-muted">Select a slot, then pick its question here.</p>
                            <p v-else-if="!activeList.length" class="px-3 py-6 text-center text-sm text-muted">No matching questions.</p>
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex items-center gap-2 border-t border-border pt-4 text-sm">
                    <Link :href="route('questions.index')" class="font-semibold text-primary hover:underline">Manage question library →</Link>
                    <span class="text-subtle">Add or edit questions</span>
                </div>
            </Card>

            <!-- Oodles rules -->
            <Card v-if="gameSlug === 'oodles'" title="Rules">
                <div class="grid max-w-md grid-cols-2 gap-4">
                    <NumberInput v-model="settingsForm.settings.cards_per_game" label="Cards per game" :min="1" :max="26" />
                    <NumberInput v-model="settingsForm.settings.control_timer_seconds" label="Timer (s)" :min="5" :max="120" />
                </div>
                <div class="mt-5 flex items-center gap-2 border-t border-border pt-4 text-sm">
                    <Link :href="route('questions.index')" class="font-semibold text-primary hover:underline">Manage question library →</Link>
                    <span class="text-subtle">Oodles cards are pulled at random. Add or edit questions.</span>
                </div>
            </Card>

        </div>

        <!-- Add member modal -->
        <Modal :show="showAddMemberModal" max-width="md" @close="closeAddMemberModal">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-body">Add team member</h3>
                <div class="mb-4 flex border-b border-border">
                    <button :class="['-mb-px border-b-2 px-4 py-2 text-sm font-medium', addMemberType === 'guest' ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-body']" @click="addMemberType = 'guest'">Guest</button>
                    <button :class="['-mb-px border-b-2 px-4 py-2 text-sm font-medium', addMemberType === 'friend' ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-body']" @click="addMemberType = 'friend'">Friends</button>
                    <button v-if="waitingPlayers.length" :class="['-mb-px border-b-2 px-4 py-2 text-sm font-medium', addMemberType === 'waiting' ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-body']" @click="addMemberType = 'waiting'">Joined ({{ waitingPlayers.length }})</button>
                </div>

                <TextField v-if="addMemberType === 'guest'" v-model="guestNameInput" label="Guest name" placeholder="Enter a name" />
                <div v-else-if="addMemberType === 'friend'" class="space-y-2">
                    <div v-if="!availableFriends.length" class="py-4 text-center text-muted">No friends available.</div>
                    <div v-for="friend in availableFriends" :key="friend.id" :class="['cursor-pointer rounded-lg border p-3', selectedFriendId === friend.id ? 'border-primary bg-primary/10' : 'border-border hover:border-border-strong']" @click="selectedFriendId = friend.id">
                        <div class="font-medium text-body">{{ friend.nickname || friend.name }}</div>
                        <div v-if="friend.nickname" class="text-sm text-muted">{{ friend.name }}</div>
                    </div>
                </div>
                <div v-else class="space-y-2">
                    <div v-for="player in waitingPlayers" :key="player.id" :class="['cursor-pointer rounded-lg border p-3', selectedWaitingPlayerId === player.id ? 'border-primary bg-primary/10' : 'border-border hover:border-border-strong']" @click="selectedWaitingPlayerId = player.id">
                        <div class="font-medium text-body">{{ player.display_name }}</div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <Button variant="ghost" size="md" @click="closeAddMemberModal">Cancel</Button>
                    <Button variant="primary" size="md" :disabled="addMemberDisabled" @click="addTeamMember">Add</Button>
                </div>
            </div>
        </Modal>

        <!-- Confirmations -->
        <Confirm
            :show="confirmState.show"
            :title="confirmState.title"
            :message="confirmState.message"
            :confirm-text="confirmState.confirmText"
            variant="danger"
            @confirm="runConfirm"
            @cancel="confirmState.show = false"
            @close="confirmState.show = false"
        />
    </StandardLayout>
</template>
