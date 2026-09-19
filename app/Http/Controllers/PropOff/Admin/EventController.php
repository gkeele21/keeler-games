<?php

namespace App\Http\Controllers\PropOff\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PropOff\StoreEventRequest;
use App\Http\Requests\PropOff\UpdateEventRequest;
use App\Models\PropOff\CaptainInvitation;
use App\Models\PropOff\Event;
use App\Models\PropOff\EventInvitation;
use App\Models\PropOff\Group;
use App\Services\PropOff\EntryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EventController extends Controller
{
    protected EntryService $entryService;

    public function __construct(EntryService $entryService)
    {
        $this->entryService = $entryService;
    }
    /**
     * Display a listing of events.
     */
    public function index(Request $request)
    {
        $query = Event::with('creator')
            ->withCount(['questions', 'entries']);

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
            });
        }

        $events = $query->latest()->paginate(15);

        return Inertia::render('PropOff/Admin/Events/Index', [
            'events' => $events,
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    /**
     * Show the form for creating a new event.
     */
    public function create()
    {
        return Inertia::render('PropOff/Admin/Events/EventForm');
    }

    /**
     * Store a newly created event in storage.
     */
    public function store(StoreEventRequest $request)
    {
        $event = Event::create([
            ...$request->validated(),
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('propoff.admin.events.show', $event)
            ->with('success', 'Event created successfully!');
    }

    /**
     * Display the specified event.
     */
    public function show(Event $event)
    {
        $event->load([
            'creator',
            'invitations.group',
            'groups',
        ]);

        $event->loadCount(['entries', 'eventQuestions']);

        // Calculate statistics
        $gradedCount = \App\Models\PropOff\EventAnswer::where('event_id', $event->id)
            ->where('is_void', false)
            ->count();

        $stats = [
            'total_questions' => $event->event_questions_count,
            'total_entries' => $event->entries_count,
            'completed_entries' => $event->entries()->where('is_complete', true)->count(),
            'average_score' => (float) ($event->entries()
                ->where('is_complete', true)
                ->avg('percentage') ?? 0),
            'participating_groups' => $event->groups()->count(),
            'graded_count' => $gradedCount,
            'total_points' => $this->entryService->calculateTheoreticalMaxForEvent($event),
        ];

        // Get questions with all necessary data
        $questions = $event->eventQuestions()
            ->with('eventAnswers')
            ->withCount(['groupQuestions as user_answers_count' => function ($query) {
                $query->whereHas('userAnswers');
            }])
            ->orderBy('display_order')
            ->get()
            ->map(function ($question) {
                $eventAnswer = $question->eventAnswers->first();
                return [
                    'id' => $question->id,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'options' => $question->options,
                    'points' => $question->points,
                    'display_order' => $question->display_order,
                    'correct_answer' => $eventAnswer?->correct_answer,
                    'is_void' => $eventAnswer?->is_void ?? false,
                    'user_answers_count' => $question->user_answers_count ?? 0,
                ];
            });

        // Get all groups for invitation generation
        $availableGroups = Group::all();

        return Inertia::render('PropOff/Admin/Events/Show', [
            'event' => $event,
            'questions' => $questions,
            'stats' => $stats,
            'invitations' => $event->invitations,
            'availableGroups' => $availableGroups,
        ]);
    }

    /**
     * Show the form for editing the specified event.
     */
    public function edit(Event $event)
    {
        return Inertia::render('PropOff/Admin/Events/EventForm', [
            'event' => $event,
        ]);
    }

    /**
     * Update the specified event in storage.
     */
    public function update(UpdateEventRequest $request, Event $event)
    {
        $event->update($request->validated());

        return redirect()->route('propoff.admin.events.show', $event)
            ->with('success', 'Event updated successfully!');
    }

    /**
     * Remove the specified event from storage.
     */
    public function destroy(Event $event)
    {
        $eventName = $event->name;
        $event->delete();

        return redirect()->route('propoff.admin.events.index')
            ->with('success', "Event '{$eventName}' deleted successfully!");
    }

    /**
     * Update event status.
     */
    public function updateStatus(Request $request, Event $event)
    {
        $request->validate([
            'status' => 'required|in:draft,open,locked,in_progress,completed',
        ]);

        $event->update(['status' => $request->status]);

        return back()->with('success', 'Event status updated successfully!');
    }

    /**
     * Duplicate an event.
     */
    public function duplicate(Event $event)
    {
        $newEvent = $event->replicate();
        $newEvent->name = $event->name . ' (Copy)';
        $newEvent->status = 'draft';
        $newEvent->created_by = auth()->id();
        $newEvent->save();

        // Duplicate event questions
        foreach ($event->eventQuestions as $eventQuestion) {
            $newEventQuestion = $eventQuestion->replicate();
            $newEventQuestion->event_id = $newEvent->id;
            $newEventQuestion->save();
        }

        return redirect()->route('propoff.admin.events.show', $newEvent)
            ->with('success', 'Event duplicated successfully!');
    }


    /**
     * Generate invitation for event-group combination.
     */
    public function generateInvitation(Request $request, Event $event)
    {
        $request->validate([
            'group_id' => 'required|exists:propoff_groups,id',
        ]);

        // Associate group with event if not already
        $group = Group::find($request->group_id);
        if (!$group->event_id) {
            $group->update(['event_id' => $event->id]);
        }

        // Check if invitation already exists
        $invitation = EventInvitation::where('event_id', $event->id)
            ->where('group_id', $request->group_id)
            ->first();

        if ($invitation) {
            // Reactivate if exists
            $invitation->update(['is_active' => true]);
        } else {
            // Create new invitation
            $invitation = EventInvitation::create([
                'event_id' => $event->id,
                'group_id' => $request->group_id,
                'token' => EventInvitation::generateToken(),
                'is_active' => true,
            ]);
        }

        return back()->with('success', 'Invitation link generated!');
    }

    /**
     * Deactivate invitation.
     */
    public function deactivateInvitation(Event $event, EventInvitation $invitation)
    {
        $invitation->update(['is_active' => false]);

        return back()->with('success', 'Invitation deactivated.');
    }

    /**
     * Generate captain invitation (quick method from event page).
     */
    public function generateCaptainInvitation(Request $request, Event $event)
    {
        $validated = $request->validate([
            'max_uses' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $invitation = CaptainInvitation::create([
            'event_id' => $event->id,
            'token' => CaptainInvitation::generateToken(),
            'max_uses' => $validated['max_uses'] ?? null,
            'times_used' => 0,
            'expires_at' => $validated['expires_at'] ?? null,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Captain invitation link generated!');
    }
}
