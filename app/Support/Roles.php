<?php

namespace App\Support;

/**
 * The eight roles, and what each one grants by default.
 *
 * These are starting points, not a cage. Roles are editable at runtime on the
 * Roles page, and any individual can be given extra permissions or have one
 * taken away on their own account — see `users.denied_permissions`. Re-seeding
 * resets a role to the definition here, so runtime edits to a role are
 * deliberate and reversible.
 *
 * ── Why there is no Super Admin ─────────────────────────────────────────────
 * There was one, and it held exactly the same 74 permissions as Admin. The only
 * differences were that it bypassed the Gate entirely and that other admins
 * could not edit it — which made two roles that looked identical on the Roles
 * page and behaved differently in ways nobody could see.
 *
 * The job it really did was break-glass recovery, and the console does that
 * better: `tcc:password` and `tcc:two-factor-reset` work over SSH even when
 * nobody can sign in at all, which was never true of a role you had to log in
 * to use. What remains is the guard that actually prevents a lockout — the last
 * Admin cannot be demoted, deactivated or deleted.
 *
 * ── Why nobody but an Admin holds `.all` by default ──────────────────────────
 * Anchors, Creatives, Relationship Managers and Telecallers see their own work. That keeps
 * every list short and every dashboard about the person reading it. When
 * somebody needs the team-wide view, they are direct-granted the specific
 * `.all` strings rather than promoted into a wider role — which is why there is
 * no "Sales Lead" or "Content Lead" role here.
 */
final class Roles
{
    /** Runs the platform. The top role — there is nothing above it. */
    public const ADMIN = 'Admin';

    public const ANCHOR = 'Anchor';

    public const CONTENT = 'Content';

    public const CREATIVE = 'Creative';

    public const RELATIONSHIP_MANAGER = 'Relationship Manager';

    public const TELECALLER = 'Telecaller';

    public const SALES_EXECUTIVE = 'Sales Executive';

    public const SALES_MANAGER = 'Sales Manager';

    /** @return list<string> */
    public static function names(): array
    {
        return [
            self::ADMIN,
            self::ANCHOR,
            self::CONTENT,
            self::CREATIVE,
            self::RELATIONSHIP_MANAGER,
            self::TELECALLER,
            self::SALES_EXECUTIVE,
            self::SALES_MANAGER,
        ];
    }

    /**
     * A short description of each role, shown on the Roles page.
     *
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return [
            self::ADMIN => 'Runs the platform: every module, plus accounts, roles, settings and the audit log.',
            self::ANCHOR => 'Conducts interviews, records the Q&A and hands the write-up to the content team.',
            self::CONTENT => 'Rewrites handovers into finished articles and takes them through to published.',
            self::CREATIVE => 'Produces posters, reels and video edits, and records where they were published.',
            self::RELATIONSHIP_MANAGER => 'Owns clients and projects, works the sales pipeline and books interviews.',
            self::TELECALLER => 'Works the call list: adds and calls leads, logs each call and books interviews.',
            self::SALES_EXECUTIVE => 'Sells: works their own leads and deals, logs every call and meeting, and writes proposals.',
            self::SALES_MANAGER => 'Runs the sales team: sees every deal, approves discounts, sets targets and hands out leads.',
        ];
    }

    /**
     * Role -> the permissions it grants.
     *
     * @return array<string, list<string>>
     */
    public static function definitions(): array
    {
        return [
            self::ADMIN => self::admin(),
            self::ANCHOR => self::anchor(),
            self::CONTENT => self::content(),
            self::CREATIVE => self::creative(),
            self::RELATIONSHIP_MANAGER => self::relationshipManager(),
            self::TELECALLER => self::telecaller(),
            self::SALES_EXECUTIVE => self::salesExecutive(),
            self::SALES_MANAGER => self::salesManager(),
        ];
    }

