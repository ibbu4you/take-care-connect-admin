<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Is this install actually working?
 *
 * ── Why a command rather than a page ───────────────────────────────────────
 * Most of what goes wrong on shared hosting goes wrong before anybody can sign
 * in to look at a page: the storage symlink did not survive the upload, the
 * asset manifest is from the previous release, the cron was never added. A
 * command runs at the end of the deploy and says which one, in the same
 * terminal, before anyone finds out by clicking.
 *
 * Every check is read-only and safe to run at any time.
 */
class HealthCommand extends Command
{
    protected $signature = 'tcc:health';

    protected $description = 'Check that this installation is correctly set up';

    private int $failures = 0;

    private int $notes = 0;

    /**
     * Every table this system creates.
     *
     * Used to answer "is this database ours?" by looking at what is in it
     * rather than at what it is called. See database().
     */
    private const OWNED_TABLES = [
        'activity_log', 'article_assets', 'articles', 'assignments',
        'cache', 'cache_locks', 'categories', 'clients',
        'deals', 'deliverable_files', 'deliverables', 'failed_jobs',
        'follow_ups', 'interview_assets', 'interviews', 'job_batches',
        'jobs', 'leads', 'migrations', 'model_has_permissions',
        'model_has_roles', 'notes', 'notifications', 'password_reset_tokens',
        'permissions', 'projects', 'role_has_permissions', 'roles',
        'sequences', 'sessions', 'settings', 'social_posts',
        'stage_events', 'targets', 'users',
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>Take Care Connect Admin</> — health');
        $this->newLine();

        $this->database();
        $this->schema();
        $this->access();
        $this->storage();
        $this->uploads();
        $this->frontend();
        $this->scheduler();
        $this->security();

        $this->newLine();

        if ($this->failures > 0) {
            $this->components->error($this->failures.' '.($this->failures === 1 ? 'problem' : 'problems').' found.');

            return self::FAILURE;
        }

        if ($this->notes > 0) {
            $this->components->warn('Working, with '.$this->notes.' '.($this->notes === 1 ? 'note' : 'notes').'.');

            return self::SUCCESS;
        }

        $this->components->info('Everything checks out.');

        return self::SUCCESS;
    }

    private function database(): void
    {
        $this->section('Database');

        try {
            $name = DB::connection()->getDatabaseName();
            DB::connection()->getPdo();
            $this->pass("Connected to <options=bold>{$name}</>");

            /*
             | The one mistake worth shouting about.
             |
             | Pointing this at the live website's database would not fail —
             | both are Laravel apps with a `users` table — it would merge two
             | sets of accounts with different roles and different permission
             | catalogues. Better caught here than by a support call.
             |
             | ── Why this reads the contents, not the name ──────────────────
             | This used to flag any database whose name contained "tcif",
             | which is the wrong question twice over. A perfectly good empty
             | database called `tcif_admin` is not the live site, and the live
             | site's database would sail through under any other name. What
             | actually matters is whether somebody else's tables are already
             | in here.
             */
            $tables = array_map(
                static fn ($row) => array_values((array) $row)[0],
                DB::select('SHOW TABLES'),
            );

            $foreign = array_values(array_diff($tables, self::OWNED_TABLES));

            if ($foreign !== []) {
                $shown = array_slice($foreign, 0, 4);
                $rest = count($foreign) - count($shown);

                $this->problem(
                    'This database holds '.count($foreign).' table(s) this system does not own ('
                    .implode(', ', $shown).($rest > 0 ? ", +{$rest} more" : '')
                    .'). This system must have a database of its own.'
                );
            }
        } catch (\Throwable $e) {
            $this->problem('Cannot connect: '.$e->getMessage());

            return;
        }

        $applied = DB::table('migrations')->count();
        $files = count(glob(database_path('migrations/*.php')) ?: []);

        $files > $applied
            ? $this->problem(($files - $applied).' migrations have not been run. Run: php artisan migrate --force')
            : $this->pass("{$applied} migrations applied");
    }

    private function schema(): void
    {
        $this->section('Schema');

        $expected = [
            'users', 'sessions', 'jobs', 'notifications',
            'interviews', 'articles', 'deliverables', 'deals',
        ];

        foreach ($expected as $table) {
            if (! Schema::hasTable($table)) {
                $this->problem("Table `{$table}` is missing.");

                return;
            }
        }

        $this->pass('All expected tables present');

        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        if ($failed > 0) {
            $this->note($failed.' failed '.($failed === 1 ? 'job' : 'jobs').' waiting. Inspect: php artisan queue:failed');
        }
    }

