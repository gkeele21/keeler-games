<?php

namespace App\Http\Controllers\PropOff\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request)
    {
        $query = User::withCount(['propoffEntries as entries_count', 'propoffGroups as groups_count']);

        // Filter by role
        if ($request->has('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('first_name', 'like', "%{$request->search}%")
                    ->orWhere('last_name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        $users = $query->latest()->paginate(20);

        return Inertia::render('PropOff/Admin/Users/Index', [
            'users' => $users,
            'filters' => $request->only(['role', 'search']),
        ]);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user)
    {
        $user->load([
            'propoffGroups' => function ($query) {
                $query->withCount('users');
            },
        ]);
        // The page expects a `groups` key.
        $user->setRelation('groups', $user->propoffGroups);
        $user->unsetRelation('propoffGroups');

        $user->loadCount(['propoffEntries as entries_count', 'propoffGroups as groups_count']);

        // Get user statistics
        $stats = [
            'total_entries' => $user->propoffEntries()->count(),
            'entries_with_answers' => $user->propoffEntries()->whereHas('userAnswers')->count(),
            'groups_joined' => $user->propoffGroups()->count(),
            'average_score' => $user->propoffEntries()->whereHas('userAnswers')->avg('percentage') ?? 0,
            'best_score' => $user->propoffEntries()->whereHas('userAnswers')->max('percentage') ?? 0,
            'total_points' => $user->propoffEntries()->whereHas('userAnswers')->sum('total_score'),
        ];

        // Get recent entries
        $recentEntries = $user->propoffEntries()
            ->with(['event', 'group'])
            ->withCount('userAnswers as answered_count')
            ->whereHas('userAnswers')
            ->latest('updated_at')
            ->limit(10)
            ->get();

        // Get leaderboard positions
        $leaderboardPositions = \App\Models\PropOff\Leaderboard::where('user_id', $user->id)
            ->with(['event', 'group'])
            ->orderBy('rank')
            ->limit(10)
            ->get();

        return Inertia::render('PropOff/Admin/Users/Show', [
            'user' => $user,
            'stats' => $stats,
            'recentEntries' => $recentEntries,
            'leaderboardPositions' => $leaderboardPositions,
        ]);
    }

    /**
     * Update user role.
     */
    public function updateRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role' => 'required|in:manager,admin,user',
        ]);

        // Prevent demoting yourself
        if ($user->id === auth()->id() && $validated['role'] !== auth()->user()->role) {
            return back()->with('error', 'You cannot change your own role!');
        }

        $user->update(['role' => $validated['role']]);

        return back()->with('success', "User role updated to {$validated['role']}!");
    }

    /**
     * Delete user account.
     */
    public function destroy(User $user)
    {
        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete yourself!');
        }

        $userName = $user->name;
        $user->delete();

        return redirect()->route('propoff.admin.users.index')
            ->with('success', "User '{$userName}' deleted successfully!");
    }


    /**
     * Export users to CSV.
     */
    public function exportCSV(Request $request)
    {
        $query = User::withCount(['propoffEntries as entries_count', 'propoffGroups as groups_count']);

        // Apply filters
        if ($request->has('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        $users = $query->get();

        $filename = "users_" . now()->format('Y-m-d_His') . ".csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($users) {
            $file = fopen('php://output', 'w');

            // Headers
            fputcsv($file, [
                'ID',
                'Name',
                'Email',
                'Role',
                'Total Entries',
                'Total Groups',
                'Created At',
                'Email Verified At',
            ]);

            // Data rows
            foreach ($users as $user) {
                fputcsv($file, [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->role,
                    $user->entries_count,
                    $user->groups_count,
                    $user->created_at->format('Y-m-d H:i:s'),
                    $user->email_verified_at ? $user->email_verified_at->format('Y-m-d H:i:s') : 'Not Verified',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Bulk delete users.
     */
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        // Prevent deleting yourself
        if (in_array(auth()->id(), $validated['user_ids'])) {
            return back()->with('error', 'You cannot delete yourself!');
        }

        $count = User::whereIn('id', $validated['user_ids'])->delete();

        return back()->with('success', "{$count} users deleted successfully!");
    }

}
