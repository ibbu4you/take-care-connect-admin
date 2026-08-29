import { useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { cn } from '@/lib/utils';

export interface KindOption {
    value: string;
    label: string;
    description: string;
    platforms: string[];
    is_video: boolean;
}

interface CommissionDialogProps {
    open: boolean;
    onClose: () => void;
    parentKind: 'interview' | 'article';
    parentId: number;
    kinds: KindOption[];
    creatives: Array<{ id: number; name: string }>;
    defaultDueOn?: string | null;
}

/**
 * Commission creative work against an interview or an article.
 *
 * Shared by both pages because the request is identical from either side — and
 * because a deliverable commissioned from an interview is the *only* route the
 * two social-only interview kinds have to produce anything at all.
 *
 * Quantity is on the form because "three reels and two posters" is one decision
 * the team makes once, not three trips through a dialog. The slot numbers that
 * come out of it are what let people say "reel 2" afterwards and be understood.
 */
export function CommissionDialog({
    open,
    onClose,
    parentKind,
    parentId,
    kinds,
    creatives,
    defaultDueOn,
}: CommissionDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        parent_type: parentKind,
        parent_id: parentId,
        kind: 'reel',
        title: '',
        quantity: 1,
        assigned_to: '',
        due_on: defaultDueOn ?? '',
        priority: 'normal',
        brief: '',
    });

    const selected = kinds.find((kind) => kind.value === data.kind);

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Commission creative work"
            description="Posters, reels and edits for the creative team to produce."
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() =>
                            post('/deliverables', {
                                preserveScroll: true,
                                onSuccess: () => {
                                    reset();
                                    onClose();
                                },
                            })
                        }
                    >
                        Commission
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <fieldset>
                    <legend className="mb-2 text-sm font-medium">What is needed?</legend>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {kinds.map((kind) => (
                            <label
                                key={kind.value}
                                className={cn(
                                    'flex cursor-pointer items-start gap-2 rounded-control border p-2.5 transition-colors',
                                    data.kind === kind.value
                                        ? 'border-primary/40 bg-primary/5'
                                        : 'border-border hover:bg-muted/50',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="kind"
                                    value={kind.value}
                                    checked={data.kind === kind.value}
                                    onChange={(event) => setData('kind', event.target.value)}
                                    className="mt-0.5 size-4 border-input text-primary focus-visible:ring-2 focus-visible:ring-ring"
                                />
                                <span className="min-w-0">
                                    <span className="block text-sm font-medium">{kind.label}</span>
                                    <span className="block text-xs text-muted-foreground">{kind.description}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    {errors.kind && <p className="mt-1 text-xs font-medium text-destructive">{errors.kind}</p>}
                </fieldset>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="How many?"
                        htmlFor="quantity"
                        error={errors.quantity}
                        hint={
                            selected
                                ? `Numbered so the team can say "${selected.label.toLowerCase()} 2".`
                                : undefined
                        }
                    >
                        <Input
                            id="quantity"
                            type="number"
                            min={1}
                            max={10}
                            value={data.quantity}
                            onChange={(event) => setData('quantity', Number(event.target.value))}
                            invalid={Boolean(errors.quantity)}
                        />
                    </Field>

                    <Field label="Who is making it?" htmlFor="assigned_to" error={errors.assigned_to}>
                        <Select
                            id="assigned_to"
                            value={data.assigned_to}
                            onChange={(event) => setData('assigned_to', event.target.value)}
                        >
                            <option value="">Leave unassigned</option>
                            {creatives.map((creative) => (
                                <option key={creative.id} value={creative.id}>
                                    {creative.name}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <Field label="Due" htmlFor="commission_due_on" error={errors.due_on}>
                        <Input
                            id="commission_due_on"
                            type="date"
                            value={data.due_on}
                            onChange={(event) => setData('due_on', event.target.value)}
                            invalid={Boolean(errors.due_on)}
                        />
                    </Field>

                    <Field label="Priority" htmlFor="commission_priority" error={errors.priority}>
                        <Select
                            id="commission_priority"
                            value={data.priority}
                            onChange={(event) => setData('priority', event.target.value)}
                        >
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                        </Select>
                    </Field>
                </div>

                <Field label="Brief" htmlFor="commission_brief" error={errors.brief}>
                    <Textarea
                        id="commission_brief"
                        value={data.brief}
                        onChange={(event) => setData('brief', event.target.value)}
                        rows={3}
                        placeholder="Pull the quote about the second year. Keep it under 45 seconds."
                    />
                </Field>

                {selected && (
                    <p className="text-xs text-muted-foreground">
                        Goes to {selected.platforms.join(' and ')} by default — the creative can change that.
                    </p>
                )}
            </div>
        </Dialog>
    );
}