    /**
     * Everything — every module, plus accounts, roles, settings and the audit log.
     *
     * ── Why an Admin has no Gate bypass ────────────────────────────────────
     * Holding every permission and bypassing every check are not the same
     * thing, and the difference is per-user revocations. An Admin who has had
     * one permission taken away on their own account genuinely does not have
     * it, which is what the permission matrix already shows and what a bypass
     * would quietly contradict.
     *
     * @return list<string>
     */
    private static function admin(): array
    {
        return Permissions::all();
    }

    /**
     * Interviews they are the anchor on, and the social work that comes off them.
     *
     * No `.all` anywhere: an Anchor's queue is their own diary. They can create
     * and assign deliverables because the two social-only interview kinds are
     * theirs end to end — nobody else is involved between the interview and the
     * reel.
     *
     * @return list<string>
     */
    private static function anchor(): array
    {
        return [
            ...Permissions::only('interviews', [
                'view', 'update', 'conduct', 'handover', 'reassign',
            ]),
            ...Permissions::only('assets', ['upload']),
            ...Permissions::only('articles', ['view', 'assign']),
            ...Permissions::only('deliverables', ['view', 'create', 'assign']),
            ...Permissions::only('social', ['links.record']),
            ...Permissions::only('clients', ['view']),
            ...Permissions::only('projects', ['view']),
            ...Permissions::only('comments', ['create']),
            ...Permissions::only('reports', ['view']),
        ];
    }

    /**
     * The editorial desk.
     *
     * Holds `interviews.view.all` — they triage the whole handover queue, so
     * scoping it to their own would hide the very thing they are waiting for —
     * and `articles.view.all`, because an article in revisions is the team's
     * problem, not one writer's.
     *
     * @return list<string>
     */
    private static function content(): array
    {
        return [
            ...Permissions::only('articles', [
                'view', 'view.all', 'create', 'update', 'assign',
                'review', 'approve', 'publish',
            ]),
            ...Permissions::only('interviews', ['view', 'view.all', 'update']),
            // No `assets.view.all`: file access is derived from the record the
            // file hangs off. Content already sees every interview, so they
            // already reach every handover document — granting the blanket
            // string as well would only widen access beyond that.
            ...Permissions::only('assets', ['upload']),
            ...Permissions::only('deliverables', [
                'view', 'view.all', 'create', 'assign', 'approve',
            ]),
            ...Permissions::only('social', ['links.view.all']),
            ...Permissions::only('clients', ['view', 'view.all']),
            ...Permissions::only('projects', ['view', 'view.all']),
            ...Permissions::only('comments', ['create']),
            ...Permissions::only('reports', ['view', 'view.all']),
        ];
    }

    /**
     * Posters, reels and edits.
     *
     * Deliberately no `interviews.view.all`. A Creative still sees the
     * interview behind their own deliverable, because Scope::apply() matches
     * them on `creative_owner_id` — they get the context they need to cut the
     * reel and no more.
     *
     * @return list<string>
     */
    private static function creative(): array
    {
        // No `projects.view`: the project a deliverable belongs to is shown on
        // the deliverable itself. Granting the list page as well would put a
        // whole "Sales" section in a video editor's sidebar for one link.
        return [
            ...Permissions::only('deliverables', ['view', 'update', 'work', 'publish']),
            ...Permissions::only('interviews', ['view']),
            // Same reasoning as Content, and it matters more here: a Creative
            // is scoped to interviews they are the creative owner of, so
            // `assets.view.all` would have handed them every business handover
            // document in the system to solve a problem they do not have.
            ...Permissions::only('assets', ['upload']),
            ...Permissions::only('social', ['links.record']),
            ...Permissions::only('comments', ['create']),
            ...Permissions::only('reports', ['view']),
        ];
    }

