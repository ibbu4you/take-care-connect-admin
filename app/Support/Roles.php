<?php

namespace App\Support;

/**
 * The five roles, and what each one grants by default.
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
 * Anchors, Creatives and Relationship Managers see their own work. That keeps
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

    /** @return list<string> */
    public static function names(): array
    {
        return [
            self::ADMIN,
            self::ANCHOR,
            self::CONTENT,
            self::CREATIVE,
            self::RELATIONSHIP_MANAGER,
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
                'review', 'approve', 'publish', 'export',
            ]),
            ...Permissions::only('interviews', ['view', 'view.all', 'update', 'export']),
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
            ...Permissions::only('reports', ['view', 'view.all', 'export']),
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
                'view', 'create', 'update', 'import', 'export',
            ]),
            ...Permissions::only('projects', ['view', 'create', 'update']),
            ...Permissions::only('deals', [
                'view', 'create', 'update', 'move', 'close', 'export',
            ]),
            ...Permissions::only('targets', ['view']),
            ...Permissions::only('interviews', [
                'view', 'create', 'schedule', 'cancel', 'export',
            ]),
            ...Permissions::only('deliverables', ['view', 'approve']),
            ...Permissions::only('social', ['links.view.all']),
            ...Permissions::only('comments', ['create']),
            ...Permissions::only('reports', ['view', 'export']),
        ];
    }
}
