import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Target as TargetIcon } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Dialog } from '@/Components/ui/dialog';
import { Field, Input, Select } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import { money, moneyCompact } from '@/lib/format';
import { cn } from '@/lib/utils';

interface Row {
    user: { id: number; name: string; initials: string };
    revenue_target: number | null;
    deals_target: number | null;
    interviews_target: number | null;
    revenue_actual: number;
    deals_actual: number;
    interviews_actual: number;
    attainment: number | null;
    /** Null when incentives are switched off in Settings. */
    incentive: number | null;
    note: string | null;
}

interface IndexProps {
    period: string;
    periodLabel: string;
    periods: Array<{ value: string; label: string }>;
    rows: Row[];
    totals: { revenue_target: number; revenue_actual: number };
    commission: number;
    can: { manage: boolean };
}

export default function TargetsIndex({ period, periodLabel, periods, rows, totals, commission, can }: IndexProps) {
    const [editing, setEditing] = useState<Row | null>(null);

    const teamAttainment =
        totals.revenue_target > 0 ? Math.round((totals.revenue_actual / totals.revenue_target) * 100) : null;

    return (
        <div className="space-y-6">
            <Head title="Targets" />

            <PageHeader
                title="Targets"
                subtitle="Monthly revenue targets and how everyone is tracking. Attainment is summed from closed deals, never stored."
                actions={
                    <Select
                        value={period}
                        onChange={(event) =>
                            router.get('/targets', { period: event.target.value }, { preserveState: true })
                        }
                        className="w-auto min-w-40"
                        aria-label="Choose a month"
                    >
                        {periods.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                }
            />

            {totals.revenue_target > 0 && (
                <Card>
                    <CardContent className="py-4">
                        <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                            <span className="text-sm font-medium">{periodLabel} · the whole team</span>
                            <span className="text-sm tabular">
                                <span className="font-bold">{money(totals.revenue_actual)}</span>
                                <span className="text-muted-foreground"> of {money(totals.revenue_target)}</span>
                            </span>
                        </div>

                        <div className="h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn('h-full rounded-full transition-all', barTone(teamAttainment))}
                                style={{ width: `${Math.min(teamAttainment ?? 0, 100)}%` }}
                            />
                        </div>
                    </CardContent>
                </Card>
            )}

            <Card>
                {rows.length === 0 ? (
                    <EmptyState
                        icon={TargetIcon}
                        title="Nobody to show"
                        description="Targets are set for relationship managers."
                    />
                ) : (
                    <TableWrap>
                        <Table>
                            <THead>
                                <TR className="hover:bg-transparent">
                                    <TH>Person</TH>
                                    <TH>Revenue</TH>
                                    <TH>Attainment</TH>
                                    <TH>Deals</TH>
                                    <TH>Interviews booked</TH>
                                    {commission > 0 && <TH className="text-right">Incentive ({commission}%)</TH>}
                                    {can.manage && <TH />}
                                </TR>
                            </THead>
                            <TBody>
                                {rows.map((row) => (
                                    <TR key={row.user.id}>
                                        <TD>
                                            <div className="flex items-center gap-2">
                                                <Avatar name={row.user.name} initials={row.user.initials} size="sm" />
                                                <span className="truncate text-sm font-medium">{row.user.name}</span>
                                            </div>
                                        </TD>

                                        <TD>
                                            <span className="whitespace-nowrap text-sm tabular">
                                                <span className="font-medium">{moneyCompact(row.revenue_actual)}</span>
                                                {row.revenue_target !== null && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        of {moneyCompact(row.revenue_target)}
                                                    </span>
                                                )}
                                            </span>
                                        </TD>

                                        <TD className="min-w-40">
                                            {/*
                                              | No target is not zero attainment.
                                              |
                                              | Drawing an empty red bar against a
                                              | target nobody set reads as failure
                                              | rather than as a gap in the setup.
                                            */}
                                            {row.attainment === null ? (
                                                <span className="text-xs text-muted-foreground">No target set</span>
                                            ) : (
                                                <div className="flex items-center gap-2">
                                                    <div className="h-1.5 w-24 overflow-hidden rounded-full bg-muted">
                                                        <div
                                                            className={cn('h-full rounded-full', barTone(row.attainment))}
                                                            style={{ width: `${Math.min(row.attainment, 100)}%` }}
                                                        />
                                                    </div>
                                                    <span
                                                        className={cn('text-xs font-semibold tabular', textTone(row.attainment))}
                                                    >
                                                        {row.attainment}%
                                                    </span>
                                                </div>
                                            )}
                                        </TD>

                                        <TD>
                                            <span className="text-sm tabular">
                                                {row.deals_actual}
                                                {row.deals_target !== null && (
                                                    <span className="text-muted-foreground"> / {row.deals_target}</span>
                                                )}
                                            </span>
                                        </TD>

                                        <TD>
                                            <span className="text-sm tabular">
                                                {row.interviews_actual}
                                                {row.interviews_target !== null && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        / {row.interviews_target}
                                                    </span>
                                                )}
                                            </span>
                                        </TD>

                                        {commission > 0 && (
                                            <TD className="text-right">
                                                <span className="text-sm font-medium tabular text-success">
                                                    {moneyCompact(row.incentive ?? 0)}
                                                </span>
                                            </TD>
                                        )}

                                        {can.manage && (
                                            <TD>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Set target for ${row.user.name}`}
                                                    onClick={() => setEditing(row)}
                                                >
                                                    <Pencil />
                                                </Button>
                                            </TD>
                                        )}
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </TableWrap>
                )}
            </Card>

            {editing && (
                <TargetDialog row={editing} period={period} periodLabel={periodLabel} onClose={() => setEditing(null)} />
            )}
        </div>
    );
}

function barTone(attainment: number | null): string {
    if (attainment === null) return 'bg-muted';
    if (attainment >= 100) return 'bg-success';
    if (attainment >= 60) return 'bg-warning';

    return 'bg-destructive';
}

function textTone(attainment: number): string {
    if (attainment >= 100) return 'text-success';
    if (attainment >= 60) return 'text-warning';

    return 'text-destructive';
}

function TargetDialog({
    row,
    period,
    periodLabel,
    onClose,
}: {
    row: Row;
    period: string;
    periodLabel: string;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        user_id: row.user.id,
        period,
        revenue_target: row.revenue_target !== null ? String(row.revenue_target) : '',
        deals_target: row.deals_target !== null ? String(row.deals_target) : '',
        interviews_target: row.interviews_target !== null ? String(row.interviews_target) : '',
        note: row.note ?? '',
    });

    return (
        <Dialog
            open
            onClose={onClose}
            title={`${row.user.name} · ${periodLabel}`}
            description="Leave a field blank to set no target for it."
            size="sm"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() => post('/targets', { preserveScroll: true, onSuccess: onClose })}
                    >
                        Save target
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Revenue target" htmlFor="revenue_target" error={errors.revenue_target} hint="In rupees.">
                    <Input
                        id="revenue_target"
                        type="number"
                        min={0}
                        value={data.revenue_target}
                        onChange={(event) => setData('revenue_target', event.target.value)}
                        invalid={Boolean(errors.revenue_target)}
                    />
                </Field>

                <Field label="Deals to close" htmlFor="deals_target" error={errors.deals_target}>
                    <Input
                        id="deals_target"
                        type="number"
                        min={0}
                        value={data.deals_target}
                        onChange={(event) => setData('deals_target', event.target.value)}
                    />
                </Field>

                <Field label="Interviews to book" htmlFor="interviews_target" error={errors.interviews_target}>
                    <Input
                        id="interviews_target"
                        type="number"
                        min={0}
                        value={data.interviews_target}
                        onChange={(event) => setData('interviews_target', event.target.value)}
                    />
                </Field>

                <Field label="Note" htmlFor="target_note" error={errors.note}>
                    <Input
                        id="target_note"
                        value={data.note}
                        onChange={(event) => setData('note', event.target.value)}
                        placeholder="Stretch month — two big renewals due."
                    />
                </Field>
            </div>
        </Dialog>
    );
}

TargetsIndex.layout = adminLayout;
