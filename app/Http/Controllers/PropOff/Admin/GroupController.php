<?php

namespace App\Http\Controllers\PropOff\Admin;

use App\Http\Controllers\Controller;
use App\Models\PropOff\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GroupController extends Controller
{
    /**
     * Display a listing of groups.
     */
    public function index(Request $request)
    {
        $query = Group::withCount(['users', 'entries']);

        // Search
        if ($request->has('search') && $request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%");
            });
        }

        $groups = $query->latest()->paginate(20);

        return Inertia::render('PropOff/Admin/Groups/Index', [
            'groups' => $groups,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Show the form for creating a new group.
     */
    public function create()
    {
        $events = \App\Models\PropOff\Event::select('id', 'name', 'event_date', 'status')
            ->orderBy('event_date', 'desc')
            ->get();

        return Inertia::render('PropOff/Admin/Groups/GroupForm', [
            'events' => $events,
        ]);
    }

    /**
     * Store a newly created group in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'event_id' => 'required|exists:propoff_events,id',
            'grading_source' => 'required|in:captain,admin',
        ]);

        $group = Group::create([
            'name' => $validated['name'],
            'event_id' => $validated['event_id'],
            'grading_source' => $validated['grading_source'],
            'code' => $this->generateUniqueCode(),
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('propoff.admin.groups.show', $group->id)
            ->with('success', 'Group created successfully!');
    }

    /**
     * Generate a unique group code.
     */
    private function generateUniqueCode()
    {
        do {
            $code = strtoupper(substr(md5(uniqid()), 0, 6));
        } while (Group::where('code', $code)->exists());

        return $code;
    }

    /**
     * Display the specified group.
     */
    public function show(Group $group)
    {
        $group->load(['users']);
        $group->loadCount(['entries', 'users']);

        // Get group statistics
        $stats = [
            'total_members' => $group->users()->count(),
            'total_entries' => $group->entries()->count(),
            'entries_with_answers' => $group->entries()->whereHas('userAnswers')->count(),
            'average_score' => $group->entries()->whereHas('userAnswers')->avg('percentage') ?? 0,
            'best_score' => $group->entries()->whereHas('userAnswers')->max('percentage') ?? 0,
            'total_events_played' => $group->entries()->distinct('event_id')->count('event_id'),
        ];

        // Get recent entries
        $recentEntries = $group->entries()
            ->with(['event', 'user'])
            ->withCount('userAnswers as answered_count')
            ->whereHas('userAnswers')
            ->latest('updated_at')
            ->limit(10)
            ->get();

        // Get leaderboard positions
        $leaderboardPositions = \App\Models\PropOff\Leaderboard::where('group_id', $group->id)
            ->with(['event', 'user'])
            ->orderBy('rank')
            ->limit(10)
            ->get();

        return Inertia::render('PropOff/Admin/Groups/Show', [
            'group' => $group,
            'stats' => $stats,
            'recentEntries' => $recentEntries,
            'leaderboardPositions' => $leaderboardPositions,
        ]);
    }

    /**
     * Show the form for editing the specified group.
     */
    public function edit(Group $group)
    {
        return Inertia::render('PropOff/Admin/Groups/GroupForm', [
            'group' => $group,
        ]);
    }

    /**
     * Update the specified group.
     */
    public function update(Request $request, Group $group)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:propoff_groups,code,' . $group->id . '|regex:/^[A-Z0-9-]+$/',
        ]);

        $group->update($validated);

        return redirect()->route('propoff.admin.groups.show', $group->id)
            ->with('success', 'Group updated successfully!');
    }

    /**
     * Delete the specified group.
     */
    public function destroy(Group $group)
    {
        $groupName = $group->name;
        $group->delete();

        return redirect()->route('propoff.admin.groups.index')
            ->with('success', "Group '{$groupName}' deleted successfully!");
    }

    /**
     * Add a user to the group.
     */
    public function addUser(Request $request, Group $group)
    {
        $validated = $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            return back()->with('error', 'User not found with that email address.');
        }

        // Check if user is already in the group
        if ($group->members()->where('users.id', $user->id)->exists()) {
            return back()->with('error', 'User is already a member of this group!');
        }

        $group->members()->attach($user->id, [
            'joined_at' => now(),
        ]);

        return back()->with('success', "User '{$user->name}' added to group successfully!");
    }

    /**
     * Remove a user from the group.
     */
    public function removeUser(Request $request, Group $group, User $user)
    {
        // Check if user is in the group
        if (!$group->users()->where('users.id', $user->id)->exists()) {
            return back()->with('error', 'User is not a member of this group!');
        }

        $group->users()->detach($user->id);

        return back()->with('success', "User '{$user->name}' removed from group successfully!");
    }

    /**
     * Export groups to CSV.
     */
    public function exportCSV(Request $request)
    {
        $query = Group::withCount(['users', 'entries']);

        // Apply filters
        if ($request->has('search') && $request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%");
            });
        }

        $groups = $query->get();

        $filename = "groups_" . now()->format('Y-m-d_His') . ".csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($groups) {
            $file = fopen('php://output', 'w');

            // Headers
            fputcsv($file, [
                'ID',
                'Name',
                'Code',
                'Total Members',
                'Total Entries',
                'Created At',
            ]);

            // Data rows
            foreach ($groups as $group) {
                fputcsv($file, [
                    $group->id,
                    $group->name,
                    $group->code,
                    $group->users_count,
                    $group->entries_count,
                    $group->created_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }



    /**
     * Bulk delete groups.
     */
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'group_ids' => 'required|array',
            'group_ids.*' => 'exists:propoff_groups,id',
        ]);

        $count = Group::whereIn('id', $validated['group_ids'])->delete();

        return back()->with('success', "{$count} groups deleted successfully!");
    }
}
