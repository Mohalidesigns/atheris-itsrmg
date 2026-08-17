import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import EaIndexToolbar, { useEaFilter, exportCsv } from '@/Components/Ea/EaIndexToolbar';
import EaFormModal from '@/Components/Ea/EaFormModal';
import EaEmptyState from '@/Components/Ea/EaEmptyState';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head } from '@inertiajs/react';
import { BugAntIcon } from '@heroicons/react/24/outline';

const STRIDE = [
    { value: 'S', label: 'S — Spoofing' },
    { value: 'T', label: 'T — Tampering' },
    { value: 'R', label: 'R — Repudiation' },
    { value: 'I', label: 'I — Information Disclosure' },
    { value: 'D', label: 'D — Denial of Service' },
    { value: 'E', label: 'E — Elevation of Privilege' },
];

const LINDDUN = [
    { value: 'L', label: 'L — Linkability' },
    { value: 'I2', label: 'I — Identifiability' },
    { value: 'N', label: 'N — Non-repudiation' },
    { value: 'D2', label: 'D — Detectability' },
    { value: 'D3', label: 'D — Disclosure of information' },
    { value: 'U', label: 'U — Unawareness' },
    { value: 'N2', label: 'N — Non-compliance' },
];

const MODEL_FIELDS = (options) => [
    { name: 'code', label: 'Code', type: 'text', required: true, placeholder: 'TM-001' },
    { name: 'name', label: 'Name', type: 'text', required: true },
    {
        name: 'subject_type',
        label: 'Subject type',
        type: 'select',
        required: true,
        options: [
            { value: 'App\\Models\\Ea\\EaApplication', label: 'Application' },
            { value: 'App\\Models\\Ea\\EaInterface', label: 'Interface' },
            { value: 'App\\Models\\Ea\\LogicalEntity', label: 'Logical entity' },
        ],
    },
    {
        name: 'subject_id',
        label: 'Subject',
        type: 'select',
        required: true,
        options: options.applications || [],
        help: 'Applications are listed here. For an interface or logical entity subject, enter its ID via bulk import until the picker covers those types.',
    },
    {
        name: 'methodology',
        label: 'Methodology',
        type: 'select',
        options: ['STRIDE', 'LINDDUN', 'PASTA'],
    },
];

/**
 * Appendix A #25: `ea.threats.techniques.store` was orphaned — a threat model
 * could be created but never populated, which made the whole surface inert.
 */
const TECHNIQUE_FIELDS = (methodology) => [
    {
        name: 'category',
        label: methodology === 'LINDDUN' ? 'LINDDUN category' : 'STRIDE category',
        type: 'select',
        required: true,
        options: methodology === 'LINDDUN' ? LINDDUN : STRIDE,
    },
    { name: 'threat', label: 'Threat', type: 'text', required: true, width: 'full' },
    { name: 'description', label: 'Description', type: 'textarea', width: 'full' },
    { name: 'mitre_attack', label: 'MITRE ATT&CK ID', type: 'text', placeholder: 'T1003' },
    { name: 'likelihood', label: 'Likelihood (1–5)', type: 'number', min: 1, max: 5 },
    { name: 'impact', label: 'Impact (1–5)', type: 'number', min: 1, max: 5 },
    { name: 'residual', label: 'Residual (0–5)', type: 'number', min: 0, max: 5 },
    { name: 'mitigation', label: 'Mitigation', type: 'textarea', width: 'full' },
];

const CSV_COLUMNS = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'methodology', label: 'Methodology' },
    { key: 'subject_type', label: 'Subject type' },
    { key: 'subject_id', label: 'Subject ID' },
    { key: 'risk_score', label: 'Risk score' },
    { key: 'techniques', label: 'Technique count', value: (m) => (m.techniques || []).length },
];

