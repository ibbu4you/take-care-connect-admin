<?php

namespace App\Support;

/**
 * The permission vocabulary.
 *
 * Names are `module.action`. One catalogue drives three things that must never
 * disagree: the seeder that creates the rows, the `permission:` middleware on
 * every route, and the checkbox matrix on the user form. Adding a capability is
 * editing this file and re-seeding — there is nowhere else to remember.
 *
 * ── The `.all` suffix ────────────────────────────────────────────────────────
 * Scope, not a separate capability. `interviews.view` means "the interviews I
 * am on"; `interviews.view.all` means "everyone's". Every index query runs
 * through {@see Scope::apply()}, which looks for exactly this pair, so a role
 * is widened from own-work to team-wide by granting one extra string rather
 * than by writing a second controller.
 *
 * That is also how a team lead is made: direct-grant `clients.view.all` and
 * `deals.view.all` to one person instead of inventing a seventh role for them.
 */
final class Permissions
{
    /**
     * Every permission, grouped for the matrix UI.
     *
     * The group key is the module; the group label heads a column block on the
     * user form; each permission carries the sentence an Admin reads when
     * deciding whether to tick it.
     *
     * @var array<string, array{label: string, permissions: array<string, string>}>
     */
    public const GROUPS = [
        /*
         | Leads come before clients, in the catalogue and in the work.
         |
         | A lead is somebody who might become a client. Keeping them out of the
         | client directory is the whole point: a directory with four hundred
         | unqualified prospects in it stops being a list of the people you
         | actually look after.
         */
        'leads' => [
            'label' => 'Leads',
            'permissions' => [
                'leads.view' => 'See their own leads',
                'leads.view.all' => 'See every lead, not just their own',
                'leads.create' => 'Add a lead',
                'leads.update' => 'Edit a lead',
                'leads.delete' => 'Delete a lead',
                'leads.convert' => 'Turn a lead into a client',
                'leads.assign' => 'Move a lead to a different owner',
                'leads.import' => 'Import leads from a spreadsheet',
                'leads.export' => 'Export the lead list',
            ],
        ],

        'clients' => [
            'label' => 'Clients',
            'permissions' => [
                'clients.view' => 'See their own clients',
                'clients.view.all' => 'See every client, not just their own',
                'clients.create' => 'Add a client',
                'clients.update' => 'Edit a client',
                'clients.delete' => 'Delete a client',
                'clients.assign' => 'Move a client to a different owner',
                'clients.export' => 'Export the client list',
            ],
        ],

        'projects' => [
            'label' => 'Projects',
            'permissions' => [
                'projects.view' => 'See their own projects',
                'projects.view.all' => 'See every project',
                'projects.create' => 'Create a project',
                'projects.update' => 'Edit a project',
                'projects.delete' => 'Delete a project',
            ],
        ],

        'deals' => [
            'label' => 'Sales pipeline',
            'permissions' => [
                'deals.view' => 'See their own deals',
                'deals.view.all' => 'See every deal on the board',
                'deals.create' => 'Create a deal',
                'deals.update' => 'Edit a deal',
                // Separate from `update` so the board can be handed to someone
                // read-only: seeing the pipeline is not permission to move it.
                'deals.move' => 'Drag a deal to another stage',
                // Separate again, because closing writes revenue.
                'deals.close' => 'Close a deal as won or lost',
                'deals.delete' => 'Delete a deal',
            ],
        ],

        'proposals' => [
            'label' => 'Proposals',
            'permissions' => [
                'proposals.view' => 'See proposals on their own deals',
                'proposals.view.all' => 'See every proposal',
                'proposals.create' => 'Write, edit and send a proposal',
                // Separate, because it is the discount check: a proposal over the
                // approval threshold cannot be sent until somebody holding this
                // has looked at it.
                'proposals.approve' => 'Approve a proposal with a large discount',
            ],
        ],

        'targets' => [
            'label' => 'Targets',
            'permissions' => [
                'targets.view' => 'See their own monthly target',
                'targets.view.all' => 'See everyone\'s targets',
                'targets.manage' => 'Set targets for other people',
            ],
        ],

        'interviews' => [
            'label' => 'Interviews',
            'permissions' => [
                'interviews.view' => 'See interviews they are involved in',
                'interviews.view.all' => 'See every interview',
                'interviews.create' => 'Create an interview',
                'interviews.schedule' => 'Set the date and assign an anchor',
                'interviews.update' => 'Edit interview details',
                'interviews.conduct' => 'Mark conducted and record the Q&A',
                'interviews.handover' => 'Hand over to the content team',
                'interviews.reassign' => 'Move an interview to someone else',
                'interviews.cancel' => 'Cancel or mark a no-show',
                'interviews.delete' => 'Delete an interview',
            ],
        ],

        'articles' => [
            'label' => 'Articles',
            'permissions' => [
                'articles.view' => 'See articles assigned to them',
                'articles.view.all' => 'See every article',
                'articles.create' => 'Start an article',
                'articles.update' => 'Write and edit an article',
                'articles.assign' => 'Assign an article to a writer',
                'articles.review' => 'Send an article back for revisions',
                'articles.approve' => 'Approve an article for publishing',
                'articles.publish' => 'Mark published and record the live URL',
                'articles.delete' => 'Delete an article',
            ],
        ],

        'deliverables' => [
            'label' => 'Creative deliverables',
            'permissions' => [
                'deliverables.view' => 'See deliverables assigned to them',
                'deliverables.view.all' => 'See every deliverable',
                'deliverables.create' => 'Commission a poster, reel or edit',
                'deliverables.update' => 'Edit the brief, caption or due date',
                'deliverables.work' => 'Upload work and submit it for review',
                'deliverables.assign' => 'Assign a deliverable to someone',
                'deliverables.approve' => 'Approve or reject submitted work',
                'deliverables.publish' => 'Mark a deliverable published',
                'deliverables.delete' => 'Delete a deliverable',
            ],
        ],

        'assets' => [
            'label' => 'Files',
            'permissions' => [
                'assets.upload' => 'Upload documents, recordings and photos',
                'assets.view.all' => 'Open files on work they do not own',
                'assets.delete' => 'Delete an uploaded file',
            ],
        ],

        'social' => [
            'label' => 'Social links',
            'permissions' => [
                'social.links.record' => 'Record a published YouTube or Instagram link',
                'social.links.view.all' => 'See every recorded social link',
            ],
        ],

        'comments' => [
            'label' => 'Comments',
            'permissions' => [
                'comments.create' => 'Comment on any record they can see',
                'comments.delete.any' => 'Delete somebody else\'s comment',
            ],
        ],

        'reports' => [
            'label' => 'Reports',
            'permissions' => [
                'reports.view' => 'See reports covering their own work',
                'reports.view.all' => 'See organisation-wide reports',
            ],
        ],

        'users' => [
            'label' => 'People',
            'permissions' => [
                'users.view' => 'See the staff list',
                'users.create' => 'Create an account',
                'users.update' => 'Edit an account or deactivate it',
                'users.delete' => 'Delete an account',
                'users.permissions.manage' => 'Grant or revoke individual permissions',
            ],
        ],

        'roles' => [
            'label' => 'Roles',
            'permissions' => [
                'roles.view' => 'See the roles and what each one grants',
                'roles.update' => 'Change what a role grants',
            ],
        ],

        'settings' => [
            'label' => 'Settings',
            'permissions' => [
                'settings.view' => 'Open settings',
                'settings.update' => 'Change settings',
            ],
        ],

        'audit' => [
            'label' => 'Audit log',
            'permissions' => [
                'audit.view' => 'Read the activity log',
            ],
        ],
    ];

