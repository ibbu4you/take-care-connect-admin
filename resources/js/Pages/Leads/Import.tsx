import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Download, FileSpreadsheet, Upload } from 'lucide-react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Field, Select } from '@/Components/ui/input';
import { adminLayout } from '@/Layouts/AdminLayout';
import { cn } from '@/lib/utils';

interface ImportProps {
    sources: Array<{ value: string; label: string }>;
    owners: Array<{ id: number; name: string }>;
    columns: string[];
    maxRows: number;
}

/** What each recognised column means, in the words a sheet would use. */
const COLUMN_HELP: Record<string, string> = {
    name: 'Business name — the only column that must be there',
    contact_person: 'Contact person / owner',
    phone: 'Phone / mobile',
    whatsapp: 'WhatsApp',
    email: 'Email',
    city: 'City / town',
    state: 'State',
    trade: 'Trade / category',
    notes: 'Notes / remarks',
    next_action_on: 'Next call (as 15/11/2026)',
};

export default function LeadImport({ sources, owners, columns, maxRows }: ImportProps) {
    const { data, setData, post, processing, errors, progress } = useForm<{
        file: File | null;
        source: string;
        owner_id: string;
    }>({
        file: null,
        source: sources.find((source) => source.value === 'manual')?.value ?? sources[0]?.value ?? '',
        owner_id: '',
    });

    return (
        <div className="space-y-6">
            <Head title="Import leads" />

            <PageHeader
                title="Import leads"
                subtitle="Turn a spreadsheet of businesses into leads in one step. Anyone already in the system is skipped, not duplicated."
                actions={
                    <Button asChild variant="outline">
                        <Link href="/leads">
                            <ArrowLeft />
                            Back to leads
                        </Link>
                    </Button>
                }
            />

            <div className="grid items-start gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Upload</CardTitle>
                    </CardHeader>

                    <CardContent>
                        <form
                            className="space-y-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                post('/leads/import', { forceFormData: true });
                            }}
                        >
                            <Field label="Spreadsheet" htmlFor="file" error={errors.file} required>
                                <label
                                    htmlFor="file"
                                    className={cn(
                                        'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors',
                                        data.file ? 'border-primary/40 bg-primary/5' : 'border-border hover:border-primary/40 hover:bg-muted/50',
                                        errors.file && 'border-destructive/50',
                                    )}
                                >
                                    <span className="inline-flex size-11 items-center justify-center rounded-full bg-primary/10 text-primary">
                                        <FileSpreadsheet className="size-5" aria-hidden="true" />
                                    </span>
                                    {data.file ? (
                                        <>
                                            <span className="text-sm font-medium">{data.file.name}</span>
                                            <span className="text-xs text-muted-foreground">Choose a different file</span>
                                        </>
                                    ) : (
                                        <>
                                            <span className="text-sm font-medium">Choose a CSV file</span>
                                            <span className="text-xs text-muted-foreground">
                                                From Excel or Google Sheets: File → Save as / Download → CSV. Up to{' '}
                                                {maxRows.toLocaleString('en-IN')} rows.
                                            </span>
                                        </>
                                    )}
                                    <input
                                        id="file"
                                        type="file"
                                        accept=".csv,text/csv"
                                        className="sr-only"
                                        onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                                    />
                                </label>
                            </Field>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Where did this list come from?"
                                    htmlFor="source"
                                    error={errors.source}
                                    hint="Applied to every lead in the file."
                                    required
                                >
                                    <Select id="source" value={data.source} onChange={(event) => setData('source', event.target.value)}>
                                        {sources.map((source) => (
                                            <option key={source.value} value={source.value}>
                                                {source.label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>

                                {owners.length > 0 && (
                                    <Field label="Give them to" htmlFor="owner_id" error={errors.owner_id} hint="Who will call them.">
                                        <Select id="owner_id" value={data.owner_id} onChange={(event) => setData('owner_id', event.target.value)}>
                                            <option value="">Me</option>
                                            {owners.map((owner) => (
                                                <option key={owner.id} value={owner.id}>
                                                    {owner.name}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                )}
                            </div>

                            {progress && (
                                <div className="h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                    <div className="h-full bg-primary transition-[width]" style={{ width: `${progress.percentage ?? 0}%` }} />
                                </div>
                            )}

                            <div className="flex justify-end">
                                <Button type="submit" loading={processing} disabled={!data.file}>
                                    <Upload />
                                    Import leads
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Columns it reads</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <ul className="space-y-2 text-sm">
                            {columns.map((column) => (
                                <li key={column} className="flex gap-2">
                                    <span
                                        className={cn('mt-1.5 size-1.5 shrink-0 rounded-full', column === 'name' ? 'bg-primary' : 'bg-border')}
                                        aria-hidden="true"
                                    />
                                    <span className={cn(column === 'name' && 'font-medium')}>{COLUMN_HELP[column] ?? column}</span>
                                </li>
                            ))}
                        </ul>

                        <p className="text-xs text-muted-foreground">
                            Headings are matched loosely — "Mobile No.", "Phone" and "Contact number" all work. Other columns are ignored.
                            A row whose phone number is already on a lead is skipped.
                        </p>

                        <Button asChild variant="outline" size="sm" className="w-full rounded-lg">
                            <a href="/leads/import/template">
                                <Download />
                                Download a template
                            </a>
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

LeadImport.layout = adminLayout;
