import { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import EaEmptyState from '@/Components/Ea/EaEmptyState';
import ConfirmDialog from '@/Components/Ea/ConfirmDialog';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { EnvelopeOpenIcon } from '@heroicons/react/24/outline';

/**
 * Surveys & campaigns — WS 1.2.
 *
 * The form builder writes to real entity attributes, which is why the field
 * list is picked from a fixed surveyable set per entity type rather than typed
 * freehand: a survey that lands in a side table and needs an architect to
 * transcribe it does not keep a repository fresh.
 */

const CADENCE_LABEL = {
    once: 'One-off',
    weekly: 'Weekly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    annually: 'Annually',
};

const INPUT =
    'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm text-[#2D3748]';
const LABEL = 'block text-xs font-medium uppercase tracking-wide text-[#718096]';

function SurveyBuilder({ show, onClose, options }) {
    const [selected, setSelected] = useState([]);
    const [recipients, setRecipients] = useState('');

    const form = useForm({
        code: '',
        name: '',
        description: '',
        entity_type: options.entityTypes?.[0]?.value || '',
        fields: [],
        scope_filter: {},
        audience_mode: 'subscription',
        audience_roles: ['responsible', 'accountable'],
        additional_recipients: [],
        cadence: 'once',
        stale_after_days: 180,
        window_days: 14,
        reminder_interval_days: 7,
        max_reminders: 2,
        is_active: true,
    });

    const available = useMemo(
        () => options.fieldsByType?.[form.data.entity_type] || [],
        [form.data.entity_type, options.fieldsByType],
    );

    const toggle = (attribute) => {
        setSelected((prev) =>
            prev.includes(attribute) ? prev.filter((a) => a !== attribute) : [...prev, attribute],
        );
    };

    const submit = (e) => {
        e.preventDefault();
        const fields = available.filter((f) => selected.includes(f.attribute));
        const extra = recipients
            .split(/[\s,;]+/)
            .map((s) => s.trim())
            .filter(Boolean)
            .map((email) => ({ email, name: null }));

        form.transform((data) => ({ ...data, fields, additional_recipients: extra })).post(
            route('ea.surveys.store'),
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    setSelected([]);
                    setRecipients('');
                    onClose();
                },
            },
        );
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit}>
                <div className="border-b border-gray-100 px-6 py-4">
                    <h2 className="text-base font-semibold text-[#0A1F44]">New survey</h2>
                    <p className="mt-1 text-xs text-[#718096]">
                        Answers are written straight onto the records they ask about, so a response updates the
                        repository rather than sitting in a report.
                    </p>
                </div>

                <div className="max-h-[62vh] space-y-5 overflow-y-auto px-6 py-5">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div className="md:col-span-2">
                            <label className={LABEL}>Name *</label>
                            <input
                                className={INPUT}
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="Quarterly application owner confirmation"
                            />
                            {form.errors.name && <p className="mt-1 text-xs text-red-600">{form.errors.name}</p>}
                        </div>
                        <div className="md:col-span-2">
                            <label className={LABEL}>Message to recipients</label>
                            <textarea
                                className={INPUT}
                                rows={2}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="We are confirming the details we hold for the systems you own."
                            />
                        </div>
                        <div>
                            <label className={LABEL}>Ask about *</label>
                            <select
                                className={INPUT}
                                value={form.data.entity_type}
                                onChange={(e) => {
                                    form.setData('entity_type', e.target.value);
                                    setSelected([]);
                                }}
                            >
                                {options.entityTypes?.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className={LABEL}>Cadence</label>
                            <select
                                className={INPUT}
                                value={form.data.cadence}
                                onChange={(e) => form.setData('cadence', e.target.value)}
                            >
                                {options.cadences?.map((c) => (
                                    <option key={c} value={c}>
                                        {CADENCE_LABEL[c] || c}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className={LABEL}>Questions *</label>
                        <p className="mb-2 mt-1 text-[11px] text-[#718096]">
                            Each question writes to a real attribute on the record.
                        </p>
                        <div className="grid grid-cols-1 gap-1 rounded-lg border border-gray-100 p-3 md:grid-cols-2">
                            {available.length === 0 && (
                                <p className="text-xs text-[#718096]">
                                    No surveyable attributes are defined for this object type.
                                </p>
                            )}
                            {available.map((f) => (
                                <label
                                    key={f.attribute}
                                    className="flex items-start gap-2 rounded px-2 py-1.5 text-sm hover:bg-gray-50"
                                >
                                    <input
                                        type="checkbox"
                                        checked={selected.includes(f.attribute)}
                                        onChange={() => toggle(f.attribute)}
                                        className="mt-0.5 rounded border-gray-300 text-[#0A1F44] focus:ring-[#1A365D]/30"
                                    />
                                    <span>
                                        <span className="text-[#2D3748]">{f.label}</span>
                                        <span className="block font-mono text-[10px] text-[#718096]">
                                            {f.attribute}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        {form.errors.fields && <p className="mt-1 text-xs text-red-600">{form.errors.fields}</p>}
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label className={LABEL}>Send to</label>
                            <div className="mt-2 space-y-1">
                                {options.roles?.map((r) => (
                                    <label key={r.value} className="flex items-center gap-2 text-sm text-[#2D3748]">
                                        <input
                                            type="checkbox"
                                            checked={form.data.audience_roles.includes(r.value)}
                                            onChange={(e) =>
                                                form.setData(
                                                    'audience_roles',
                                                    e.target.checked
                                                        ? [...form.data.audience_roles, r.value]
                                                        : form.data.audience_roles.filter((x) => x !== r.value),
                                                )
                                            }
                                            className="rounded border-gray-300 text-[#0A1F44] focus:ring-[#1A365D]/30"
                                        />
                                        {r.label}
                                    </label>
                                ))}
                            </div>
                        </div>
                        <div>
                            <label className={LABEL}>Also email (one per line)</label>
                            <textarea
                                className={INPUT}
                                rows={4}
                                value={recipients}
                                onChange={(e) => setRecipients(e.target.value)}
                                placeholder="owner@bank.ng"
                            />
                            <p className="mt-1 text-[11px] text-[#718096]">
                                These people do not need an account or a licence — they answer by magic link.
                            </p>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <div>
                            <label className={LABEL}>Only if stale (days)</label>
                            <input
                                type="number"
                                className={INPUT}
                                value={form.data.stale_after_days ?? ''}
                                onChange={(e) =>
                                    form.setData('stale_after_days', e.target.value === '' ? null : Number(e.target.value))
                                }
                            />
                            <p className="mt-1 text-[11px] text-[#718096]">Blank asks about everything in scope.</p>
                        </div>
                        <div>
                            <label className={LABEL}>Window (days)</label>
                            <input
                                type="number"
                                className={INPUT}
                                value={form.data.window_days}
                                onChange={(e) => form.setData('window_days', Number(e.target.value))}
                            />
                        </div>
                        <div>
                            <label className={LABEL}>Remind every (days)</label>
                            <input
                                type="number"
                                className={INPUT}
                                value={form.data.reminder_interval_days}
                                onChange={(e) => form.setData('reminder_interval_days', Number(e.target.value))}
                            />
                        </div>
                        <div>
                            <label className={LABEL}>Max reminders</label>
                            <input
                                type="number"
                                className={INPUT}
                                value={form.data.max_reminders}
                                onChange={(e) => form.setData('max_reminders', Number(e.target.value))}
                            />
                        </div>
                    </div>
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-gray-100 bg-[#F7FAFC] px-6 py-4">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing || selected.length === 0}>
                        {form.processing ? 'Saving…' : 'Create survey'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

export default function Surveys({ surveys = [], campaigns = [], options = {} }) {
    const perms = useEaPermissions();
    const [showBuilder, setShowBuilder] = useState(false);
    const [launching, setLaunching] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [preview, setPreview] = useState(null);

    const openLaunch = (survey) => {
        setLaunching(survey);
        setPreview(null);
        fetch(route('ea.surveys.preview', survey.id), { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then(setPreview);
    };

    const running = campaigns.filter((c) => c.state === 'running');

    return (
        <AuthenticatedLayout header="Surveys & Campaigns">
            <Head title="Surveys & Campaigns" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Command Centre' },
                    { label: 'Surveys' },
                ]}
                title="Surveys & Campaigns"
                subtitle="Ask the people who know. Responses write straight onto the records and refresh their quality seal."
                actions={
                    perms.canCreate && (
                        <button
                            type="button"
                            onClick={() => setShowBuilder(true)}
                            className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D]"
                        >
                            New survey
                        </button>
                    )
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Survey definitions" value={surveys.length} tone="navy" />
                <KpiCard label="Campaigns running" value={running.length} tone={running.length ? 'gold' : 'white'} />
                <KpiCard
                    label="Recipients in flight"
                    value={running.reduce((a, c) => a + (c.recipients || 0), 0)}
                    tone="white"
                />
                <KpiCard
                    label="Responses received"
                    value={running.reduce((a, c) => a + (c.responses || 0), 0)}
                    tone="green"
                />
            </div>

            {surveys.length === 0 ? (
                <EaEmptyState
                    icon={EnvelopeOpenIcon}
                    title="No surveys defined"
                    description="A repository populated once by consultants is stale within a quarter. Surveys are how it stays true — ask each owner to confirm what you hold, on a schedule, and let the answers update the records directly."
                    actionLabel="Build the first survey"
                    onAction={() => setShowBuilder(true)}
                    canAct={perms.canCreate}
                />
            ) : (
                <div className="mb-6 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                        Survey definitions
                    </h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Code</th>
                                <th className="px-3 py-2">Name</th>
                                <th className="px-3 py-2">Asks about</th>
                                <th className="px-3 py-2">Questions</th>
                                <th className="px-3 py-2">Cadence</th>
                                <th className="px-3 py-2">Latest campaign</th>
                                <th className="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {surveys.map((s) => (
                                <tr key={s.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{s.code}</td>
                                    <td className="px-3 py-2 text-[#2D3748]">
                                        {s.name}
                                        {s.stale_after_days && (
                                            <span className="block text-[10px] text-[#718096]">
                                                only records unconfirmed for {s.stale_after_days}d
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{s.entity_label}</td>
                                    <td className="px-3 py-2 text-xs">{s.field_count}</td>
                                    <td className="px-3 py-2 text-xs">
                                        {CADENCE_LABEL[s.cadence] || s.cadence}
                                        {s.next_run_at && (
                                            <span className="block text-[10px] text-[#718096]">next {s.next_run_at}</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        {s.latest_campaign ? (
                                            <Link
                                                href={route('ea.surveys.campaign', s.latest_campaign.id)}
                                                className="text-[#0A1F44] hover:underline"
                                            >
                                                {s.latest_campaign.completion}% of {s.latest_campaign.recipients}
                                            </Link>
                                        ) : (
                                            <span className="text-[#718096]">Never run</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <div className="flex items-center justify-end gap-2">
                                            {perms.canCreate && (
                                                <button
                                                    type="button"
                                                    onClick={() => openLaunch(s)}
                                                    className="rounded border border-gray-200 px-2 py-1 text-xs font-medium text-[#0A1F44] hover:bg-gray-50"
                                                >
                                                    Launch
                                                </button>
                                            )}
                                            {perms.canDelete && (
                                                <button
                                                    type="button"
                                                    onClick={() => setDeleting(s)}
                                                    className="rounded border border-gray-200 px-2 py-1 text-xs font-medium text-[#B3261E] hover:bg-[#B3261E]/5"
                                                >
                                                    Delete
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {campaigns.length > 0 && (
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                        Recent campaigns
                    </h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Campaign</th>
                                <th className="px-3 py-2">Survey</th>
                                <th className="px-3 py-2">State</th>
                                <th className="px-3 py-2">Records</th>
                                <th className="px-3 py-2">Recipients</th>
                                <th className="px-3 py-2 w-48">Completion</th>
                                <th className="px-3 py-2">Closes</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {campaigns.map((c) => (
                                <tr key={c.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 font-mono text-xs">
                                        <Link
                                            href={route('ea.surveys.campaign', c.id)}
                                            className="text-[#0A1F44] hover:underline"
                                        >
                                            {c.code}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2 text-[#2D3748]">{c.survey}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge
                                            status={c.state === 'running' ? 'active' : 'closed'}
                                            label={c.state}
                                        />
                                    </td>
                                    <td className="px-3 py-2 text-xs">{c.entities}</td>
                                    <td className="px-3 py-2 text-xs">{c.recipients}</td>
                                    <td className="px-3 py-2">
                                        <div className="flex items-center gap-2">
                                            <span className="h-2 flex-1 overflow-hidden rounded-full bg-gray-100">
                                                <span
                                                    className="block h-2 rounded-full bg-[#2D7D46]"
                                                    style={{ width: `${c.completion}%` }}
                                                />
                                            </span>
                                            <span className="w-14 text-right text-xs text-[#718096]">
                                                {c.responses}/{c.recipients}
                                            </span>
                                        </div>
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{c.closes_at || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <SurveyBuilder show={showBuilder} onClose={() => setShowBuilder(false)} options={options} />

            <Modal show={Boolean(launching)} onClose={() => setLaunching(null)} maxWidth="md">
                <div className="px-6 py-5">
                    <h2 className="text-base font-semibold text-[#0A1F44]">Launch “{launching?.name}”?</h2>
                    {preview === null ? (
                        <p className="mt-3 text-sm text-[#718096]">Working out who this would reach…</p>
                    ) : (
                        <>
                            <p className="mt-3 text-sm text-[#2D3748]">
                                This will email <strong>{preview.recipients}</strong> recipient(s) about{' '}
                                <strong>{preview.entities}</strong> record(s).
                            </p>
                            {preview.recipients === 0 && (
                                <p className="mt-2 rounded-lg border border-[#B3261E]/30 bg-[#B3261E]/5 p-2 text-xs text-[#B3261E]">
                                    Nobody would receive this. The records in scope have no owner in the selected
                                    roles — assign owners first, or add named recipients to the survey.
                                </p>
                            )}
                            {preview.unreachable > 0 && preview.recipients > 0 && (
                                <p className="mt-2 text-xs text-[#8A6400]">
                                    {preview.unreachable} record(s) in scope have no owner and will not be asked
                                    about.
                                </p>
                            )}
                            {preview.sample?.length > 0 && (
                                <ul className="mt-3 max-h-40 space-y-1 overflow-y-auto text-[11px] text-[#718096]">
                                    {preview.sample.map((s, i) => (
                                        <li key={i}>
                                            {s.email} — {s.entity}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    )}
                </div>
                <div className="flex items-center justify-end gap-2 border-t border-gray-100 bg-[#F7FAFC] px-6 py-4">
                    <SecondaryButton onClick={() => setLaunching(null)}>Cancel</SecondaryButton>
                    <PrimaryButton
                        disabled={!preview || preview.recipients === 0}
                        onClick={() =>
                            router.post(route('ea.surveys.launch', launching.id), {}, {
                                onFinish: () => setLaunching(null),
                            })
                        }
                    >
                        Launch campaign
                    </PrimaryButton>
                </div>
            </Modal>

            <ConfirmDialog
                show={Boolean(deleting)}
                onClose={() => setDeleting(null)}
                title={`Delete survey “${deleting?.name}”?`}
                body="Past campaigns and the responses they collected are deleted with it. The answers already written onto records stay — those are repository data, not survey data."
                url={deleting ? route('ea.surveys.destroy', deleting.id) : ''}
            />
        </AuthenticatedLayout>
    );
}
