<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\LeadSource;
use App\Support\Workflow\LeadStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importing leads from a spreadsheet.
 *
 * A telecaller is usually handed their day as a sheet: a market association's
 * member list, an expo's exhibitors, a list somebody typed up. This turns that
 * sheet into leads in one step, without creating a second copy of a business
 * the team already has.
 *
 * ── CSV, not Excel ─────────────────────────────────────────────────────────
 * Every spreadsheet program saves CSV ("File → Save as / Download → CSV"), and
 * reading it needs nothing installed on a shared host. Parsing .xlsx would mean
 * a library and its memory footprint for the sake of skipping one menu click.
 *
 * ── Duplicates are skipped, not merged ─────────────────────────────────────
 * A row whose phone number is already on a lead — or already appeared earlier
 * in the same file — is left out and counted. Merging would silently rewrite a
 * lead somebody is in the middle of working; skipping and saying so is safe,
 * and the summary tells the importer exactly how many were held back and why.
 */
class LeadImportController extends Controller
{
    /** A day's list, not a database migration. Larger files are split. */
    private const MAX_ROWS = 2000;

    /**
     * Column headings people actually use, normalised to letters and digits.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'name' => ['name', 'business', 'businessname', 'company', 'companyname', 'shop', 'shopname', 'firm', 'firmname', 'brand'],
        'contact_person' => ['contact', 'contactperson', 'contactname', 'owner', 'ownername', 'person', 'proprietor'],
        'phone' => ['phone', 'phoneno', 'phonenumber', 'mobile', 'mobileno', 'mobilenumber', 'contactnumber', 'contactno', 'number', 'cell'],
        'whatsapp' => ['whatsapp', 'whatsappno', 'whatsappnumber'],
        'email' => ['email', 'emailid', 'emailaddress', 'mail'],
        'city' => ['city', 'town', 'district', 'place', 'location'],
        'state' => ['state'],
        'trade' => ['trade', 'businesstype', 'type', 'category', 'industry', 'sector', 'product', 'products'],
        'notes' => ['notes', 'note', 'remarks', 'remark', 'comment', 'comments'],
        'next_action_on' => ['nextcall', 'nextcallon', 'callbackon', 'callback', 'followup', 'followupdate', 'followupon'],
    ];

    public function __construct(private readonly CodeGenerator $codes) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Leads/Import', [
            'sources' => LeadSource::options(),
            'owners' => $request->user()->can('leads.assign') ? $this->ownerOptions() : [],
            'columns' => array_keys(self::COLUMNS),
            'maxRows' => self::MAX_ROWS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'source' => ['required', Rule::enum(LeadSource::class)],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        // Only somebody who may move leads between people may import them into
        // somebody else's name. Everybody else imports their own list.
        $ownerId = $request->user()->can('leads.assign') && ! empty($validated['owner_id'])
            ? (int) $validated['owner_id']
            : $request->user()->id;

        [$rows, $map] = $this->read($request->file('file')->getRealPath());

        $known = $this->knownPhones();
        $seen = [];
        $counts = ['imported' => 0, 'existing' => 0, 'repeated' => 0, 'unnamed' => 0];

        DB::transaction(function () use ($rows, $map, $validated, $ownerId, $request, &$known, &$seen, &$counts) {
            foreach ($rows as $row) {
                $value = fn (string $field): ?string => isset($map[$field])
                    ? (trim((string) ($row[$map[$field]] ?? '')) ?: null)
                    : null;

                $name = $value('name');

                if ($name === null) {
                    $counts['unnamed']++;

                    continue;
                }

                $phone = $value('phone') ?? $value('whatsapp');
                $key = $this->phoneKey($phone);

                if ($key !== null && isset($known[$key])) {
                    $counts['existing']++;

                    continue;
                }

                if ($key !== null && isset($seen[$key])) {
                    $counts['repeated']++;

                    continue;
                }

                Lead::create([
                    'code' => $this->codes->next('LED'),
                    'name' => mb_substr($name, 0, 255),
                    'contact_person' => $this->clip($value('contact_person'), 255),
                    'phone' => $this->clip($phone, 32),
                    'whatsapp' => $this->clip($value('whatsapp'), 32),
                    'email' => filter_var($value('email'), FILTER_VALIDATE_EMAIL) ?: null,
                    'city' => $this->clip($value('city'), 255),
                    'state' => $this->clip($value('state'), 120),
                    'trade' => $this->clip($value('trade'), 255),
                    'notes' => $value('notes'),
                    'next_action_on' => $this->date($value('next_action_on')),
                    'source' => $validated['source'],
                    'status' => LeadStatus::New,
                    'owner_id' => $ownerId,
                    'created_by' => $request->user()->id,
                ]);

                if ($key !== null) {
                    $seen[$key] = true;
                }

                $counts['imported']++;
            }
        });

        return redirect()->route('leads.index')->with('success', $this->summary($counts));
    }

    /** A blank sheet with the headings the importer understands, plus one example row. */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // So Excel opens it as UTF-8.
            fputcsv($out, ['Business name', 'Contact person', 'Phone', 'WhatsApp', 'Email', 'City', 'State', 'Trade', 'Next call', 'Notes']);
            fputcsv($out, ['Madurai Jasmine Growers', 'Selvi Murugan', '98765 43210', '', '', 'Madurai', 'Tamil Nadu', 'Flower farming', '', 'Met at the Pongal market']);
            fclose($out);
        }, 'lead-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The rows, and which column holds which field.
     *
     * @return array{0: list<list<string>>, 1: array<string, int>}
     */
    private function read(string $path): array
    {
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first); // Excel's UTF-8 marker.

        // Comma in most of the world; Excel in some locales writes semicolons.
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $d) => substr_count($first, $d))->first();

        $header = str_getcsv(trim($first), $delimiter);
        $map = [];

        foreach ($header as $index => $heading) {
            $normalised = preg_replace('/[^a-z0-9]/', '', mb_strtolower($heading));

            foreach (self::COLUMNS as $field => $aliases) {
                if (! isset($map[$field]) && in_array($normalised, $aliases, true)) {
                    $map[$field] = $index;
                }
            }
        }

        if (! isset($map['name'])) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => 'No column for the business name. Name the first column "Business name" — or download the template.',
            ]);
        }

        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null] || implode('', $row) === '') {
                continue; // A blank line.
            }

            $rows[] = $row;

            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);

                throw ValidationException::withMessages([
                    'file' => 'More than '.number_format(self::MAX_ROWS).' rows. Split the sheet and import it in parts.',
                ]);
            }
        }

        fclose($handle);

        return [$rows, $map];
    }

    /**
     * The last ten digits of a number, so "+91 98765 43210", "098765-43210" and
     * "9876543210" are recognised as the same phone.
     */
    private function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 7 ? substr($digits, -10) : null;
    }

    /** @return array<string, true> */
    private function knownPhones(): array
    {
        $known = [];

        foreach (Lead::withTrashed()->whereNotNull('phone_normalized')->pluck('phone_normalized') as $digits) {
            $key = $this->phoneKey($digits);

            if ($key !== null) {
                $known[$key] = true;
            }
        }

        return $known;
    }

    /** Day first, as dates are written here; ISO as well, as a sheet may export it. */
    private function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'd/m/y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);

                if ($date !== false && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
                // Not this format; try the next.
            }
        }

        return null;
    }

    private function clip(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    /** @param array<string, int> $counts */
    private function summary(array $counts): string
    {
        $parts = ["Imported {$counts['imported']} ".($counts['imported'] === 1 ? 'lead' : 'leads').'.'];

        $skipped = array_filter([
            $counts['existing'] > 0 ? "{$counts['existing']} already in the system" : null,
            $counts['repeated'] > 0 ? "{$counts['repeated']} repeated in the file" : null,
            $counts['unnamed'] > 0 ? "{$counts['unnamed']} without a business name" : null,
        ]);

        if ($skipped !== []) {
            $parts[] = 'Skipped '.implode(', ', $skipped).'.';
        }

        return implode(' ', $parts);
    }

    /** @return list<array{id: int, name: string}> */
    private function ownerOptions(): array
    {
        return User::query()
            ->active()
            ->role([Roles::RELATIONSHIP_MANAGER, Roles::TELECALLER])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }
}
