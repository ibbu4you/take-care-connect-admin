import { Head, router } from '@inertiajs/react';
import { Medal, Trophy } from 'lucide-react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Card } from '@/Components/ui/card';
import { Select } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { usePermissions } from '@/hooks/usePermissions';
import { adminLayout } from '@/Layouts/AdminLayout';
import { moneyCompact } from '@/lib/format';
import { cn } from '@/lib/utils';

interface Row {
    id: number;
    name: string;
    initials: string;
    won_value: number;
    won_count: number;
    meetings: number;
    proposals: number;
    calls: number;
    attainment: number | null;
    incentive: number | null;
}

interface LeaderboardProps {
    period: string;
    periodLabel: string;
    periods: Array<{ value: string; label: string }>;
    rows: Row[];
    commission: number;
}

const PODIUM = ['bg-chart-4/15 text-chart-4', 'bg-muted text-muted-foreground', 'bg-stage-revisions/15 text-stage-revisions'];

/**
 * Who is winning this month.
 *
 * Ranked on value won, with deals won, proposals and meetings behind it — so
 * an early-month board where nobody has closed yet still says who is closest.
 * Your own row is highlighted, whoever you are.
 */
export default function Leaderboard({ period, periodLabel, periods, rows, commission }: LeaderboardProps) {
    const { user } = usePermissions();
    const podium = rows.slice(0, 3);

    return (
        <div className="space-y-6">
            <Head title="Leaderboard" />

            <PageHeader
                title="Leaderboard"
                subtitle={`Who is winning in ${periodLabel}. Ranked on value won, then deals, proposals and meetings.`}
                actions={
                    <Select
                        value={period}
                        onChange={(event) => router.get('/sales/leaderboard', { period: event.target.value }, { preserveScroll: true })}
                        className="w-auto min-w-40"
                        aria-label="Month"
                    >
                        {periods.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                }
            />

            {rows.length === 0 ? (
                <Card>
                    <EmptyState icon={Trophy} title="Nobody in sales yet" description="People with a sales role appear here." />
                </Card>
            ) : (
                <>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {podium.map((row, index) => (
                            <Card key={row.id} className={cn('flex items-center gap-4 p-4', row.id === user?.id && 'ring-2 ring-primary/30')}>
                                <span className={cn('inline-flex size-11 shrink-0 items-center justify-center rounded-full', PODIUM[index])} aria-hidden="true">
                                    {index === 0 ? <Trophy className="size-5" /> : <Medal className="size-5" />}
                                </span>
                                <div className="min-w-0">
                                    <p className="text-xs font-medium uppercase tracking-[0.04em] text-muted-foreground">
                                        {['First', 'Second', 'Third'][index]}
                                    </p>
                                    <p className="truncate font-medium">{row.name}</p>
                                    <p className="text-lg font-medium tabular">
                                        {moneyCompact(row.won_value)}{' '}
                                        <span className="text-xs font-normal text-muted-foreground">
                                            · {row.won_count} {row.won_count === 1 ? 'deal' : 'deals'}
                                        </span>
                                    </p>
                                </div>
                            </Card>
                        ))}
                    </div>

                    <Card className="overflow-hidden">
                        <TableWrap>
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH className="w-12">#</TH>
                                        <TH>Person</TH>
                                        <TH className="text-right">Won</TH>
                                        <TH className="text-right">Deals</TH>
                                        <TH className="text-right">Proposals sent</TH>
                                        <TH className="text-right">Meetings held</TH>
                                        <TH className="text-right">Calls</TH>
                                        <TH className="text-right">Target</TH>
                                        {commission > 0 && <TH className="text-right">Incentive</TH>}
                                    </TR>
                                </THead>
                                <TBody>
                                    {rows.map((row, index) => (
                                        <TR key={row.id} className={cn(row.id === user?.id && 'bg-primary/5 hover:bg-primary/10')}>
                                            <TD className="font-medium tabular text-muted-foreground">{index + 1}</TD>
                                            <TD>
                                                <div className="flex items-center gap-2">
                                                    <Avatar name={row.name} initials={row.initials} size="sm" />
                                                    <span className="truncate text-sm font-medium">
                                                        {row.name}
                                                        {row.id === user?.id && <span className="ml-1.5 text-xs font-normal text-primary">You</span>}
                                                    </span>
                                                </div>
                                            </TD>
                                            <TD className="text-right font-medium tabular">{moneyCompact(row.won_value)}</TD>
                                            <TD className="text-right tabular">{row.won_count}</TD>
                                            <TD className="text-right tabular">{row.proposals}</TD>
                                            <TD className="text-right tabular">{row.meetings}</TD>
                                            <TD className="text-right tabular">{row.calls}</TD>
                                            <TD className="text-right tabular">
                                                {row.attainment === null ? (
                                                    <span className="text-xs text-muted-foreground">Not set</span>
                                                ) : (
                                                    <span className={cn('font-medium', row.attainment >= 100 ? 'text-success' : row.attainment >= 60 ? 'text-warning' : '')}>
                                                        {row.attainment}%
                                                    </span>
                                                )}
                                            </TD>
                                            {commission > 0 && <TD className="text-right font-medium tabular text-success">{moneyCompact(row.incentive ?? 0)}</TD>}
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </TableWrap>
                    </Card>
                </>
            )}
        </div>
    );
}

Leaderboard.layout = adminLayout;
