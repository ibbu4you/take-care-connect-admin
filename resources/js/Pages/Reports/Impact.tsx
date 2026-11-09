import { Head, router } from '@inertiajs/react';
import { Download, ExternalLink, FileText, HeartHandshake, MapPin, Megaphone, Mic, PhoneCall, Store, UserSearch } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { EmptyState } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { date } from '@/lib/format';
import { cn } from '@/lib/utils';

interface Tally {
    label: string;
    value: number;
}

interface ImpactProps {
    window: { months: number; label: string };
    options: number[];
    totals: { reached: number; spoke_to: number; featured: number; stories: number; posts: number; cities: number };
    journey: Tally[];
    trend: Array<{ month: string; featured: number; stories: number }>;
    cities: Tally[];
    trades: Tally[];
    kinds: Tally[];
    stories: Array<{
        id: number;
        title: string;
        business: string | null;
        city: string | null;
        published_at: string | null;
        url: string | null;
    }>;
}

/**
 * What the work added up to.
 *
 * Written for somebody outside the team — a trustee, a donor, the annual
 * report — so it speaks in businesses and stories, never in pipeline or
 * workload terms, and it can be downloaded as a sheet.
 */
export default function Impact({ window, options, totals, journey, trend, cities, trades, kinds, stories }: ImpactProps) {
    const nothingYet = totals.reached === 0 && totals.featured === 0 && totals.stories === 0;

    return (
        <div className="space-y-6">
            <Head title="Impact" />

            <PageHeader
                title="Impact"
                subtitle="Small businesses reached, interviewed and featured — free of cost. The figures for a trustee, a donor or the annual report."
                actions={
                    <>
                        <div className="inline-flex h-10 items-center rounded-lg border border-border bg-muted p-1" role="radiogroup" aria-label="Period">
                            {options.map((months) => (
                                <button
                                    key={months}
                                    type="button"
                                    role="radio"
                                    aria-checked={window.months === months}
                                    onClick={() => router.get('/reports/impact', { months }, { preserveScroll: true, preserveState: true })}
                                    className={cn(
                                        'h-8 rounded-md px-3 text-sm font-medium transition-colors',
                                        window.months === months ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {months === 1 ? '1M' : `${months}M`}
                                </button>
                            ))}
                        </div>
                        <Button asChild variant="outline">
                            <a href={`/reports/impact/export?months=${window.months}`}>
                                <Download />
                                Download
                            </a>
                        </Button>
                    </>
                }
            />

            <StatGrid count={6}>
                <StatCard label="Businesses added" value={totals.reached} icon={UserSearch} tone="primary" sub={window.label} />
                <StatCard label="Spoken to" value={totals.spoke_to} icon={PhoneCall} tone="info" sub="A real conversation" />
                <StatCard label="Interviewed" value={totals.featured} icon={Mic} tone="violet" sub="Businesses featured" />
                <StatCard label="Stories published" value={totals.stories} icon={FileText} tone="success" sub="On the website" />
                <StatCard label="Social posts" value={totals.posts} icon={Megaphone} tone="warning" sub="Reels and posters out" />
                <StatCard label="Cities reached" value={totals.cities} icon={MapPin} tone="danger" sub="Where featured businesses are" />
            </StatGrid>

            {nothingYet ? (
                <Card>
                    <EmptyState
                        icon={HeartHandshake}
                        title="Nothing in this period yet"
                        description="As businesses are called, interviewed and featured, the figures appear here. Try a longer period."
                    />
                </Card>
            ) : (
                <>
                    <div className="grid items-start gap-6 xl:grid-cols-5">
                        <Card className="xl:col-span-2">
                            <CardHeader>
                                <CardTitle>From first call to published story</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <BarList rows={journey} tint="bg-primary" />
                                <p className="pt-1 text-xs text-muted-foreground">
                                    Each step is counted in {window.label.toLowerCase()} on its own — they are not the same businesses moving down a funnel.
                                </p>
                            </CardContent>
                        </Card>

                        <Card className="xl:col-span-3">
                            <CardHeader>
                                <CardTitle>The last twelve months</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="h-64">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart data={trend} margin={{ top: 4, right: 4, bottom: 0, left: -24 }} barGap={2}>
                                            <CartesianGrid vertical={false} stroke="hsl(var(--border))" strokeDasharray="4 4" />
                                            <XAxis dataKey="month" tickLine={false} axisLine={false} tick={{ fontSize: 12, fill: 'hsl(var(--muted-foreground))' }} />
                                            <YAxis allowDecimals={false} tickLine={false} axisLine={false} tick={{ fontSize: 12, fill: 'hsl(var(--muted-foreground))' }} />
                                            <Tooltip
                                                cursor={{ fill: 'hsl(var(--muted))' }}
                                                contentStyle={{
                                                    background: 'hsl(var(--popover))',
                                                    border: '1px solid hsl(var(--border))',
                                                    borderRadius: 8,
                                                    fontSize: 12,
                                                }}
                                            />
                                            <Legend iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12 }} />
                                            <Bar dataKey="featured" name="Businesses interviewed" fill="hsl(var(--primary))" radius={[4, 4, 0, 0]} />
                                            <Bar dataKey="stories" name="Stories published" fill="hsl(var(--chart-3))" radius={[4, 4, 0, 0]} />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <div className="grid items-start gap-6 lg:grid-cols-3">
                        <TallyCard title="Where they are" icon={MapPin} rows={cities} empty="No cities recorded yet." />
                        <TallyCard title="What they do" icon={Store} rows={trades} empty="No trades recorded yet." />
                        <TallyCard title="How they were featured" icon={Mic} rows={kinds} empty="No interviews in this period." />
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Recently published stories</CardTitle>
                        </CardHeader>
                        {stories.length === 0 ? (
                            <EmptyState icon={FileText} title="No stories published yet" />
                        ) : (
                            <ul className="divide-y divide-border">
                                {stories.map((story) => (
                                    <li key={story.id} className="flex items-center gap-3 px-5 py-3">
                                        <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-success/10 text-success" aria-hidden="true">
                                            <FileText className="size-4" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{story.title}</p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {[story.business, story.city, story.published_at && date(story.published_at)].filter(Boolean).join(' · ')}
                                            </p>
                                        </div>
                                        {story.url && (
                                            <a
                                                href={story.url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary hover:underline"
                                            >
                                                Read
                                                <ExternalLink className="size-3" aria-hidden="true" />
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </>
            )}
        </div>
    );
}

function TallyCard({
    title,
    icon: Icon,
    rows,
    empty,
}: {
    title: string;
    icon: typeof MapPin;
    rows: Tally[];
    empty: string;
}) {
    const total = rows.reduce((sum, row) => sum + row.value, 0);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Icon className="size-4 text-muted-foreground" aria-hidden="true" />
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent>{total === 0 ? <p className="text-sm text-muted-foreground">{empty}</p> : <BarList rows={rows} tint="bg-chart-2" />}</CardContent>
        </Card>
    );
}

/** Horizontal bars with the figure at the end — readable at a glance, and printable. */
function BarList({ rows, tint }: { rows: Tally[]; tint: string }) {
    const peak = Math.max(1, ...rows.map((row) => row.value));

    return (
        <ul className="space-y-2.5">
            {rows.map((row) => (
                <li key={row.label}>
                    <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
                        <span className="truncate">{row.label}</span>
                        <span className="shrink-0 font-medium tabular">{row.value}</span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className={cn('h-full rounded-full', row.value === 0 ? 'bg-transparent' : tint)}
                            style={{ width: `${(row.value / peak) * 100}%` }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}

Impact.layout = adminLayout;