    /**
     * Every permission name, flat. What the seeder creates.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];

        foreach (self::GROUPS as $group) {
            foreach (array_keys($group['permissions']) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Every permission in one module, e.g. `deals` -> all eight deal strings.
     *
     * @return list<string>
     */
    public static function forModule(string $module): array
    {
        return array_keys(self::GROUPS[$module]['permissions'] ?? []);
    }

    /**
     * Shorthand for the seeder: `Permissions::only('deals', ['view', 'create'])`.
     *
     * Prefixing here rather than writing `deals.view` out in the role matrix
     * keeps {@see Roles} readable as a table of who-does-what.
     *
     * @param  list<string>  $actions
     * @return list<string>
     */
    public static function only(string $module, array $actions): array
    {
        $names = array_map(static fn (string $action): string => "{$module}.{$action}", $actions);

        /*
         | Refuse to compose a permission that does not exist.
         |
         | This used to concatenate whatever it was given, so `only('reports',
         | ['export'])` happily produced `reports.export` long after that
         | permission had been removed from the catalogue. Nothing complained
         | until the seeder called syncPermissions() and spatie threw — with a
         | message naming the permission but not the role, on a stack that
         | passes through every test that touches a user.
         |
         | The mistake is in the role definition, so it is caught here, where
         | the definition is.
         */
        $unknown = array_values(array_diff($names, self::all()));

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown permission(s) in a role definition: '.implode(', ', $unknown)
                .'. Either add them to Permissions::GROUPS or remove them from the role.'
            );
        }

        return $names;
    }

    /**
     * The catalogue as the permission matrix UI needs it.
     *
     * @return list<array{key: string, label: string, permissions: list<array{name: string, label: string}>}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::GROUPS as $key => $group) {
            $permissions = [];

            foreach ($group['permissions'] as $name => $label) {
                $permissions[] = ['name' => $name, 'label' => $label];
            }

            $catalog[] = [
                'key' => $key,
                'label' => $group['label'],
                'permissions' => $permissions,
            ];
        }

        return $catalog;
    }
}
