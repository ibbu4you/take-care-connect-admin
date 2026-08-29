import { Head, router, useForm } from '@inertiajs/react';
import { Check, Plus, RotateCcw, Save, Tag, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { ConfirmDialog } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { adminLayout } from '@/Layouts/AdminLayout';
import { cn } from '@/lib/utils';

interface SettingField {
    key: string;
    label: string;
    unit: string;
    help: string;
    min: number;
    max: number;
    group: string;
    value: number;
    default: number;
    is_default: boolean;
}

interface CategoryRow {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    articles_count: number;
}

interface IndexProps {
    fields: SettingField[];
    categories: CategoryRow[];
    can: { update: boolean };
}

export default function SettingsIndex({ fields, categories, can }: IndexProps) {
    return (
        <div className="space-y-6">
            <Head title="Settings" />

            <PageHeader
                title="Settings"
                subtitle="The durations that drive reminders and default deadlines, and the category list articles are filed under."
            />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
                <WorkflowSettings fields={fields} canUpdate={can.update} />
                <Categories categories={categories} canUpdate={can.update} />
            </div>
        </div>
    );
}

/* ─────────────────────────────────────────────────────────────────────────
 | The durations
 ───────────────────────────────────────────────────────────────────────── */

function WorkflowSettings({ fields, canUpdate }: { fields: SettingField[]; canUpdate: boolean }) {
    const { data, setData, patch, processing, errors, isDirty } = useForm<{ values: Record<string, number> }>({
        values: Object.fromEntries(fields.map((field) => [field.key, field.value])),
    });

    // Grouped in the order the config lists them, so the page reads in the same
    // order as the workflow it describes.
    const groups = fields.reduce<Record<string, SettingField[]>>((carry, field) => {
        (carry[field.group] ??= []).push(field);

        return carry;
    }, {});

    function submit(event: React.FormEvent) {
        event.preventDefault();
        patch('/settings', { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="space-y-6">
            {/*
              | One card, not one per group.
              |
              | Four settings in four cards is four headers and three gaps for
              | four numbers, and it makes a short page feel like a long one.
              | The group name is a divider instead — enough to say which part
              | of the workflow a number belongs to, without framing it.
            */}
            <Card>
                <CardHeader>
                    <CardTitle>Workflow</CardTitle>
                </CardHeader>

                <div className="divide-y divide-border border-t border-border">
                    {Object.entries(groups).map(([group, groupFields]) => (
                        <div key={group} className="px-5 py-4">
                            <p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                {group}
                            </p>

                            <div className="space-y-4">
                                {groupFields.map((field) => (
                                    <div key={field.key}>
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                            <label
                                                htmlFor={field.key}
                                                className="text-sm font-medium"
                                            >
                                                {field.label}
                                            </label>

                                            <Input
                                                id={field.key}
                                                type="number"
                                                min={field.min}
                                                max={field.max}
                                                value={data.values[field.key]}
                                                disabled={!canUpdate}
                                                onChange={(event) =>
                                                    setData('values', {
                                                        ...data.values,
                                                        [field.key]: Number(event.target.value),
                                                    })
                                                }
                                                className="h-9 w-20"
                                                aria-describedby={`${field.key}-help`}
                                            />

                                            <span className="text-sm text-muted-foreground">{field.unit}</span>

                                            {!field.is_default && canUpdate && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setData('values', {
                                                            ...data.values,
                                                            [field.key]: field.default,
                                                        })
                                                    }
                                                    className="ml-auto inline-flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                                                >
                                                    <RotateCcw className="size-3" />
                                                    Reset to {field.default}
                                                </button>
                                            )}
                                        </div>

                                        <p id={`${field.key}-help`} className="mt-1 text-xs text-muted-foreground">
                                            {field.help}
                                        </p>

                                        {errors[`values.${field.key}` as keyof typeof errors] && (
                                            <p className="mt-1 text-xs font-medium text-destructive">
                                                {errors[`values.${field.key}` as keyof typeof errors]}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </Card>

            {canUpdate && (
                <div className="flex items-center gap-3">
                    <Button type="submit" loading={processing} disabled={!isDirty}>
                        <Save />
                        Save settings
                    </Button>

                    {isDirty && (
                        <span className="text-sm text-muted-foreground">
                            Takes effect immediately, including for tomorrow morning's digest.
                        </span>
                    )}
                </div>
            )}
        </form>
    );
}

/* ─────────────────────────────────────────────────────────────────────────
 | Categories
 ───────────────────────────────────────────────────────────────────────── */

function Categories({ categories, canUpdate }: { categories: CategoryRow[]; canUpdate: boolean }) {
    const [adding, setAdding] = useState('');
    const [editing, setEditing] = useState<CategoryRow | null>(null);
    const [editName, setEditName] = useState('');
    const [pendingDelete, setPendingDelete] = useState<CategoryRow | null>(null);

    function add(event: React.FormEvent) {
        event.preventDefault();

        if (!adding.trim()) return;

        router.post(
            '/settings/categories',
            { name: adding.trim() },
            { preserveScroll: true, onSuccess: () => setAdding('') },
        );
    }

    function save(category: CategoryRow) {
        router.patch(
            `/settings/categories/${category.id}`,
            { name: editName.trim(), is_active: category.is_active },
            { preserveScroll: true, onSuccess: () => setEditing(null) },
        );
    }

    function toggle(category: CategoryRow) {
        router.patch(
            `/settings/categories/${category.id}`,
            { name: category.name, is_active: !category.is_active },
            { preserveScroll: true },
        );
    }

    return (
        <div className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Tag className="size-4" aria-hidden="true" />
                        Categories
                    </CardTitle>
                </CardHeader>

                <CardContent className="pb-4">
                    <p className="text-xs text-muted-foreground">
                        A copy of the website's list. The export bundle carries the category by name, so if the
                        two drift apart it shows up as a label somebody can see rather than a wrong link.
                    </p>
                </CardContent>

                <ul className="divide-y divide-border border-t border-border">
                    {categories.map((category) => (
                        <li key={category.id} className="px-5 py-2.5">
                            {editing?.id === category.id ? (
                                <div className="flex items-center gap-2">
                                    <Input
                                        value={editName}
                                        onChange={(event) => setEditName(event.target.value)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') save(category);
                                            if (event.key === 'Escape') setEditing(null);
                                        }}
                                        autoFocus
                                        className="h-8"
                                    />
                                    <Button size="icon" variant="ghost" onClick={() => save(category)} aria-label="Save">
                                        <Check />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        onClick={() => setEditing(null)}
                                        aria-label="Cancel"
                                    >
                                        <X />
                                    </Button>
                                </div>
                            ) : (
                                <div className="flex items-center gap-2">
                                    <span
                                        className={cn(
                                            'min-w-0 flex-1 truncate text-sm',
                                            !category.is_active && 'text-muted-foreground line-through',
                                        )}
                                    >
                                        {category.name}
                                    </span>

                                    {category.articles_count > 0 && (
                                        <Badge tone="default">{category.articles_count}</Badge>
                                    )}

                                    {canUpdate && (
                                        <>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setEditing(category);
                                                    setEditName(category.name);
                                                }}
                                                className="text-xs text-muted-foreground transition-colors hover:text-foreground"
                                            >
                                                Rename
                                            </button>

                                            <button
                                                type="button"
                                                onClick={() => toggle(category)}
                                                className="text-xs text-muted-foreground transition-colors hover:text-foreground"
                                            >
                                                {category.is_active ? 'Switch off' : 'Switch on'}
                                            </button>

                                            {/*
                                              | Deleting is only offered where it
                                              | is actually possible. One with
                                              | articles behind it is switched
                                              | off instead, and the button that
                                              | would explain that is a button
                                              | that should not be there.
                                            */}
                                            {category.articles_count === 0 && (
                                                <button
                                                    type="button"
                                                    onClick={() => setPendingDelete(category)}
                                                    className="text-muted-foreground transition-colors hover:text-destructive"
                                                    aria-label={`Remove ${category.name}`}
                                                >
                                                    <Trash2 className="size-3.5" />
                                                </button>
                                            )}
                                        </>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>

                {canUpdate && (
                    <form onSubmit={add} className="flex items-center gap-2 border-t border-border p-4">
                        <Input
                            value={adding}
                            onChange={(event) => setAdding(event.target.value)}
                            placeholder="Add a category…"
                            className="h-9"
                            aria-label="New category name"
                        />
                        <Button type="submit" size="icon" variant="outline" disabled={!adding.trim()}>
                            <Plus />
                        </Button>
                    </form>
                )}
            </Card>

            <ConfirmDialog
                open={pendingDelete !== null}
                onClose={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(`/settings/categories/${pendingDelete.id}`, {
                            preserveScroll: true,
                            onFinish: () => setPendingDelete(null),
                        });
                    }
                }}
                title={`Remove ${pendingDelete?.name}?`}
                description="Nothing is filed under it, so nothing is lost. If that changes later, switch a category off rather than removing it."
                confirmLabel="Remove category"
            />
        </div>
    );
}

SettingsIndex.layout = adminLayout;
