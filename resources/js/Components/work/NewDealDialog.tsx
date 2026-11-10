import { useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';

/*
 | Opening a deal.
 |
 | ── Why this is a shared component ─────────────────────────────────────────
 | It lived inside the board page, which was fine while the board was the only
 | way into Sales. The list is the landing view now, and a list you cannot add
 | to is a list that sends people to the board to do one thing and then back
 | again.
 |
 | Copying it into the second page would have been quicker and would have left
 | two dialogs to keep in step — the sort of pair that agrees until the day
 | somebody adds a field to one of them.
 */
export function NewDealDialog({
    open,
    onClose,
    clients,
    stages,
}: {
    open: boolean;
    onClose: () => void;
    clients: Array<{ id: number; name: string }>;
    stages: Array<{ value: string; label: string }>;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        client_id: '',
        title: '',
        stage: 'new',
        expected_value: '',
        expected_close_on: '',
        notes: '',
    });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="New deal"
            description="A conversation worth tracking, against a client."
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() =>
                            post('/deals', {
                                preserveScroll: true,
                                onSuccess: () => {
                                    reset();
                                    onClose();
                                },
                            })
                        }
                    >
                        Open deal
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                {clients.length === 0 ? (
                    <p className="rounded-control bg-warning/10 p-3 text-sm text-warning">
                        You have no clients yet. Add one first — a deal always belongs to somebody.
                    </p>
                ) : (
                    <Field label="Client" htmlFor="client_id" error={errors.client_id} required>
                        <Select
                            id="client_id"
                            value={data.client_id}
                            onChange={(event) => setData('client_id', event.target.value)}
                            invalid={Boolean(errors.client_id)}
                        >
                            <option value="">Choose a client…</option>
                            {clients.map((client) => (
                                <option key={client.id} value={client.id}>
                                    {client.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                )}

                <Field label="What is it?" htmlFor="deal_title" error={errors.title} required>
                    <Input
                        id="deal_title"
                        value={data.title}
                        onChange={(event) => setData('title', event.target.value)}
                        placeholder="Three-interview feature package"
                        invalid={Boolean(errors.title)}
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Expected value"
                        htmlFor="expected_value"
                        error={errors.expected_value}
                        required
                        hint="In rupees."
                    >
                        <Input
                            id="expected_value"
                            type="number"
                            min={0}
                            value={data.expected_value}
                            onChange={(event) => setData('expected_value', event.target.value)}
                            invalid={Boolean(errors.expected_value)}
                        />
                    </Field>

                    <Field label="Stage" htmlFor="deal_stage" error={errors.stage}>
                        <Select
                            id="deal_stage"
                            value={data.stage}
                            onChange={(event) => setData('stage', event.target.value)}
                        >
                            {stages.map((stage) => (
                                <option key={stage.value} value={stage.value}>
                                    {stage.label}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <Field label="Expected close" htmlFor="expected_close_on" error={errors.expected_close_on}>
                        <Input
                            id="expected_close_on"
                            type="date"
                            value={data.expected_close_on}
                            onChange={(event) => setData('expected_close_on', event.target.value)}
                        />
                    </Field>
                </div>

                <Field label="Notes" htmlFor="deal_notes" error={errors.notes}>
                    <Textarea
                        id="deal_notes"
                        value={data.notes}
                        onChange={(event) => setData('notes', event.target.value)}
                        rows={3}
                    />
                </Field>
            </div>
        </Dialog>
    );
}