    /**
     * Clients, the pipeline, and booking interviews.
     *
     * Approves deliverables because they are the one facing the client when a
     * poster goes out with the wrong phone number on it. Cannot reassign a
     * client (`clients.assign`) — moving an account between owners is an Admin
     * decision, not one the losing or gaining owner makes.
     *
     * @return list<string>
     */
    private static function relationshipManager(): array
    {
        return [
            ...Permissions::only('leads', [
                'view', 'create', 'update', 'convert', 'import', 'export',
            ]),
            ...Permissions::only('clients', [
                'view', 'create', 'update', 'export',
            ]),
            ...Permissions::only('projects', ['view', 'create', 'update']),
            ...Permissions::only('deals', [
                'view', 'create', 'update', 'move', 'close',
            ]),
            ...Permissions::only('proposals', ['view', 'create']),
            ...Permissions::only('targets', ['view']),
            ...Permissions::only('interviews', [
                'view', 'create', 'schedule', 'cancel',
            ]),
            ...Permissions::only('deliverables', ['view', 'approve']),
            ...Permissions::only('social', ['links.view.all']),
            ...Permissions::only('comments', ['create']),
            ...Permissions::only('reports', ['view']),
        ];
    }

    /**
     * The call list, and booking interviews straight off a call.
     *
     * Their own leads only: they add, edit and import leads and log every call
     * against one. Converting a lead into a client is not theirs — once a lead
     * is qualified, the Relationship Manager takes the account, the deal and the
     * client relationship from there, which is why there are no `clients`,
     * `projects` or `deals` strings here.
     *
     * They can book and reschedule an interview (`create`, `schedule`); the ones
     * they booked appear in their own list through `scheduled_by`. Calling one
     * off (`interviews.cancel`) stays with the RM, since it is a conversation
     * with the client rather than with the diary.
     *
     * @return list<string>
     */
    private static function telecaller(): array
    {
        return [
            ...Permissions::only('leads', ['view', 'create', 'update', 'import']),
            ...Permissions::only('interviews', ['view', 'create', 'schedule']),
            ...Permissions::only('comments', ['create']),
            // Their own figures, like every other role.
            ...Permissions::only('reports', ['view']),
        ];
    }

    /**
     * A salesperson: their own leads, clients and deals, start to finish.
     *
     * Own work only — no `.all` — so their pipeline, follow-ups and dashboard
     * are about them. They can convert a lead and close a deal, because both
     * are the moment the sale happens and the person who made it is the one
     * standing there. A large discount still goes past a Sales Manager first:
     * `proposals.approve` is not theirs.
     *
     * @return list<string>
     */
    private static function salesExecutive(): array
    {
        return [
            ...Permissions::only('leads', ['view', 'create', 'update', 'import', 'convert']),
            ...Permissions::only('clients', ['view', 'create', 'update']),
            ...Permissions::only('deals', ['view', 'create', 'update', 'move', 'close']),
            ...Permissions::only('proposals', ['view', 'create']),
            ...Permissions::only('targets', ['view']),
            ...Permissions::only('comments', ['create']),
            ...Permissions::only('reports', ['view']),
        ];
    }

    /**
     * The head of sales.
     *
     * The one non-admin role that sees across the team, because that is the
     * job: every deal and lead (`.all`), moving leads between people, setting
     * targets, and approving discounts. `reports.view.all` opens Performance
     * and the sales team view. No accounts, roles or settings — those stay
     * with the Admin.
     *
     * @return list<string>
     */
    private static function salesManager(): array
    {
        return [
            ...Permissions::only('leads', ['view', 'view.all', 'create', 'update', 'import', 'convert', 'assign', 'export']),
            ...Permissions::only('clients', ['view', 'view.all', 'create', 'update', 'assign', 'export']),
            ...Permissions::only('deals', ['view', 'view.all', 'create', 'update', 'move', 'close']),
            ...Permissions::only('proposals', ['view', 'view.all', 'create', 'approve']),
            ...Permissions::only('targets', ['view', 'view.all', 'manage']),
            ...Permissions::only('comments', ['create']),
            ...Permissions::only('reports', ['view', 'view.all']),
        ];
    }
}
