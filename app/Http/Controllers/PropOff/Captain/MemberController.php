<?php

namespace App\Http\Controllers\PropOff\Captain;

use App\Http\Controllers\Controller;
use App\Models\PropOff\Group;
use App\Models\PropOff\Leaderboard;
use App\Models\User;
use App\Services\PropOff\ParticipantResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MemberController extends Controller
{
    public function __construct(private ParticipantResolver $resolver)
    {
    }

    /**
     * Display a listing of group members.
     */
    public function index(Request $request, Group $group)
    {
        $members = $group->members()
            ->withPivot('is_captain', 'joined_at')
            ->orderByPivot('is_captain', 'desc')
            ->orderByPivot('joined_at', 'asc')
            ->get()
            ->map(function ($member) {
                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'is_captain' => $member->pivot->is_captain,
                    'is_guest' => $member->isGuest(),
                    'joined_at' => $member->pivot->joined_at,
                    'entries_count' => $member->propoffEntries()->where('group_id', $member->pivot->group_id)->count(),
                ];
            });

        return Inertia::render('PropOff/Groups/Members/Index', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'join_code' => $group->code,
                'event' => [
                    'id' => $group->event->id,
                    'name' => $group->event->name,
                ],
            ],
            'members' => $members,
        ]);
    }

    /**
     * Promote a member to captain.
     */
    public function promoteToCaptain(Request $request, Group $group, User $user)
    {
        // Check if user is a member of the group
        if (!$group->members()->where('user_id', $user->id)->exists()) {
            return back()->with('error', 'User is not a member of this group.');
        }

        // Check if already a captain
        if ($group->isCaptain($user->id)) {
            return back()->with('error', 'User is already a captain of this group.');
        }

        // Promote to captain
        $group->addCaptain($user);

        return back()->with('success', "{$user->name} has been promoted to captain!");
    }

    /**
     * Demote a captain to regular member.
     */
    public function demoteFromCaptain(Request $request, Group $group, User $user)
    {
        // Check if user is a captain
        if (!$group->isCaptain($user->id)) {
            return back()->with('error', 'User is not a captain of this group.');
        }

        // Check if this is the last captain
        if ($group->captains()->count() <= 1) {
            return back()->with('error', 'Cannot demote the last captain. Promote someone else first.');
        }

        // Demote from captain
        $group->removeCaptain($user);

        return back()->with('success', "{$user->name} has been demoted to regular member.");
    }

    /**
     * Remove a member from the group.
     */
    public function remove(Request $request, Group $group, User $user)
    {
        // Check if user is a member of the group
        if (!$group->members()->where('user_id', $user->id)->exists()) {
            return back()->with('error', 'User is not a member of this group.');
        }

        // Check if user is a captain
        $isCaptain = $group->isCaptain($user->id);

        // If captain, check if they're the last one
        if ($isCaptain && $group->captains()->count() <= 1) {
            return back()->with('error', 'Cannot remove the last captain. Promote someone else first or delete the group.');
        }

        // Delete any entries this user has in this group
        $entriesDeleted = $group->entries()->where('user_id', $user->id)->delete();

        // Delete leaderboard entry
        Leaderboard::where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->delete();

        // Remove from group
        $group->members()->detach($user->id);

        return back()->with('success', "{$user->name} has been removed from the group.");
    }

    /**
     * Regenerate the join code for the group.
     */
    public function regenerateJoinCode(Request $request, Group $group)
    {
        $group->update([
            'code' => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
        ]);

        return back()->with('success', 'Join code regenerated successfully!');
    }

    /**
     * Add a guest user to the group.
     */
    public function addGuest(Request $request, Group $group)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // The captain has seen the warning and means to add a second
            // person who genuinely shares the name.
            'allow_duplicate_name' => 'nullable|boolean',
        ]);

        $name = trim($validated['name']);

        // Adding someone already in the group is nearly always a slip. Say so
        // rather than silently creating a second row for the same person —
        // that is how "Megan" ended up in the data three times.
        if (empty($validated['allow_duplicate_name'])
            && $this->resolver->findCandidateInGroup($name, $group)) {
            return back()->withErrors([
                'name' => "{$name} is already in this group. Add them again only if this is a different person with the same name.",
            ]);
        }

        $guest = $this->resolver->createGuest($name);
        $this->resolver->attachTo($guest, $group);

        return back()->with('success', "Guest {$guest->name} added successfully!");
    }

    /**
     * Separate a member who claimed the wrong entry.
     *
     * The "is this you?" step lets someone confirm a name match, which is what
     * makes returning painless — but with several people sharing a name, an
     * occasional wrong press is inevitable. This gives the captain the way back:
     * the member gets a fresh identity for this group, taking this group's entry
     * and answers with them, and the original person keeps everything else,
     * including their previous years.
     */
    public function separate(Request $request, Group $group, User $user)
    {
        // A captain splitting themselves would strip the group's own captaincy.
        // Transferring the role first is the honest order of operations.
        if ($group->users()->where('users.id', $user->id)->wherePivot('is_captain', true)->exists()) {
            return back()->withErrors([
                'member' => 'Promote another captain before separating this one.',
            ]);
        }

        if (! $group->users()->where('users.id', $user->id)->exists()) {
            return back()->withErrors(['member' => 'That person is not in this group.']);
        }

        $this->resolver->separateFromGroup($user, $group);

        return back()->with(
            'success',
            "{$user->name} was separated — their answers here now belong to a new person, and the original entry keeps its history.",
        );
    }
}
