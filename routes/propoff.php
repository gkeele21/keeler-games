<?php

use App\Http\Controllers\PropOff\Admin\CaptainInvitationController as AdminCaptainInvitationController;
use App\Http\Controllers\PropOff\Admin\EventAnswerController as AdminEventAnswerController;
use App\Http\Controllers\PropOff\Admin\EventController as AdminEventController;
use App\Http\Controllers\PropOff\Admin\EventQuestionController as AdminEventQuestionController;
use App\Http\Controllers\PropOff\Admin\GradingController as AdminGradingController;
use App\Http\Controllers\PropOff\Admin\GroupController as AdminGroupController;
use App\Http\Controllers\PropOff\Admin\QuestionTemplateController;
use App\Http\Controllers\PropOff\Admin\UserController as AdminUserController;
use App\Http\Controllers\PropOff\Captain\GradingController as CaptainGradingController;
use App\Http\Controllers\PropOff\Captain\GroupController as CaptainGroupController;
use App\Http\Controllers\PropOff\Captain\GroupQuestionController;
use App\Http\Controllers\PropOff\Captain\InvitationController;
use App\Http\Controllers\PropOff\Captain\MemberController;
use App\Http\Controllers\PropOff\GroupController;
use App\Http\Controllers\PropOff\GroupPickerController;
use App\Http\Controllers\PropOff\GuestController;
use App\Http\Controllers\PropOff\HistoryController;
use App\Http\Controllers\PropOff\PlayController;
use App\Http\Middleware\EnsureIsCaptainOfGroup;
use App\Http\Middleware\GuestCookieMiddleware;
use App\Services\PropOff\SmartRoutingService;
use Illuminate\Support\Facades\Route;