    private function access(): void
    {
        $this->section('Roles and permissions');

        $expected = count(Permissions::all());
        $actual = Permission::count();

        if ($actual === 0) {
            $this->problem('No permissions seeded. Run: php artisan db:seed --class=RolePermissionSeeder');

            return;
        }

        $actual === $expected
            ? $this->pass("{$actual} permissions, ".count(Roles::names()).' roles')
            : $this->note("{$actual} permissions in the database, {$expected} in the catalogue. Re-run the seeder.");

        $admins = User::role(Roles::ADMIN)->active()->count();

        $admins === 0
            ? $this->problem('No administrator. Create one: php artisan tcc:setup')
            : $this->pass($admins.' active administrator '.($admins === 1 ? 'account' : 'accounts'));

        /*
         | Accounts that would let anybody in.
         |
         | ── Why this asks about the password, not the address ─────────────
         | It used to flag any account whose email appeared in DemoSeeder's
         | list — which on a real installation means it flags
         | admin@takecareconnect.com, the most natural address for the first
         | administrator and the one already in .env as MAIL_FROM_ADDRESS. The
         | advice it then printed was "delete them", about the only account
         | anybody could sign in with. A check that tells you to delete your
         | own administrator is worse than no check.
         |
         | What makes a demo account dangerous is that its password is
         | "password". So that is the question. It costs one bcrypt comparison
         | per active account, which at this system's scale is a fraction of a
         | second, and it cannot be wrong about a real account.
         */
        if (app()->environment('production')) {
            $open = User::query()
                ->active()
                ->get(['id', 'email', 'password'])
                ->filter(fn (User $user) => Hash::check('password', $user->password))
                ->pluck('email');

            if ($open->isNotEmpty()) {
                $this->problem(
                    $open->count().' '.($open->count() === 1 ? 'account signs' : 'accounts sign')
                    .' in with the password "password": '.$open->implode(', ')
                    .'. Change or delete '.($open->count() === 1 ? 'it' : 'them').'.'
                );
            }
        }
    }

    private function storage(): void
    {
        $this->section('Storage');

        $paths = ['framework/cache', 'framework/sessions', 'framework/views', 'logs', 'app/workfiles'];

        foreach ($paths as $path) {
            $full = storage_path($path);

            if (! is_dir($full)) {
                @mkdir($full, 0775, true);
            }

            if (! is_writable($full)) {
                $this->problem("Not writable: storage/{$path}");

                return;
            }
        }

        $this->pass('storage/ is writable');

        $this->storageLink();

        $free = @disk_free_space(base_path());

        if ($free !== false && $free < 1024 ** 3) {
            $this->note('Less than 1 GB free. Video cuts fill a shared-hosting quota quickly.');
        }
    }

    /**
     * Does public/storage actually point at storage/app/public?
     *
     * ── Why not is_link() ──────────────────────────────────────────────────
     * On Windows the link Laravel creates is a reparse point that PHP reports
     * as neither a link nor a directory — file_exists() is the only thing that
     * says true. So this resolves the path instead and checks where it lands,
     * which works on both platforms and catches something is_link() cannot:
     * a plain empty `public/storage` directory somebody created by hand, which
     * looks fine and serves nothing.
     */
    private function storageLink(): void
    {
        $link = public_path('storage');

        if (! file_exists($link)) {
            $this->problem('public/storage is missing. Run: php artisan storage:link');

            return;
        }

        $resolved = realpath($link);
        $target = realpath(storage_path('app/public'));

        $resolved !== false && $resolved === $target
            ? $this->pass('public/storage is linked')
            : $this->problem('public/storage is not a link to storage/app/public. Delete it and run: php artisan storage:link');
    }

