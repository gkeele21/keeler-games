<?php

namespace App\Http\Controllers\PropOff;

use App\Http\Controllers\Controller;

use App\Mail\GuestMagicLink;
use App\Models\PropOff\EventInvitation;
use App\Models\User;
use App\Services\PropOff\ParticipantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class GuestController extends Controller
{
    /**
     * Show the guest registration page.
     */
    public function show($token)
    {
        $invitation = EventInvitation::where('token', $token)
            ->with(['event', 'group'])
            ->firstOrFail();

        if (!$invitation->isValid()) {
            return Inertia::render('PropOff/Guest/InvitationExpired', [
                'message' => 'This invitation is no longer valid.',
            ]);
        }

        return Inertia::render('PropOff/Guest/Join', [
            'invitation' => [
                'token' => $invitation->token,
                'event' => [
                    'id' => $invitation->event->id,
                    'name' => $invitation->event->name,
                    'event_date' => $invitation->event->event_date,
                    'status' => $invitation->event->status,
                ],
                'group' => [
                    'id' => $invitation->group->id,
                    'name' => $invitation->group->name,
                ],
            ],
        ]);
    }

    /**
     * Register guest and auto-login.
     *
     * Looks for an existing person before creating one. This route carried 140
     * of the Super Bowl LX joins while creating a fresh user every time, which
     * is where most of the duplicate names came from — the same human
     * re-registering after losing their magic link.
     */
    public function register(Request $request, ParticipantResolver $resolver)
    {
        $token = $request->route('token');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'password' => 'nullable|string|min:8|confirmed',
            // Set when the person has confirmed they are the matched entry.
            'claim_user_id' => 'nullable|integer',
            // Set when they've seen the match and said it isn't them — a real
            // second person who happens to share the name.
            'allow_duplicate_name' => 'nullable|boolean',
        ]);

        $invitation = EventInvitation::where('token', $token)
            ->with(['event', 'group'])
            ->firstOrFail();

        if (!$invitation->isValid()) {
            return back()->withErrors(['token' => 'This invitation is no longer valid.']);
        }

        $group = $invitation->group;
        $name = trim($validated['name']);

        // 1. They confirmed a matched entry on the "is this you?" step. The
        //    match may have come from a previous year's group, so look the
        //    person up across the lineage rather than only this group —
        //    otherwise a returning player's claim silently falls through and
        //    they get a duplicate anyway.
        if (! empty($validated['claim_user_id'])) {
            $claimed = $this->claimableUser((int) $validated['claim_user_id'], $group);

            if ($claimed) {
                return $this->completeJoin($claimed, $invitation, $resolver);
            }
        }

        // 2. An email is an assertion of identity — reuse without asking.
        $existing = $resolver->findByEmail($validated['email'] ?? null);
        if ($existing) {
            if ($existing->name !== $name) {
                $existing->update(User::splitName($name));
            }

            return $this->completeJoin($existing, $invitation, $resolver);
        }

        // 3. A name match is only a candidate — two real people share a name
        //    often enough that merging them silently would be wrong. Ask, unless
        //    they've already told us it isn't them. Searches back through the
        //    groups this one continues from, since a new year's group is empty
        //    on the night and only the lineage can recognise a returning player.
        $candidate = empty($validated['allow_duplicate_name'])
            ? $resolver->findCandidateInLineage($name, $group)
            : null;
        if ($candidate) {
            return back()->with([
                'step'        => 'verify',
                'verifyEntry' => $resolver->candidateSummary(
                    $candidate['user'],
                    $candidate['group'],
                    $group,
                ),
            ]);
        }

        $user = $resolver->createGuest($name, $validated['email'] ?? null, $validated['password'] ?? null);
        $guestToken = $user->guest_token;

        return $this->completeJoin($user, $invitation, $resolver, $guestToken);
    }

    /**
     * A claim is only honoured for someone actually present in this group or one
     * it continues from — never an arbitrary user id posted from the client.
     */
    private function claimableUser(int $userId, \App\Models\PropOff\Group $group): ?User
    {
        foreach ($group->lineage() as $ancestor) {
            $user = $ancestor->users()->where('users.id', $userId)->first();

            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Shared tail of every successful join: group membership, invitation usage,
     * login, and the magic-link hand-off for password-less guests.
     */
    private function completeJoin(
        User $user,
        EventInvitation $invitation,
        ParticipantResolver $resolver,
        ?string $guestToken = null,
    ) {
        $resolver->attachTo($user, $invitation->group);

        // Increment invitation usage
        $invitation->incrementUsage();

        // Auto-login
        Auth::login($user);

        // Redirect to play hub
        session()->flash('success', 'Welcome! You can now play the event.');

        // Only show magic link if no password was set (guest token exists)
        if ($guestToken) {
            $magicLink = route('propoff.guest.login', ['guestToken' => $guestToken]);
            session()->flash('magic_link', $magicLink);
            session()->flash('show_magic_link', true);

            // Email the link so it survives the party — the on-screen copy is
            // gone the moment the phone is locked, which is exactly how the
            // Super Bowl LX guests lost theirs and re-registered.
            if ($user->email) {
                Mail::to($user->email)->send(
                    new GuestMagicLink($user, $invitation->event, $magicLink),
                );
            }
        }

        return \Inertia\Inertia::location(route('propoff.play.hub', ['code' => $invitation->group->code]));
    }

    /**
     * Auto-login guest user via guest token (magic link).
     */
    public function login($guestToken)
    {
        $user = User::where('guest_token', $guestToken)
            ->where('role', 'guest')
            ->with('propoffGroups')
            ->firstOrFail();

        // Auto-login
        Auth::login($user);

        // Get the user's first group to redirect to
        $group = $user->propoffGroups->first();
        if (!$group) {
            return redirect()->route('dashboard')
                ->with('error', 'No group found for this account.');
        }

        // Redirect to play hub with success message
        return redirect()->route('propoff.play.hub', ['code' => $group->code])
            ->with('success', 'Welcome back, ' . $user->name . '!');
    }

    /**
     * Show the guest login page (manual token entry).
     */
    public function showLoginForm()
    {
        return Inertia::render('PropOff/Guest/Login');
    }

    /**
     * Handle guest login form submission (manual token entry).
     */
    public function loginWithToken(Request $request)
    {
        $request->validate([
            'guest_token' => 'required|string|size:32',
        ]);

        $user = User::where('guest_token', $request->guest_token)
            ->where('role', 'guest')
            ->with('propoffGroups')
            ->first();

        if (!$user) {
            return back()->withErrors([
                'guest_token' => 'Invalid guest token. Please check your token and try again.',
            ]);
        }

        // Auto-login
        Auth::login($user);

        // Get the user's first group to redirect to
        $group = $user->propoffGroups->first();
        if (!$group) {
            return redirect()->route('dashboard')
                ->with('error', 'No group found for this account.');
        }

        // Redirect to play hub with success message
        return redirect()->route('propoff.play.hub', ['code' => $group->code])
            ->with('success', 'Welcome back, ' . $user->name . '!');
    }

}