export default function Threats({ models = [], byMethodology = {}, options = {} }) {
    const perms = useEaPermissions();
    const [showModel, setShowModel] = useState(false);
    const [techniqueFor, setTechniqueFor] = useState(null);

    const f = useEaFilter(models, {
        searchKeys: ['code', 'name', 'methodology'],
        filters: { methodology: {} },
    });

    return (
        <AuthenticatedLayout header="Threat Models">
            <Head title="Threat Models" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Security Architecture' },
                    { label: 'Threat Models' },
                ]}
                title="STRIDE / LINDDUN Threat Models"
                subtitle="Per-application and per-interface threat modelling tied to MITRE ATT&CK and the security-zone control posture."
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.threats')} current="ea.threats" />

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Models" value={models.length} tone="navy" />
                <KpiCard label="STRIDE models" value={byMethodology.STRIDE || 0} tone="gold" />
                <KpiCard label="LINDDUN models" value={byMethodology.LINDDUN || 0} tone="white" />
                <KpiCard
                    label="Avg techniques / model"
                    value={
                        models.length
                            ? Math.round(
                                  models.reduce((s, m) => s + (m.techniques?.length || 0), 0) / models.length,
                              )
                            : 0
                    }
                    tone="white"
                />
            </div>

            <EaIndexToolbar
                search={{ value: f.query, onChange: f.setQuery, placeholder: 'Search threat models…' }}
                filters={[
                    {
                        key: 'methodology',
                        label: 'All methodologies',
                        value: f.active.methodology,
                        onChange: (v) => f.setFilter('methodology', v),
                        options: ['STRIDE', 'LINDDUN', 'PASTA'],
                    },
                ]}
                onCreate={() => setShowModel(true)}
                createLabel="New threat model"
                canCreate={perms.canCreate}
                onExport={() => exportCsv('ea-threat-models.csv', CSV_COLUMNS, f.filtered)}
                canExport={perms.canExport}
                total={models.length}
                shown={f.filtered.length}
            />

            {f.filtered.length === 0 ? (
                <EaEmptyState
                    icon={BugAntIcon}
                    filtered={f.isFiltered}
                    onClearFilter={f.reset}
                    title="No threat models"
                    description="Model the applications a supervisor will ask about first — the channels facing the internet and the connections carrying payment data."
                    actionLabel="Create the first threat model"
                    onAction={() => setShowModel(true)}
                    canAct={perms.canCreate}
                />
            ) : (
                <div className="space-y-3">
                    {f.filtered.map((m) => (
                        <div key={m.id} className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-xs text-[#0A1F44]">{m.code}</span>
                                <span className="font-medium text-[#2D3748]">{m.name}</span>
                                <StatusBadge status="moderate" label={m.methodology} />
                                <span className="ml-auto text-xs text-[#718096]">
                                    Risk: {m.risk_score ?? '—'} · Techniques: {m.techniques?.length || 0}
                                </span>
                                {perms.canCreate && (
                                    <button
                                        type="button"
                                        onClick={() => setTechniqueFor(m)}
                                        className="rounded border border-gray-200 px-2 py-1 text-xs font-medium text-[#0A1F44] hover:bg-gray-50"
                                    >
                                        + Add technique
                                    </button>
                                )}
                            </div>
                            <div className="mt-1 text-xs text-[#718096]">
                                Subject: {String(m.subject_type).split('\\').pop()} #{m.subject_id}
                            </div>
                            {m.techniques?.length > 0 ? (
                                <div className="mt-3 grid grid-cols-1 gap-1 md:grid-cols-2">
                                    {m.techniques.map((t) => (
                                        <div
                                            key={t.id}
                                            className="flex items-center gap-2 rounded border border-gray-100 px-2 py-1 text-xs"
                                        >
                                            <span className="font-mono text-[10px] text-[#C9A86A]">{t.category}</span>
                                            <span className="text-[#2D3748]">{t.threat}</span>
                                            <span className="ml-auto flex items-center gap-2">
                                                {t.mitre_attack && (
                                                    <span className="font-mono text-[10px] text-[#718096]">
                                                        {t.mitre_attack}
                                                    </span>
                                                )}
                                                <span className="text-[10px] text-[#718096]">
                                                    L{t.likelihood}/I{t.impact}
                                                </span>
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="mt-3 text-xs text-[#718096]">
                                    No techniques recorded — an empty threat model evidences nothing.
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            <EaFormModal
                show={showModel}
                onClose={() => setShowModel(false)}
                record={null}
                fields={MODEL_FIELDS(options)}
                title="New threat model"
                subtitle="Pick the subject from the catalogue rather than typing an ID."
                storeRoute={() => route('ea.threats.store')}
                updateRoute={() => ''}
            />

            <EaFormModal
                show={Boolean(techniqueFor)}
                onClose={() => setTechniqueFor(null)}
                record={null}
                fields={TECHNIQUE_FIELDS(techniqueFor?.methodology)}
                title={`Add a technique to ${techniqueFor?.code || ''}`}
                subtitle="Likelihood × impact feeds the model’s risk score; residual is the score after the stated mitigation."
                submitLabel="Add technique"
                storeRoute={() => (techniqueFor ? route('ea.threats.techniques.store', techniqueFor.id) : '')}
                updateRoute={() => ''}
            />
        </AuthenticatedLayout>
    );
}