Route::prefix('propoff')->name('propoff.')->group(function () {
    // ---- Guest / passwordless (public) ------------------------------------
    Route::get('join/{token}', [GuestController::class, 'show'])->name('guest.join');
    Route::post('join/{token}', [GuestController::class, 'register'])->name('guest.register');
    Route::get('guest/{guestToken}', [GuestController::class, 'login'])->name('guest.login');
    Route::post('guest/forget', function () {
        if (auth()->check() && auth()->user()->isGuest()) {
            auth()->logout();
        }

        return redirect('/')->withoutCookie(GuestCookieMiddleware::COOKIE);
    })->name('guest.forget');

    // ---- Play (public — guest cookie / join flow) --------------------------
    Route::prefix('play/{code}')->group(function () {
        Route::get('/', [PlayController::class, 'hub'])->name('play.hub');
        Route::get('join', [PlayController::class, 'joinForm'])->name('play.join');
        Route::post('join', [PlayController::class, 'processJoin'])->name('play.join.process');
        Route::get('game', [PlayController::class, 'questions'])->name('play.game');
        Route::post('save', [PlayController::class, 'saveAnswers'])->name('play.save');
        Route::get('leaderboard', [PlayController::class, 'leaderboard'])->name('play.leaderboard');
    });

    // ---- Captain invitation flow (guest accessible) -------------------------
    Route::prefix('captain')->name('captain.')->group(function () {
        Route::get('join/{token}', [CaptainGroupController::class, 'join'])->name('join');
        Route::get('events/{event}/create-group/{token}', [CaptainGroupController::class, 'create'])->name('groups.create');
        Route::post('events/{event}/create-group/{token}', [CaptainGroupController::class, 'store'])->name('groups.store');
    });

    // ---- Authenticated member area -----------------------------------------
    Route::middleware('auth')->group(function () {
        // Module home: smart redirect by active-group count.
        Route::get('/', function (SmartRoutingService $routing) {
            return redirect($routing->getRedirectForUser(auth()->user()));
        })->name('home');

        Route::get('selector', [GroupPickerController::class, 'index'])->name('selector');
        Route::get('history', [HistoryController::class, 'index'])->name('history');

        Route::resource('groups', GroupController::class)->except(['show']);
        Route::get('groups/{group}/questions', [GroupController::class, 'show'])->name('groups.questions');
        Route::post('groups/{group}/leave', [GroupController::class, 'leave'])->name('groups.leave');

        // Captain-only management of a specific group
        Route::middleware(EnsureIsCaptainOfGroup::class)->group(function () {
            Route::post('groups/{group}/questions', [GroupQuestionController::class, 'store'])->name('groups.questions.store');
            Route::patch('groups/{group}/questions/{groupQuestion}', [GroupQuestionController::class, 'update'])->name('groups.questions.update');
            Route::delete('groups/{group}/questions/{groupQuestion}', [GroupQuestionController::class, 'destroy'])->name('groups.questions.destroy');
            Route::post('groups/{group}/questions/{groupQuestion}/toggle-active', [GroupQuestionController::class, 'toggleActive'])->name('groups.questions.toggleActive');
            Route::post('groups/{group}/questions/{groupQuestion}/duplicate', [GroupQuestionController::class, 'duplicate'])->name('groups.questions.duplicate');
            Route::post('groups/{group}/questions/reorder', [GroupQuestionController::class, 'reorder'])->name('groups.questions.reorder');

            Route::post('groups/{group}/questions/{groupQuestion}/set-answer', [CaptainGradingController::class, 'setAnswer'])->name('groups.grading.setAnswer');
            Route::post('groups/{group}/questions/{groupQuestion}/toggle-void', [CaptainGradingController::class, 'toggleVoid'])->name('groups.grading.toggleVoid');

            Route::get('groups/{group}/members', [MemberController::class, 'index'])->name('groups.members.index');
            Route::post('groups/{group}/members/{user}/promote', [MemberController::class, 'promoteToCaptain'])->name('groups.members.promote');
            Route::post('groups/{group}/members/{user}/demote', [MemberController::class, 'demoteFromCaptain'])->name('groups.members.demote');
            Route::delete('groups/{group}/members/{user}', [MemberController::class, 'remove'])->name('groups.members.remove');
            Route::post('groups/{group}/regenerate-join-code', [MemberController::class, 'regenerateJoinCode'])->name('groups.members.regenerateJoinCode');
            Route::post('groups/{group}/members/add-guest', [MemberController::class, 'addGuest'])->name('groups.members.addGuest');
            Route::post('groups/{group}/members/{user}/separate', [MemberController::class, 'separate'])->name('groups.members.separate');

            Route::get('groups/{group}/invitation', [InvitationController::class, 'show'])->name('groups.invitation');
            Route::post('groups/{group}/invitation/regenerate', [InvitationController::class, 'regenerate'])->name('groups.invitation.regenerate');
            Route::post('groups/{group}/invitation/toggle', [InvitationController::class, 'toggle'])->name('groups.invitation.toggle');
            Route::patch('groups/{group}/invitation', [InvitationController::class, 'update'])->name('groups.invitation.update');

            Route::post('groups/{group}/toggle-lock', [GroupController::class, 'toggleLock'])->name('groups.toggle-lock');
        });
    });

    // ---- Admin (admin + manager roles) --------------------------------------
    Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
        Route::get('dashboard', fn () => redirect()->route('propoff.admin.events.index'))->name('dashboard');

        Route::resource('events', AdminEventController::class);
        Route::post('events/{event}/update-status', [AdminEventController::class, 'updateStatus'])->name('events.updateStatus');
        Route::post('events/{event}/duplicate', [AdminEventController::class, 'duplicate'])->name('events.duplicate');
        Route::get('events/{event}/statistics', [AdminEventController::class, 'statistics'])->name('events.statistics');

        Route::post('events/{event}/generate-invitation', [AdminEventController::class, 'generateInvitation'])->name('events.generateInvitation');
        Route::post('events/{event}/invitations/{invitation}/deactivate', [AdminEventController::class, 'deactivateInvitation'])->name('events.deactivateInvitation');

        Route::get('events/{event}/import-questions', [AdminEventQuestionController::class, 'importQuestions'])->name('events.import-questions');
        Route::get('events/{event}/event-questions/search-templates', [AdminEventQuestionController::class, 'searchTemplates'])->name('events.event-questions.searchTemplates');
        Route::post('events/{event}/event-questions', [AdminEventQuestionController::class, 'store'])->name('events.event-questions.store');
        Route::post('events/{event}/event-questions/template/{template}', [AdminEventQuestionController::class, 'createFromTemplate'])->name('events.event-questions.createFromTemplate');
        Route::post('events/{event}/event-questions/bulk-create-from-templates', [AdminEventQuestionController::class, 'bulkCreateFromTemplates'])->name('events.event-questions.bulkCreateFromTemplates');
        Route::patch('events/{event}/event-questions/{eventQuestion}', [AdminEventQuestionController::class, 'update'])->name('events.event-questions.update');
        Route::delete('events/{event}/event-questions/{eventQuestion}', [AdminEventQuestionController::class, 'destroy'])->name('events.event-questions.destroy');
        Route::delete('events/{event}/event-questions', [AdminEventQuestionController::class, 'destroyAll'])->name('events.event-questions.destroyAll');
        Route::post('events/{event}/event-questions/reorder', [AdminEventQuestionController::class, 'reorder'])->name('events.event-questions.reorder');
        Route::post('events/{event}/event-questions/{eventQuestion}/duplicate', [AdminEventQuestionController::class, 'duplicate'])->name('events.event-questions.duplicate');
        Route::post('events/{event}/event-questions/bulk-import', [AdminEventQuestionController::class, 'bulkImport'])->name('events.event-questions.bulkImport');
        Route::post('events/{event}/questions/{eventQuestion}/set-answer', [AdminEventQuestionController::class, 'setAnswer'])->name('events.questions.set-answer');

        Route::get('events/{event}/grading', [AdminGradingController::class, 'index'])->name('events.grading.index');
        Route::post('events/{event}/event-questions/{eventQuestion}/set-answer', [AdminGradingController::class, 'setAnswer'])->name('events.grading.setAnswer');
        Route::post('events/{event}/groups/{group}/bulk-set-answers', [AdminGradingController::class, 'bulkSetAnswers'])->name('events.grading.bulkSetAnswers');
        Route::post('events/{event}/event-questions/{eventQuestion}/groups/{group}/toggle-void', [AdminGradingController::class, 'toggleVoid'])->name('events.grading.toggleVoid');
        Route::post('events/{event}/calculate-scores', [AdminGradingController::class, 'calculateScores'])->name('events.grading.calculateScores');
        Route::get('events/{event}/export-csv', [AdminGradingController::class, 'exportCSV'])->name('events.grading.exportCSV');
        Route::get('events/{event}/export-detailed-csv', [AdminGradingController::class, 'exportDetailedCSV'])->name('events.grading.exportDetailedCSV');
        Route::get('events/{event}/groups/{group}/export-detailed-csv', [AdminGradingController::class, 'exportDetailedCSV'])->name('events.grading.exportDetailedCSVByGroup');
        Route::get('events/{event}/groups/{group}/summary', [AdminGradingController::class, 'groupSummary'])->name('events.grading.groupSummary');

        Route::get('events/{event}/captain-invitations', [AdminCaptainInvitationController::class, 'index'])->name('events.captain-invitations.index');
        Route::post('events/{event}/captain-invitations', [AdminCaptainInvitationController::class, 'store'])->name('events.captain-invitations.store');
        Route::get('events/{event}/captain-invitations/{invitation}', [AdminCaptainInvitationController::class, 'show'])->name('events.captain-invitations.show');
        Route::patch('events/{event}/captain-invitations/{invitation}', [AdminCaptainInvitationController::class, 'update'])->name('events.captain-invitations.update');
        Route::delete('events/{event}/captain-invitations/{invitation}', [AdminCaptainInvitationController::class, 'destroy'])->name('events.captain-invitations.destroy');
        Route::post('events/{event}/captain-invitations/{invitation}/deactivate', [AdminCaptainInvitationController::class, 'deactivate'])->name('events.captain-invitations.deactivate');
        Route::post('events/{event}/captain-invitations/{invitation}/reactivate', [AdminCaptainInvitationController::class, 'reactivate'])->name('events.captain-invitations.reactivate');
        Route::post('events/{event}/generate-captain-invitation', [AdminEventController::class, 'generateCaptainInvitation'])->name('events.generateCaptainInvitation');
        Route::post('events/{event}/create-my-group', [AdminCaptainInvitationController::class, 'createMyGroup'])->name('events.createMyGroup');

        Route::post('events/{event}/event-questions/{eventQuestion}/toggle-event-void', [AdminEventAnswerController::class, 'toggleVoid'])->name('events.event-answers.toggleVoid');

        // Manager-only
        Route::middleware('manager')->group(function () {
            Route::resource('question-templates', QuestionTemplateController::class);
            Route::post('question-templates/reorder', [QuestionTemplateController::class, 'reorder'])->name('question-templates.reorder');
            Route::post('question-templates/{template}/preview', [QuestionTemplateController::class, 'preview'])->name('question-templates.preview');
            Route::post('question-templates/{template}/duplicate', [QuestionTemplateController::class, 'duplicate'])->name('question-templates.duplicate');

            Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('users/export/csv', [AdminUserController::class, 'exportCSV'])->name('users.exportCSV');
            Route::post('users/bulk-delete', [AdminUserController::class, 'bulkDelete'])->name('users.bulkDelete');
            Route::get('users-statistics', [AdminUserController::class, 'statistics'])->name('users.statistics');
            Route::get('users/{user}', [AdminUserController::class, 'show'])->name('users.show');
            Route::post('users/{user}/update-role', [AdminUserController::class, 'updateRole'])->name('users.updateRole');
            Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
            Route::get('users/{user}/activity', [AdminUserController::class, 'activity'])->name('users.activity');

            Route::get('groups', [AdminGroupController::class, 'index'])->name('groups.index');
            Route::get('groups/create', [AdminGroupController::class, 'create'])->name('groups.create');
            Route::post('groups', [AdminGroupController::class, 'store'])->name('groups.store');
            Route::get('groups/export/csv', [AdminGroupController::class, 'exportCSV'])->name('groups.exportCSV');
            Route::get('groups-statistics', [AdminGroupController::class, 'statistics'])->name('groups.statistics');
            Route::post('groups/bulk-delete', [AdminGroupController::class, 'bulkDelete'])->name('groups.bulkDelete');
            Route::get('groups/{group}', [AdminGroupController::class, 'show'])->name('groups.show');
            Route::get('groups/{group}/edit', [AdminGroupController::class, 'edit'])->name('groups.edit');
            Route::patch('groups/{group}', [AdminGroupController::class, 'update'])->name('groups.update');
            Route::delete('groups/{group}', [AdminGroupController::class, 'destroy'])->name('groups.destroy');
            Route::post('groups/{group}/add-user', [AdminGroupController::class, 'addUser'])->name('groups.addUser');
            Route::delete('groups/{group}/users/{user}', [AdminGroupController::class, 'removeUser'])->name('groups.removeUser');
            Route::get('groups/{group}/members', [AdminGroupController::class, 'members'])->name('groups.members');
        });
    });
});
