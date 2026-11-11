<?php

use App\Http\Controllers\ArticleAssetController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DealActivityController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\DeliverableController;
use App\Http\Controllers\DeliverableFileController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\ImpactController;
use App\Http\Controllers\InterviewAssetController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\InterviewMessageController;
use App\Http\Controllers\LeadCallController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadImportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TargetController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Take Care Connect Admin
|--------------------------------------------------------------------------
| Inertia routes, not a JSON API. Controllers return Inertia::render() and the
| React pages under resources/js/Pages mirror these paths.
|
| Three layers of protection, in order:
|
|   auth        signed in at all
|   active      the account has not been switched off since
|   2fa         a second factor, where the account or its role demands one
|   permission: the coarse "may this person open this module" question
|
| Policies then answer the finer per-record questions inside the controllers.
| The `permission:` string on a route is always the same string the sidebar
| filters on, so the menu and the router can never disagree.
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');

    // Throttled by the `login` limiter (per-IP and per-email+IP) registered in
    // AppServiceProvider; LoginRequest surfaces a lockout as a form error
    // rather than a bare 429.
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});

/*
| Signed in, but not yet past the second factor.
|
| These sit outside the `2fa` middleware by necessity — the challenge cannot be
| behind the thing it is the challenge for.
*/
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::prefix('two-factor')->name('two-factor.')->group(function () {
        Route::get('/setup', [TwoFactorController::class, 'setup'])->name('setup');
        Route::post('/setup', [TwoFactorController::class, 'confirm'])->name('confirm');

        Route::get('/challenge', [TwoFactorController::class, 'challenge'])->name('challenge');
        Route::post('/challenge', [TwoFactorController::class, 'verify'])
            ->middleware('throttle:6,1')
            ->name('verify');

        Route::delete('/', [TwoFactorController::class, 'destroy'])->name('destroy');
        Route::post('/abandon', [TwoFactorController::class, 'abandon'])->name('abandon');
    });
});

