<?php

namespace App\Http\Controllers\PropOff\Captain;

use App\Http\Controllers\Controller;
use App\Http\Requests\PropOff\Captain\CreateGroupRequest;
use App\Models\PropOff\CaptainInvitation;
use App\Models\PropOff\Event;
use App\Models\PropOff\EventInvitation;
use App\Models\PropOff\Group;
use App\Models\PropOff\GroupQuestion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

class GroupController extends Controller
{
    /**
     * Join via captain invitation token (redirects to event-specific create page).
     */
    public function join(Request $request, string $token)
    {
        // Find the invitation by token
        $invitation = CaptainInvitation::where('token', $token)->firstOrFail();

        // Redirect to the event-specific create group page
        return redirect()->route('propoff.captain.groups.create', [
            'event' => $invitation->event_id,
            'token' => $token,
        ]);
    }

    /**
     * Show the form for creating a new group from captain invitation.
     */
    public function create(Request $request, Event $event, string $token)
    {
        // Find and validate the captain invitation
        $invitation = CaptainInvitation::where('event_id', $event->id)
            ->where('token', $token)
            ->firstOrFail();

        // Check if invitation can be used
        if (!$invitation->canBeUsed()) {
            return Inertia::render('PropOff/Captain/InvitationExpired', [
                'event' => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'event_date' => $event->event_date,
                ],
                'invitation' => [
                    'is_active' => $invitation->is_active,
                    'expires_at' => $invitation->expires_at,
                    'max_uses' => $invitation->max_uses,
                    'times_used' => $invitation->times_used,
                ],
            ]);
        }

        // Show create group form (works for both guests and authenticated users)
        return Inertia::render('PropOff/Captain/CreateGroup', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'event_date' => $event->event_date,
                'status' => $event->status,
            ],
            'invitation' => [
                'id' => $invitation->id,
                'token' => $invitation->token,
                'max_uses' => $invitation->max_uses,
                'times_used' => $invitation->times_used,
                'expires_at' => $invitation->expires_at,
            ],
            'isGuest' => !$request->user(),
        ]);
    }

    /**
     * Store a newly created group and make the user a captain.
     */
    public function store(CreateGroupRequest $request, Event $event, string $token)
    {
        // Find and validate the captain invitation
        $invitation = CaptainInvitation::where('event_id', $event->id)
            ->where('token', $token)
            ->firstOrFail();

        // Check if invitation can be used
        if (!$invitation->canBeUsed()) {
            return back()->with('error', 'This invitation has expired or reached its usage limit.');
        }

        // Get or create user
        $user = $request->user();

        if (!$user) {
            // Check if user with this email already exists (returning captain)
            if ($request->captain_email) {
                $existingUser = User::where('email', $request->captain_email)->first();

                if ($existingUser) {
                    // Use existing user - this is a returning captain!
                    $user = $existingUser;
                    // Update name if provided (in case they want to update it)
                    if ($request->captain_name && $request->captain_name !== $user->name) {
                        $user->update(['name' => $request->captain_name]);
                    }
                } else {
                    // Create new user - with password if provided, otherwise guest
                    $guestToken = $request->captain_password ? null : Str::random(32);
                    $user = User::create([
                        ...User::splitName($request->captain_name),
                        'email' => $request->captain_email,
                        'password' => $request->captain_password ? Hash::make($request->captain_password) : null,
                        'role' => 'guest',
                        'guest_token' => $guestToken,
                    ]);
                }
            } else {
                // No email provided - create anonymous guest
                $guestToken = Str::random(32);
                $user = User::create([
                    ...User::splitName($request->captain_name),
                    'email' => null,
                    'password' => null,
                    'role' => 'guest',
                    'guest_token' => $guestToken,
                ]);
            }

            // Auto-login
            \Auth::login($user);
        }

        // Create the group, continuing this captain's last one if they have
        // run a group before. See resolvePreviousGroup() for why this is
        // automatic rather than a form field.
        $group = Group::create([
            'event_id' => $event->id,
            'previous_group_id' => $this->resolvePreviousGroup($user, $event)?->id,
            'name' => $request->name,
            'description' => $request->description,
            'grading_source' => $request->grading_source,
            'entry_cutoff' => $request->entry_cutoff,
            'code' => Str::upper(Str::random(8)),
            'created_by' => $user->id,
        ]);

        // Add user to group as captain
        $group->members()->attach($user->id, [
            'is_captain' => true,
            'joined_at' => now(),
        ]);

        // Create group questions from event questions
        $eventQuestions = $event->eventQuestions()->orderBy('display_order')->get();

        foreach ($eventQuestions as $eventQuestion) {
            GroupQuestion::create([
                'group_id' => $group->id,
                'event_question_id' => $eventQuestion->id,
                'question_text' => $eventQuestion->question_text,
                'question_type' => $eventQuestion->question_type,
                'options' => $eventQuestion->options,
                'points' => $eventQuestion->points,
                'display_order' => $eventQuestion->display_order,
                'is_active' => true,
                'is_custom' => false,
            ]);
        }

        // Increment invitation usage
        $invitation->incrementUsage();

        // Auto-generate EventInvitation for this group so captain can invite members
        EventInvitation::create([
            'event_id' => $event->id,
            'group_id' => $group->id,
            'token' => EventInvitation::generateToken(),
            'max_uses' => null, // Unlimited uses
            'times_used' => 0,
            'expires_at' => null, // No expiration
            'is_active' => true,
        ]);

        // If we just created a guest user, force a full page redirect (not Inertia redirect)
        // to ensure authentication state is properly refreshed in the browser
        if ($request->captain_name) {
            session()->flash('success', 'Group created successfully! You are now a captain of this group.');

            // Only show magic link if user doesn't have a password (guest without login credentials)
            if ($user->guest_token) {
                $magicLink = route('propoff.guest.login', ['guestToken' => $user->guest_token]);
                session()->flash('magic_link', $magicLink);
                session()->flash('show_magic_link', true);
            }

            return Inertia::location(route('propoff.groups.index'));
        }

        return redirect()->route('propoff.groups.index')
            ->with('success', 'Group created successfully! You are now a captain of this group.');
    }

    /**
     * The group this captain most recently ran, for an earlier event — their
     * previous year's party.
     *
     * Automatic rather than a form field: the create form is rendered before we
     * know who the captain is (guest captains type their name on it), so there
     * is nobody to show a picker for. Getting it wrong is cheap — the link only
     * widens the "is this you?" search, so the worst case is being offered a
     * name from a group you were genuinely in, which you can decline.
     */
    private function resolvePreviousGroup(User $user, Event $event): ?Group
    {
        return Group::query()
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->whereHas('event', fn ($q) => $q->where('event_date', '<', $event->event_date))
            ->where('event_id', '!=', $event->id)
            ->join('propoff_events', 'propoff_events.id', '=', 'propoff_groups.event_id')
            ->orderByDesc('propoff_events.event_date')
            ->select('propoff_groups.*')
            ->first();
    }
}
