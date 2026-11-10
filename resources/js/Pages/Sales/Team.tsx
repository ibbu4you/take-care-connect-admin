import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, BarChart3, CircleDollarSign, Filter, MapPin, Target, TrendingUp, Trophy, Users } from 'lucide-react';
import { useState } from 'react';
import { Pill } from '@/Components/dashboard/parts';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { PageHeader } from '@/Components/layout/PageHeader';
import { StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import { moneyCompact } from '@/lib/format';
import { cn } from '@/lib/utils';

interface TeamProps {
    summary: {
        pipeline: number;
        weighted: number;
        forecast: number;
        won_value: number;
        won_count: number;
        target: number | null;
        win_rate: number | null;
        stuck: number;
    };
    people: Array<{
        id: number;
        name: string;
        initials: string;
        open: number;
        pipeline: number;
        weighted: number;
        won_value: number;
        attainment: number | null;
        activities: number;
        awaiting: number;
        stuck: number;
    }>;
    forecast: Array<{ month: string; weighted: number; count: number }>;
    funnel: Array<{ label: string; count: number }>;
    lostReasons: Array<{ reason: string; count: number }>;
    stuck: Array<{ id: number; code: string; title: string; client: string | null; owner: string | null; stage: string; value: number; reason: string }>;
    cities: Array<{ city: string; count: number; owners: Array<{ name: string; count: number }> }>;
    sellers: Array<{ id: number; name: string }>;
    can: { assign: boolean };
}

/**
 * The sales team at a glance, for whoever runs it.
 *
 * Read top to bottom it answers, in order: are we on track this month; who is
 * carrying what; what is stuck and why; where deals are lost; and who should
 * call the new leads in each city.
 */
export default function SalesTeam({ summary, people, forecast, funnel, lostReasons, stuck, cities, sellers, can }: TeamProps) {
    const attainment = summary.target ? Math.round((summary.won_value / summary.target) * 100) : null;

    return (
        <div className="space-y-6">
            <Head title="Sales team" />

            <PageHeader title="Sales team" subtitle="Where the team stands this month, what is stuck, and who should call the new leads." />

            <StatGrid count={4}>
                <StatCard
                    label="Won this month"
                    value={moneyCompact(summary.won_value)}
                    icon={Trophy}
                    tone="success"
                    sub={summary.target ? `${attainment}% of the ${moneyCompact(summary.target)} team target` : `${summary.won_count} deals · no team target set`}
                />
                <StatCard
                    label="Forecast this month"
                    value={moneyCompact(summary.forecast)}
                    icon={TrendingUp}
                    tone="primary"
                    sub="Won so far + open deals due this month, weighted"
                />
                <StatCard label="Open pipeline" value={moneyCompact(summary.pipeline)} icon={CircleDollarSign} tone="info" sub={`${moneyCompact(summary.weighted)} weighted`} />
                <StatCard
                    label="Needs attention"
                    value={summary.stuck}
                    icon={AlertTriangle}
                    tone={summary.stuck > 0 ? 'danger' : 'success'}
                    sub={summary.win_rate !== null ? `Win rate ${summary.win_rate}% over 90 days` : 'Overdue or no next step'}
                />
            </StatGrid>

            <Card className="overflow-hidden">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Users className="size-4 text-muted-foreground" aria-hidden="true" />
                        Who is carrying what
                    </CardTitle>
                </CardHeader>
                {people.length === 0 ? (
                    <EmptyState icon={Users} title="Nobody in sales yet" description="Give people the Sales Executive or Sales Manager role." />
                ) : (
                    <TableWrap>
                        <Table>
                            <THead>
                                <TR className="hover:bg-transparent">
                                    <TH>Person</TH>
                                    <TH className="text-right">Open deals</TH>
                                    <TH className="text-right">Pipeline</TH>
                                    <TH className="text-right">Weighted</TH>
                                    <TH className="text-right">Won this month</TH>
                                    <TH className="text-right">Target</TH>
                                    <TH className="text-right">Activity, 7 days</TH>
                                    <TH className="text-right">Awaiting answer</TH>
                                    <TH className="text-right">Stuck</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {people.map((person) => (
                                    <TR key={person.id}>
                                        <TD>
                                            <div className="flex items-center gap-2">
                                                <Avatar name={person.name} initials={person.initials} size="sm" />
                                                <span className="truncate text-sm font-medium">{person.name}</span>
                                            </div>
                                        </TD>
                                        <TD className="text-right tabular">{person.open}</TD>
                                        <TD className="text-right tabular">{moneyCompact(person.pipeline)}</TD>
                                        <TD className="text-right tabular text-muted-foreground">{moneyCompact(person.weighted)}</TD>
                                        <TD className="text-right font-medium tabular">{moneyCompact(person.won_value)}</TD>
                                        <TD className="text-right tabular">
                                            {person.attainment === null ? <span className="text-xs text-muted-foreground">Not set</span> : `${person.attainment}%`}
                                        </TD>
                                        <TD className={cn('text-right tabular', person.activities === 0 && 'text-destructive')}>{person.activities}</TD>
                                        <TD className="text-right tabular">{person.awaiting}</TD>
                                        <TD className={cn('text-right tabular', person.stuck > 0 && 'font-medium text-destructive')}>{person.stuck}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </TableWrap>
                )}
            </Card>

            <div className="grid items-start gap-6 lg:grid-cols-2">
                <Card className="overflow-hidden">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <AlertTriangle className="size-4 text-muted-foreground" aria-hidden="true" />
                            Stuck deals
                        </CardTitle>
                    </CardHeader>
                    {stuck.length === 0 ? (
                        <EmptyState icon={AlertTriangle} title="Nothing stuck" description="Every open deal has a next step that is not overdue." />
                    ) : (
                        <ul className="divide-y divide-border">
                            {stuck.map((deal) => (
                                <li key={deal.id}>
                                    <Link href={`/deals/${deal.id}`} className="group flex items-center gap-3 px-5 py-3 hover:bg-muted/40">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium group-hover:text-primary">{deal.title}</p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {[deal.client, deal.owner, moneyCompact(deal.value)].filter(Boolean).join(' · ')}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 flex-col items-end gap-1">
                                            <StageBadge family="pipeline" stage={deal.stage} />
                                            <span className="text-xs font-medium text-destructive">{deal.reason}</span>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Target className="size-4 text-muted-foreground" aria-hidden="true" />
                                Coming in
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <Bars rows={forecast.map((m) => ({ label: m.month, value: m.weighted, note: `${m.count} ${m.count === 1 ? 'deal' : 'deals'}` }))} money />
                            <p className="text-xs text-muted-foreground">Open deals by expected close month, weighted by stage. Deals with no date are worth dating.</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Filter className="size-4 text-muted-foreground" aria-hidden="true" />
                                How far deals get · 90 days
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Bars rows={funnel.map((step) => ({ label: step.label, value: step.count }))} />
                        </CardContent>
                    </Card>
                </div>
            </div>

            <div className="grid items-start gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <BarChart3 className="size-4 text-muted-foreground" aria-hidden="true" />
                            Why deals are lost · 90 days
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {lostReasons.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No deals lost in the last 90 days.</p>
                        ) : (
                            <Bars rows={lostReasons.map((row) => ({ label: row.reason, value: row.count }))} tint="bg-destructive/70" />
                        )}
                    </CardContent>
                </Card>

                <CityCard cities={cities} sellers={sellers} canAssign={can.assign} />
            </div>
        </div>
    );
}

/** Horizontal bars with the figure at the end. */
function Bars({ rows, money = false, tint = 'bg-primary' }: { rows: Array<{ label: string; value: number; note?: string }>; money?: boolean; tint?: string }) {
    const peak = Math.max(1, ...rows.map((row) => row.value));

    return (
        <ul className="space-y-2.5">
            {rows.map((row) => (
                <li key={row.label}>
                    <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
                        <span className="truncate">{row.label}</span>
                        <span className="shrink-0 font-medium tabular">
                            {money ? moneyCompact(row.value) : row.value}
                            {row.note && <span className="ml-1.5 text-xs font-normal text-muted-foreground">{row.note}</span>}
                        </span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div className={cn('h-full rounded-full', row.value === 0 ? 'bg-transparent' : tint)} style={{ width: `${(row.value / peak) * 100}%` }} />
                    </div>
                </li>
            ))}
        </ul>
    );
}

/**
 * New leads by city, and handing a city to one salesperson.
 *
 * Only leads nobody has spoken to move — a lead somebody is already talking to
 * stays with them.
 */
function CityCard({ cities, sellers, canAssign }: Pick<TeamProps, 'cities' | 'sellers'> & { canAssign: boolean }) {
    const [choice, setChoice] = useState<Record<string, string>>({});

    return (
        <Card className="overflow-hidden">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <MapPin className="size-4 text-muted-foreground" aria-hidden="true" />
                    New leads by city
                </CardTitle>
            </CardHeader>
            {cities.length === 0 ? (
                <EmptyState icon={MapPin} title="No new leads waiting" description="Leads nobody has spoken to yet appear here, grouped by city." />
            ) : (
                <>
                    <ul className="divide-y divide-border">
                        {cities.map((row) => (
                            <li key={row.city} className="flex flex-wrap items-center gap-3 px-5 py-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium">
                                        {row.city} <Pill tone="info" className="ml-1">{row.count}</Pill>
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {row.owners.map((owner) => `${owner.name} ${owner.count}`).join(' · ')}
                                    </p>
                                </div>
                                {canAssign && (
                                    <div className="flex items-center gap-2">
                                        <Select
                                            value={choice[row.city] ?? ''}
                                            onChange={(event) => setChoice({ ...choice, [row.city]: event.target.value })}
                                            className="h-8 w-40 text-xs"
                                            aria-label={`Give ${row.city} leads to`}
                                        >
                                            <option value="">Give to…</option>
                                            {sellers.map((seller) => (
                                                <option key={seller.id} value={seller.id}>
                                                    {seller.name}
                                                </option>
                                            ))}
                                        </Select>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="rounded-lg"
                                            disabled={!choice[row.city]}
                                            onClick={() =>
                                                router.post('/sales/team/assign-city', { city: row.city, user_id: choice[row.city] }, { preserveScroll: true })
                                            }
                                        >
                                            Hand over
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                    <p className="border-t border-border px-5 py-3 text-xs text-muted-foreground">
                        Moves only leads nobody has spoken to yet. Anything already in conversation stays with whoever is having it.
                    </p>
                </>
            )}
        </Card>
    );
}

SalesTeam.layout = adminLayout;