Route::middleware(['auth', 'active', '2fa'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Everybody has one, so it carries no permission of its own.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    /*
    | People.
    |
    | The route middleware answers "may this person open the accounts module".
    | UserPolicy answers the questions that depend on *which* account is being
    | acted on — nobody edits a Super Admin unless they are one, nobody deletes
    | or deactivates themselves, and the last Super Admin cannot be demoted.
    */
    Route::middleware('permission:users.view')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::patch('/users/{user}/active', [UserController::class, 'toggleActive'])->name('users.active');
        Route::delete('/users/{user}/two-factor', [UserController::class, 'resetTwoFactor'])->name('users.two-factor.reset');
    });

    /*
    | Interviews — the spine of the SOP.
    |
    | The route middleware answers "is this person in this line of work at all".
    | InterviewPolicy answers the rest: only the anchor on an interview may mark
    | it conducted, only a business interview can be handed over, and only one
    | that is not already finished can be edited or cancelled.
    */
    Route::middleware('permission:interviews.view|interviews.view.all')->group(function () {
        Route::get('/interviews', [InterviewController::class, 'index'])->name('interviews.index');
        Route::get('/interviews/my-queue', [InterviewController::class, 'myQueue'])->name('interviews.mine');

        // Registered before /interviews/{interview} so "create" is never read
        // as an interview id.
        Route::get('/interviews/create', [InterviewController::class, 'create'])
            ->middleware('permission:interviews.create')
            ->name('interviews.create');

        Route::post('/interviews', [InterviewController::class, 'store'])
            ->middleware('permission:interviews.create')
            ->name('interviews.store');

        Route::get('/interviews/{interview}', [InterviewController::class, 'show'])->name('interviews.show');
        Route::post('/interviews/{interview}/messages/{message}', [InterviewMessageController::class, 'store'])
            ->whereIn('message', InterviewMessageController::MESSAGES)
            ->name('interviews.messages');

        Route::post('/interviews/{interview}/conduct', [InterviewController::class, 'conduct'])->name('interviews.conduct');
        Route::post('/interviews/{interview}/handover', [InterviewController::class, 'handover'])->name('interviews.handover');
        Route::post('/interviews/{interview}/reassign', [InterviewController::class, 'reassign'])->name('interviews.reassign');
        Route::post('/interviews/{interview}/cancel', [InterviewController::class, 'cancel'])->name('interviews.cancel');
        Route::post('/interviews/{interview}/stage', [InterviewController::class, 'moveStage'])->name('interviews.stage');

        Route::post('/interviews/{interview}/assets', [InterviewAssetController::class, 'store'])
            ->middleware('throttle:uploads')
            ->name('interviews.assets.store');

        // Served through a controller, never from a public URL: the workfiles
        // disk holds unpublished material about real businesses.
        Route::get('/interview-assets/{asset}', [InterviewAssetController::class, 'download'])
            ->name('interviews.assets.download');

        Route::delete('/interview-assets/{asset}', [InterviewAssetController::class, 'destroy'])
            ->name('interviews.assets.destroy');
    });

    /*
    | Articles — the content desk.
    |
    | An article is created automatically when a business interview is handed
    | over. ArticlePolicy carries the rule that matters here: a writer cannot
    | approve their own work, and nothing can be published that did not pass a
    | review.
    */
    Route::middleware('permission:articles.view|articles.view.all')->group(function () {
        Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
        Route::get('/articles/queue', [ArticleController::class, 'queue'])->name('articles.queue');

        Route::get('/articles/{article}', [ArticleController::class, 'edit'])->name('articles.edit');
        Route::patch('/articles/{article}', [ArticleController::class, 'update'])->name('articles.update');

        Route::post('/articles/{article}/submit', [ArticleController::class, 'submit'])->name('articles.submit');
        Route::post('/articles/{article}/approve', [ArticleController::class, 'approve'])->name('articles.approve');
        Route::post('/articles/{article}/revisions', [ArticleController::class, 'requestRevisions'])->name('articles.revisions');
        Route::post('/articles/{article}/publish', [ArticleController::class, 'publish'])->name('articles.publish');
        Route::post('/articles/{article}/assign', [ArticleController::class, 'assign'])->name('articles.assign');

        // The publish-ready bundle: sanitised body, SEO fields, FAQ block and
        // the images, with a README saying where each one goes on the site.

        Route::post('/articles/{article}/assets', [ArticleAssetController::class, 'store'])
            ->middleware('throttle:uploads')
            ->name('articles.assets.store');

        Route::get('/article-assets/{asset}', [ArticleAssetController::class, 'download'])
            ->name('articles.assets.download');

        Route::delete('/article-assets/{asset}', [ArticleAssetController::class, 'destroy'])
            ->name('articles.assets.destroy');
    });

    /*
    | Creative - posters, reels and edits.
    |
    | A deliverable hangs off an interview OR an article, which is what gives the
    | two social-only interview kinds somewhere for their output to live.
    |
    | DeliverablePolicy keeps the hands apart: whoever makes a piece of work
    | cannot approve it, and approving is not the same permission as posting it.
    */
    Route::middleware('permission:deliverables.view|deliverables.view.all')->group(function () {
        Route::get('/deliverables', [DeliverableController::class, 'index'])->name('deliverables.index');
        Route::get('/deliverables/board', [DeliverableController::class, 'board'])->name('deliverables.board');

        Route::post('/deliverables', [DeliverableController::class, 'store'])
            ->middleware('permission:deliverables.create')
            ->name('deliverables.store');

        Route::get('/deliverables/{deliverable}', [DeliverableController::class, 'show'])->name('deliverables.show');
        Route::patch('/deliverables/{deliverable}', [DeliverableController::class, 'update'])->name('deliverables.update');

        Route::post('/deliverables/{deliverable}/submit', [DeliverableController::class, 'submit'])->name('deliverables.submit');
        Route::post('/deliverables/{deliverable}/approve', [DeliverableController::class, 'approve'])->name('deliverables.approve');
        Route::post('/deliverables/{deliverable}/reject', [DeliverableController::class, 'reject'])->name('deliverables.reject');
        Route::post('/deliverables/{deliverable}/publish', [DeliverableController::class, 'publish'])->name('deliverables.publish');
        Route::post('/deliverables/{deliverable}/links', [DeliverableController::class, 'recordLink'])->name('deliverables.links');
        Route::post('/deliverables/{deliverable}/assign', [DeliverableController::class, 'assign'])->name('deliverables.assign');

        Route::post('/deliverables/{deliverable}/files', [DeliverableFileController::class, 'store'])
            ->middleware('throttle:uploads')
            ->name('deliverables.files.store');

        Route::get('/deliverable-files/{file}', [DeliverableFileController::class, 'download'])
            ->name('deliverables.files.download');

        Route::get('/deliverable-files/{file}/preview', [DeliverableFileController::class, 'preview'])
            ->name('deliverables.files.preview');

        Route::delete('/deliverable-files/{file}', [DeliverableFileController::class, 'destroy'])
            ->name('deliverables.files.destroy');
    });

    /*
    | Sales - clients, the pipeline, targets and the chase list.
    |
    | A relationship manager works their own book: `clients.view` means the
    | clients they own, and only `clients.view.all` opens the whole directory.
    |
    | The three deal permissions are split on purpose. `deals.move` is separate
    | from `deals.update` so the board can be handed to somebody read-only, and
    | `deals.close` is separate again because it is the one action that writes
    | the revenue every attainment figure is a sum of.
    */
    /*
    | Notifications.
    |
    | No permission of their own: everybody has a bell, and what reaches it is
    | already decided by what work they are on.
    */
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
    Route::delete('/notifications', [NotificationController::class, 'clear'])->name('notifications.clear');

    Route::get('/reports', [ReportController::class, 'index'])
        ->middleware('permission:reports.view|reports.view.all')
        ->name('reports.index');

    /*
     | Per-person load and turnaround. Organisation-wide by definition, so it
     | sits behind `.all` rather than the own-work scope.
     */
    Route::get('/reports/performance', PerformanceController::class)
        ->middleware('permission:reports.view.all')
        ->name('reports.performance');

    // Organisation-wide, so the same gate as Performance.
    Route::middleware('permission:reports.view.all')->group(function () {
        Route::get('/reports/impact', ImpactController::class)->name('reports.impact');
        Route::get('/reports/impact/export', [ImpactController::class, 'export'])->name('reports.impact.export');
    });

    /*
     | Leads — the people a manager is talking to who are not clients yet.
     |
     | Ahead of clients in the file for the same reason it is ahead of them in
     | the sidebar: it is where the work starts.
     */
    Route::middleware('permission:leads.view|leads.view.all')->group(function () {
        Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');

        // Before /leads/{lead}, or the literal segments are read as ids.
        Route::get('/leads/create', [LeadController::class, 'create'])
            ->middleware('permission:leads.create')
            ->name('leads.create');

        Route::get('/leads/export', [LeadController::class, 'export'])
            ->middleware('permission:leads.export')
            ->name('leads.export');

        Route::middleware('permission:leads.import')->group(function () {
            Route::get('/leads/import', [LeadImportController::class, 'create'])->name('leads.import');
            Route::get('/leads/import/template', [LeadImportController::class, 'template'])->name('leads.import.template');
            Route::post('/leads/import', [LeadImportController::class, 'store'])->name('leads.import.store');
        });

        Route::post('/leads', [LeadController::class, 'store'])
            ->middleware('permission:leads.create')
            ->name('leads.store');

        Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');

        Route::middleware('permission:leads.update')->group(function () {
            Route::get('/leads/{lead}/edit', [LeadController::class, 'edit'])->name('leads.edit');
            Route::patch('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
            Route::patch('/leads/{lead}/status', [LeadController::class, 'status'])->name('leads.status');
            Route::post('/leads/{lead}/notes', [LeadController::class, 'addNote'])->name('leads.notes');
            Route::post('/leads/{lead}/calls', [LeadCallController::class, 'store'])->name('leads.calls');
        });

        Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])
            ->middleware('permission:leads.convert')
            ->name('leads.convert');

        Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])
            ->middleware('permission:leads.delete')
            ->name('leads.destroy');
    });

    Route::middleware('permission:clients.view|clients.view.all')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');

        // Registered before /clients/{client} so neither literal segment is
        // ever read as a client id.
        Route::get('/clients/create', [ClientController::class, 'create'])
            ->middleware('permission:clients.create')
            ->name('clients.create');

        Route::get('/clients/export', [ClientController::class, 'export'])
            ->middleware('permission:clients.export')
            ->name('clients.export');

        Route::post('/clients', [ClientController::class, 'store'])
            ->middleware('permission:clients.create')
            ->name('clients.store');

        Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
        Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
        Route::patch('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    });

    /*
     | Projects — what a client bought, and how much of it has shipped.
     |
     | Read is wider than write on purpose: an anchor holds `projects.view` so
     | they can open the package behind a booking, but no create or update.
     */
    Route::middleware('permission:projects.view|projects.view.all')->group(function () {
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');

        // Before /projects/{project}, or "create" is read as an id.
        Route::get('/projects/create', [ProjectController::class, 'create'])
            ->middleware('permission:projects.create')
            ->name('projects.create');

        Route::post('/projects', [ProjectController::class, 'store'])
            ->middleware('permission:projects.create')
            ->name('projects.store');

        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');

        Route::middleware('permission:projects.update')->group(function () {
            Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
            Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
            Route::patch('/projects/{project}/status', [ProjectController::class, 'status'])->name('projects.status');
        });

        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
            ->middleware('permission:projects.delete')
            ->name('projects.destroy');
    });

    Route::middleware('permission:deals.view|deals.view.all')->group(function () {
        Route::get('/pipeline', [DealController::class, 'board'])->name('pipeline.board');
        Route::get('/deals', [DealController::class, 'index'])->name('deals.index');

        Route::post('/deals', [DealController::class, 'store'])
            ->middleware('permission:deals.create')
            ->name('deals.store');

        Route::get('/deals/{deal}', [DealController::class, 'show'])->name('deals.show');
        Route::patch('/deals/{deal}', [DealController::class, 'update'])->name('deals.update');
        Route::post('/deals/{deal}/move', [DealController::class, 'move'])->name('deals.move');
        Route::post('/deals/{deal}/close', [DealController::class, 'close'])->name('deals.close');
        Route::post('/deals/{deal}/notes', [DealController::class, 'addNote'])->name('deals.notes');
        Route::post('/deals/{deal}/activities', [DealActivityController::class, 'store'])->name('deals.activities');

        Route::get('/follow-ups', [FollowUpController::class, 'index'])->name('followups.index');
        Route::post('/follow-ups', [FollowUpController::class, 'store'])->name('followups.store');
        Route::post('/follow-ups/{followUp}/complete', [FollowUpController::class, 'complete'])->name('followups.complete');
        Route::delete('/follow-ups/{followUp}', [FollowUpController::class, 'destroy'])->name('followups.destroy');
    });

    /*
     | Proposals. Who may do what is decided in the controller — writing needs
     | proposals.create on a deal the person can see, approving needs
     | proposals.approve and is never your own — because those rules involve
     | the deal, which middleware cannot see.
     */
    Route::middleware('permission:proposals.view|proposals.view.all')->group(function () {
        Route::get('/proposals', [ProposalController::class, 'index'])->name('proposals.index');
        Route::get('/deals/{deal}/proposals/create', [ProposalController::class, 'create'])->name('proposals.create');
        Route::post('/deals/{deal}/proposals', [ProposalController::class, 'store'])->name('proposals.store');
        Route::get('/proposals/{proposal}', [ProposalController::class, 'show'])->name('proposals.show');
        Route::get('/proposals/{proposal}/edit', [ProposalController::class, 'edit'])->name('proposals.edit');
        Route::patch('/proposals/{proposal}', [ProposalController::class, 'update'])->name('proposals.update');
        Route::get('/proposals/{proposal}/print', [ProposalController::class, 'print'])->name('proposals.print');
        Route::post('/proposals/{proposal}/submit', [ProposalController::class, 'submit'])->name('proposals.submit');
        Route::post('/proposals/{proposal}/approve', [ProposalController::class, 'approve'])->name('proposals.approve');
        Route::post('/proposals/{proposal}/send-back', [ProposalController::class, 'sendBack'])->name('proposals.send-back');
        Route::post('/proposals/{proposal}/send', [ProposalController::class, 'send'])->name('proposals.send');
        Route::post('/proposals/{proposal}/respond/{answer}', [ProposalController::class, 'respond'])
            ->whereIn('answer', ['accepted', 'declined'])
            ->name('proposals.respond');
    });

    // The sales team: a leaderboard for everybody who sells, and the
    // manager's view of the whole team.
    Route::get('/sales/leaderboard', [SalesController::class, 'leaderboard'])
        ->middleware('permission:deals.view|deals.view.all')
        ->name('sales.leaderboard');
    Route::get('/sales/team', [SalesController::class, 'team'])
        ->middleware('permission:deals.view.all')
        ->name('sales.team');
    Route::post('/sales/team/assign-city', [SalesController::class, 'assignCity'])
        ->middleware('permission:leads.assign')
        ->name('sales.assign-city');

    Route::middleware('permission:targets.view|targets.view.all|targets.manage')->group(function () {
        Route::get('/targets', [TargetController::class, 'index'])->name('targets.index');
        Route::post('/targets', [TargetController::class, 'store'])->name('targets.store');
    });

    /*
     | Settings — the durations that drive reminders, and the category list.
     |
     | Reading is separated from writing so somebody can be shown how the
     | reminders are configured without being able to change them.
     */
    Route::middleware('permission:settings.view')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');

        Route::middleware('permission:settings.update')->group(function () {
            Route::patch('/settings', [SettingController::class, 'update'])->name('settings.update');

            Route::post('/settings/categories', [SettingController::class, 'storeCategory'])
                ->name('settings.categories.store');
            Route::patch('/settings/categories/{category}', [SettingController::class, 'updateCategory'])
                ->name('settings.categories.update');
            Route::delete('/settings/categories/{category}', [SettingController::class, 'destroyCategory'])
                ->name('settings.categories.destroy');
        });
    });

    Route::middleware('permission:roles.view')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::post('/roles/{role}/reset', [RoleController::class, 'reset'])->name('roles.reset');
    });

    Route::get('/audit', [AuditLogController::class, 'index'])
        ->middleware('permission:audit.view')
        ->name('audit.index');
});

// Signed-in users land on the dashboard; everyone else on the login screen.
/*
 | A proposal as the buyer sees it: no account, but only through a signed link,
 | which cannot be guessed or altered and stops working once the proposal has
 | expired.
 */
Route::get('/p/{proposal}', [ProposalController::class, 'shared'])
    ->middleware('signed')
    ->name('proposals.shared');

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
