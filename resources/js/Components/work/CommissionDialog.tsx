import { useForm } from '@inertiajs/react';
import {
    Check,
    CircleDashed,
    Film,
    GalleryHorizontalEnd,
    Image as ImageIcon,
    MonitorPlay,
    Smartphone,
    Youtube,
    type LucideIcon,
} from 'lucide-react';
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

const KIND_ICONS: Record<string, LucideIcon> = {
    poster: ImageIcon,
    reel: Smartphone,
    video_edit: Film,
    carousel: GalleryHorizontalEnd,
    story: CircleDashed,
    thumbnail: MonitorPlay,
    short: Youtube,
};

const PLATFORM_LABELS: Record<string, string> = {
    instagram: 'Instagram',
    youtube: 'YouTube',
    facebook: 'Facebook',
    linkedin: 'LinkedIn',
};

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
    const many = data.quantity > 1;

    return (
        <Dialog
            open={open}
            onClose={onClose}
            size="xl"
            title="Request creative work"
            description="Posters, reels and edits for the creative team to produce."
            footer={
                <>
                    {selected && (
                        <p className="mr-auto hidden text-xs text-muted-foreground sm:block">
                            Goes to {selected.platforms.map((p) => PLATFORM_LABELS[p] ?? p).join(' and ')} by default. The creative can change that.
                        </p>
                    )}
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
                        {many ? `Request ${data.quantity}` : 'Request'}
                    </Button>
                </>
            }
        >
            <div className="space-y-6">
                <fieldset>
                    <legend className="mb-3 text-sm font-semibold">What is needed?</legend>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        {kinds.map((kind) => {
                            const Icon = KIND_ICONS[kind.value] ?? ImageIcon;
                            const active = data.kind === kind.value;

                            return (
                                <label
                                    key={kind.value}
                                    className={cn(
                                        'group relative flex cursor-pointer items-center gap-2.5 rounded-xl border p-2.5 transition-all md:flex-col md:items-start md:gap-3 md:p-4',
                                        'has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-primary/15',
                                        active
                                            ? 'border-primary bg-primary/5 shadow-sm'
                                            : 'border-border hover:border-primary/40 hover:bg-muted/40',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="kind"
                                        value={kind.value}
                                        checked={active}
                                        onChange={(event) => setData('kind', event.target.value)}
                                        className="sr-only"
                                    />
                                    <span
                                        className={cn(
                                            'flex size-8 shrink-0 items-center justify-center rounded-lg transition-colors md:size-10',
                                            active
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted text-muted-foreground group-hover:text-foreground',
                                        )}
                                    >
                                        <Icon className="size-4 md:size-5" />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-sm font-semibold leading-tight">{kind.label}</span>
                                        <span className="mt-0.5 hidden text-xs leading-snug text-muted-foreground md:block">
                                            {kind.description}
                                        </span>
                                    </span>
                                    {active && (
                                        <span className="absolute right-2 top-2 hidden size-5 items-center justify-center rounded-full bg-primary text-primary-foreground md:right-3 md:top-3 md:flex">
                                            <Check className="size-3" strokeWidth={3} />
                                        </span>
                                    )}
                                </label>
                            );
                        })}
                    </div>
                    {errors.kind && <p className="mt-1 text-xs font-medium text-destructive">{errors.kind}</p>}
                </fieldset>

                <div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Field label="How many?" htmlFor="quantity" error={errors.quantity}>
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

                    {selected && many && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Numbered 1 to {data.quantity}, so the team can say &ldquo;{selected.label.toLowerCase()} 2&rdquo;.
                        </p>
                    )}
                </div>

                <Field label="Brief" htmlFor="commission_brief" error={errors.brief}>
                    <Textarea
                        id="commission_brief"
                        value={data.brief}
                        onChange={(event) => setData('brief', event.target.value)}
                        rows={4}
                        placeholder="Pull the quote about the second year. Keep it under 45 seconds."
                    />
                </Field>
            </div>
        </Dialog>
    );
}