    /**
     * Can PHP actually accept the files this application says it accepts?
     *
     * ── Why this is worth a check ──────────────────────────────────────────
     * The largest thing here is a 200 MB video cut. PHP ships with
     * upload_max_filesize at 2 MB, and when a POST exceeds post_max_size the
     * request body is discarded before PHP ever sees it — so the form arrives
     * with no file and no error, and Laravel reports "the file field is
     * required". The creative retries a working upload three times and then
     * calls somebody.
     *
     * Nothing in the application can detect that at the point it happens. This
     * detects it at deploy time instead.
     */
    private function uploads(): void
    {
        $this->section('Uploads');

        // The largest cap any controller allows, in kilobytes.
        $largest = 204800;

        $upload = $this->bytes(ini_get('upload_max_filesize'));
        $post = $this->bytes(ini_get('post_max_size'));
        $needed = $largest * 1024;

        $limit = min($upload, $post);

        if ($limit < $needed) {
            /*
             | A failure on the server, a note on a laptop.
             |
             | XAMPP and `artisan serve` both ignore .user.ini, so a developer
             | cannot fix this from inside the project — and a check that always
             | fails locally is a check people learn to skip past, including on
             | the one machine where it matters.
             */
            $report = app()->environment('production')
                ? fn (string $message) => $this->problem($message)
                : fn (string $message) => $this->note($message);

            $report(sprintf(
                'PHP accepts %s, but deliverable uploads allow %s. Large files will arrive empty.',
                $this->human($limit),
                $this->human($needed),
            ));
            $this->line('      <fg=gray>Set upload_max_filesize and post_max_size to 210M in .user.ini or hPanel → PHP Configuration.</>');
        } else {
            $this->pass('PHP accepts up to '.$this->human($limit));
        }

        /*
         | A 200 MB upload over a domestic Indian connection is minutes, not
         | seconds. The default 30-second limit kills it partway through and
         | leaves a truncated file on disk.
         */
        $seconds = (int) ini_get('max_execution_time');

        if ($seconds !== 0 && $seconds < 300) {
            $this->note("max_execution_time is {$seconds}s. A large upload over a slow line will be cut off; 300 is safer.");
        }
    }

    private function bytes(string|false $value): int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1024 ** 3
            ? round($bytes / 1024 ** 3, 1).' GB'
            : round($bytes / 1024 ** 2).' MB';
    }

    private function frontend(): void
    {
        $this->section('Frontend');

        $manifest = public_path('build/manifest.json');

        if (! file_exists($manifest)) {
            $this->problem('public/build/manifest.json is missing. Run `npm run build` locally and upload public/build.');

            return;
        }

        $days = (int) floor((time() - filemtime($manifest)) / 86400);

        $this->pass('Asset manifest present'.($days > 0 ? ' <fg=gray>(built '.$days.' '.($days === 1 ? 'day' : 'days').' ago)</>' : ''));
    }

    private function scheduler(): void
    {
        $this->section('Scheduler');

        /*
         | There is no way to ask the OS whether a cron entry exists, so this
         | infers it: the scheduler drains the queue every minute, and jobs
         | piling up unprocessed is what a missing cron actually looks like
         | from in here.
         */
        $queued = DB::table('jobs')->count();

        if ($queued > 25) {
            $this->problem($queued.' jobs are queued and not being processed — the cron entry is probably missing.');
            $this->line('      <fg=gray>* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1</>');

            return;
        }

        $this->pass($queued === 0 ? 'Queue is empty' : $queued.' jobs queued');
        $this->line('      <fg=gray>Confirm the cron entry in hPanel → Advanced → Cron Jobs.</>');
    }

    private function security(): void
    {
        $this->section('Security');

        if (app()->environment('production')) {
            config('app.debug')
                ? $this->problem('APP_DEBUG is on in production. A stack trace would expose the database password.')
                : $this->pass('Debug mode off');

            str_starts_with((string) config('app.url'), 'https://')
                ? $this->pass('APP_URL is HTTPS')
                : $this->note('APP_URL is not HTTPS, so secure cookies will not be sent.');
        }

        config('app.timezone') === 'Asia/Kolkata'
            ? $this->pass('Timezone is Asia/Kolkata')
            : $this->problem('Timezone is '.config('app.timezone').' — every clock in the system will be wrong.');
    }

    // ── Output ─────────────────────────────────────────────────────────────

    private function section(string $title): void
    {
        $this->line("  <fg=gray>{$title}</>");
    }

    private function pass(string $message): void
    {
        $this->line("    <fg=green>+</> {$message}");
    }

    private function note(string $message): void
    {
        $this->notes++;
        $this->line("    <fg=yellow>!</> {$message}");
    }

    private function problem(string $message): void
    {
        $this->failures++;
        $this->line("    <fg=red>x</> {$message}");
    }
}
