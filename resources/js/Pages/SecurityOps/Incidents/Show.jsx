import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Badge from '@/Components/StatusBadge';
import EaIncidentImpactPanel from '@/Components/Ea/EaIncidentImpactPanel';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    PencilIcon,
    ClockIcon,
    ShieldCheckIcon,
    ExclamationTriangleIcon,
    MagnifyingGlassIcon,
    BoltIcon,
    CheckCircleIcon,
    XCircleIcon,
    InformationCircleIcon,
    ArrowPathIcon,
    DocumentTextIcon,
} from '@/Components/Material/Symbols';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { formatDate } from '@/Utils/risk';

const severityColors = {
    critical: '#C53030',
    high: '#DD6B20',
    medium: '#D4AF37',
    low: '#2D7D46',
};

function TabButton({ active, children, onClick }) {
    return (
        <button
            onClick={onClick}
            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
                active
                    ? 'border-[#D4AF37] text-[#1A365D]'
                    : 'border-transparent text-[#718096] hover:text-[#2D3748] hover:border-gray-300'
            }`}
        >
            {children}
        </button>
    );
}

function IncidentStatusBadge({ status }) {
    return <Badge status={status || 'detected'} />;
}

const eventTypeIcons = {
    detection: MagnifyingGlassIcon,
    triage: InformationCircleIcon,
    investigation: MagnifyingGlassIcon,
    containment: ShieldCheckIcon,
    eradication: XCircleIcon,
    recovery: ArrowPathIcon,
    communication: DocumentTextIcon,
    escalation: ExclamationTriangleIcon,
    resolution: CheckCircleIcon,
    status_change: BoltIcon,
    note: DocumentTextIcon,
    evidence: MagnifyingGlassIcon,
    assignment: InformationCircleIcon,
};

// Mirrors IncidentController::updateStatus: forward through the lifecycle, or reopen into investigation.
const statusTransitions = {
    detected: ['triaged', 'investigating'],
    triaged: ['investigating', 'containing'],
    investigating: ['containing', 'eradicating'],
    containing: ['eradicating', 'recovering'],
    eradicating: ['recovering'],
    recovering: ['resolved'],
    resolved: ['post_incident_review', 'closed', 'investigating'],
    post_incident_review: ['closed', 'investigating'],
    closed: ['investigating'],
};

function calculateTimeToRespond(detectedAt, respondedAt) {
    if (!detectedAt) return 'N/A';
    if (!respondedAt) return 'Pending';

    const detected = new Date(detectedAt);
    const responded = new Date(respondedAt);
    const diffMs = responded - detected;
    const diffMins = Math.floor(diffMs / 60000);

    if (diffMins < 60) return `${diffMins}m`;
    const hours = Math.floor(diffMins / 60);
    const mins = diffMins % 60;
    if (hours < 24) return `${hours}h ${mins}m`;
    const days = Math.floor(hours / 24);
    return `${days}d ${hours % 24}h`;
}

export default function ShowIncident({ incident, statuses, templates = [], suggestedProcedures = [], eaImpact }) {
    const [activeTab, setActiveTab] = useState('timeline');

    const severityColor = severityColors[incident.severity] || '#718096';
    const nextStatuses = statusTransitions[incident.status] || [];
    const timeToRespond = calculateTimeToRespond(incident.detected_at, incident.responded_at);

    const handleStatusChange = (newStatus) => {
        router.patch(route('incidents.update-status', incident.id), { status: newStatus }, { preserveScroll: true });
    };

    const draftedKeys = (incident.notifications || []).map((n) => n.template_key);
    const draftNotification = (templateKey) => router.post(route('notifications.draft'), { incident_id: incident.id, template_key: templateKey });
    const adoptProcedure = (id) => router.post(route('incidents.procedure', incident.id), { response_procedure_id: id }, { preserveScroll: true });

    const { data: eventData, setData: setEventData, post: postEvent, processing: eventProcessing, errors: eventErrors, reset: resetEvent } = useForm({
        event_type: '',
        description: '',
    });

    const submitEvent = (e) => {
        e.preventDefault();
        postEvent(route('incidents.add-event', incident.id), {
            onSuccess: () => resetEvent(),
        });
    };

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">
                    {incident.incident_id_code || `INC-${String(incident.id).padStart(4, '0')}`}
                </span>
                <span>{incident.title}</span>
            </div>
        }>
            <Head title={`${incident.incident_id_code || 'INC'} - ${incident.title}`} />

            {/* Header Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Severity</p>
                    <p className="mt-1 text-sm font-semibold capitalize" style={{ color: severityColor }}>
                        {incident.severity}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1"><IncidentStatusBadge status={incident.status} /></div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Type</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748] capitalize">
                        {(incident.type || '').replace(/_/g, ' ')}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Time to Respond</p>
                    <p className="mt-1 text-lg font-bold font-mono-data text-[#2D3748]">
                        {timeToRespond}
                    </p>
                </div>
            </div>

            {/* Status Update Buttons */}
            {nextStatuses.length > 0 && (
                <div className="flex items-center gap-2 mb-4">
                    <span className="text-xs text-[#718096] font-medium">Move to:</span>
                    {nextStatuses.map(s => (
                        <button
                            key={s}
                            onClick={() => handleStatusChange(s)}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium border border-[#1A365D] text-[#1A365D] rounded-lg hover:bg-[#1A365D]/5 transition-colors capitalize"
                        >
                            {s === 'investigating' && ['resolved', 'post_incident_review', 'closed'].includes(incident.status) ? 'reopen' : s.replace(/_/g, ' ')}
                        </button>
                    ))}
                </div>
            )}

            {/* Action Buttons */}
            <div className="flex gap-2 mb-4">
                <Link
                    href={route('incidents.edit', incident.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"
                >
                    <PencilIcon className="w-4 h-4" />
                    Edit
                </Link>
            </div>

            {/* Tabs */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="border-b border-gray-100 px-4 flex gap-1">
                    {['timeline', 'details', 'procedure', 'notifications', 'alerts', 'breach'].map(tab => (
                        <TabButton key={tab} active={activeTab === tab} onClick={() => setActiveTab(tab)}>
                            {tab.charAt(0).toUpperCase() + tab.slice(1)}
                            {tab === 'timeline' && incident.events?.length > 0 && (
                                <span className="ml-1 text-xs bg-gray-100 px-1.5 py-0.5 rounded-lg whitespace-nowrap">{incident.events.length}</span>
                            )}
                            {tab === 'alerts' && incident.alerts?.length > 0 && (
                                <span className="ml-1 text-xs bg-gray-100 px-1.5 py-0.5 rounded-lg whitespace-nowrap">{incident.alerts.length}</span>
                            )}
                            {tab === 'notifications' && incident.notifications?.length > 0 && (
                                <span className="ml-1 text-xs bg-gray-100 px-1.5 py-0.5 rounded-lg whitespace-nowrap">{incident.notifications.length}</span>
                            )}
                            {tab === 'breach' && incident.is_data_breach && (
                                <span className="ml-1 w-2 h-2 rounded-full bg-[#C53030] inline-block" />
                            )}
                        </TabButton>
                    ))}
                </div>

                <div className="p-5">
                    {activeTab === 'timeline' && (
                        <div>
                            {/* Event Timeline */}
                            {incident.events?.length > 0 ? (
                                <div className="space-y-3 mb-6">
                                    {incident.events.map(event => {
                                        const Icon = eventTypeIcons[event.event_type] || DocumentTextIcon;
                                        return (
                                            <div key={event.id} className="flex gap-3 py-2 border-b border-gray-50 last:border-0">
                                                <div className="w-8 h-8 rounded-lg bg-[#1A365D]/5 flex items-center justify-center shrink-0 mt-0.5">
                                                    <Icon className="w-4 h-4 text-[#1A365D]" />
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-xs font-semibold text-[#1A365D] capitalize">
                                                            {(event.event_type || '').replace(/_/g, ' ')}
                                                        </span>
                                                        <span className="text-xs text-[#718096]">
                                                            {event.performer?.name || 'System'}
                                                        </span>
                                                    </div>
                                                    <p className="text-sm text-[#2D3748] mt-0.5">{event.description}</p>
                                                    <div className="flex items-center gap-1 mt-1 text-xs text-[#718096]">
                                                        <ClockIcon className="w-3 h-3" />
                                                        {formatDate(event.occurred_at || event.created_at, true)}
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <p className="text-sm text-[#718096] py-4 text-center mb-6">No events recorded yet.</p>
                            )}

                            {/* Add Event Form */}
                            <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                                <h4 className="text-sm font-semibold text-[#2D3748] mb-3">Add Event</h4>
                                <form onSubmit={submitEvent} className="space-y-3">
                                    <div>
                                        <InputLabel htmlFor="event_type" value="Event Type" />
                                        <select
                                            id="event_type"
                                            value={eventData.event_type}
                                            onChange={e => setEventData('event_type', e.target.value)}
                                            className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                            required
                                        >
                                            <option value="">Select event type...</option>
                                            {['note', 'evidence', 'communication', 'escalation', 'assignment'].map(t => (
                                                <option key={t} value={t}>
                                                    {t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={eventErrors.event_type} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="event_description" value="Description" />
                                        <textarea
                                            id="event_description"
                                            value={eventData.description}
                                            onChange={e => setEventData('description', e.target.value)}
                                            rows={2}
                                            className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                            placeholder="Describe what happened..."
                                            required
                                        />
                                        <InputError message={eventErrors.description} className="mt-1" />
                                    </div>
                                    <div className="flex justify-end">
                                        <PrimaryButton disabled={eventProcessing}>
                                            Add Event
                                        </PrimaryButton>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}

                    {activeTab === 'details' && (
                        <div className="space-y-4">
                            <div>
                                <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Description</h4>
                                <p className="text-sm text-[#2D3748]">{incident.description || 'No description provided.'}</p>
                            </div>

                            {incident.root_cause && (
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Root Cause</h4>
                                    <p className="text-sm text-[#2D3748]">{incident.root_cause}</p>
                                </div>
                            )}

                            {incident.lessons_learned && (
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Lessons Learned</h4>
                                    <p className="text-sm text-[#2D3748]">{incident.lessons_learned}</p>
                                </div>
                            )}

                            {incident.affected_systems && (
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Affected Systems</h4>
                                    <p className="text-sm text-[#2D3748]">{incident.affected_systems}</p>
                                </div>
                            )}

                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Assigned To</h4>
                                    <p className="text-sm text-[#2D3748]">{incident.assignee?.name || '--'}</p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Lead Investigator</h4>
                                    <p className="text-sm text-[#2D3748]">{incident.lead_investigator?.name || '--'}</p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Detected At</h4>
                                    <p className="text-sm text-[#2D3748]">{formatDate(incident.detected_at, true)}</p>
                                </div>
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Source</h4>
                                    <p className="text-sm text-[#2D3748]">{incident.source || '--'}</p>
                                </div>
                            </div>
                        </div>
                    )}

                    {activeTab === 'procedure' && (
                        <div>
                            {incident.procedure ? (
                                <div>
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <Link href={route('response-procedures.show', incident.procedure.id)} className="text-sm font-semibold text-[#0A1F44] hover:underline">
                                                {incident.procedure.title}
                                            </Link>
                                            <p className="text-xs text-[#718096]">Version {incident.procedure.version || '1.0'} · {incident.procedure.category}</p>
                                        </div>
                                    </div>
                                    <ol className="mt-3 list-decimal space-y-1 pl-5 text-sm text-[#2D3748]">
                                        {(incident.procedure.steps || []).map((step, i) => <li key={i}>{step}</li>)}
                                    </ol>
                                    {(incident.procedure.escalation_contacts || []).length > 0 && (
                                        <p className="mt-3 text-xs text-[#718096]">Escalate to: {incident.procedure.escalation_contacts.join(' → ')}</p>
                                    )}
                                </div>
                            ) : (
                                <div>
                                    <p className="text-sm text-[#718096]">No response procedure is being followed for this incident.</p>
                                    {suggestedProcedures.length > 0 ? (
                                        <ul className="mt-3 space-y-2">
                                            {suggestedProcedures.map((p) => (
                                                <li key={p.id} className="flex items-center justify-between gap-2 rounded-lg border border-gray-100 px-3 py-2">
                                                    <span className="text-sm text-[#2D3748]">{p.title}</span>
                                                    <button type="button" onClick={() => adoptProcedure(p.id)} className="rounded border border-[#0A1F44]/20 px-2 py-1 text-xs text-[#0A1F44] hover:bg-[#0A1F44]/5">Follow this</button>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <Link href={route('response-procedures.index')} className="mt-2 inline-block text-xs text-[#0A1F44] underline">No procedure covers this incident type: browse the playbooks →</Link>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {activeTab === 'notifications' && (
                        <div className="space-y-4">
                            <ul className="divide-y divide-gray-100">
                                {(incident.notifications || []).length === 0 && <li className="py-2 text-sm text-[#718096]">No regulatory notifications drafted yet.</li>}
                                {(incident.notifications || []).map((n) => {
                                    const late = n.delivery_status !== 'submitted' && new Date(n.deadline_at) < new Date();
                                    return (
                                        <li key={n.id} className="flex items-center justify-between gap-2 py-2">
                                            <Link href={route('notifications.show', n.id)} className="text-sm text-[#0A1F44] hover:underline">
                                                <span className="mr-1 rounded bg-[#0A1F44] px-1.5 py-0.5 text-[10px] text-white">{n.regulator_code}</span>
                                                {n.template_key}
                                            </Link>
                                            <span className={`text-xs ${late ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>
                                                {n.delivery_status.replace(/_/g, ' ')} · {n.delivery_status === 'submitted' ? `submitted ${formatDate(n.submitted_at, true)}` : `due ${formatDate(n.deadline_at, true)}`}{late ? ' · overdue' : ''}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                            <div>
                                <p className="text-xs font-semibold text-[#2D3748]">Draft from this incident</p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {templates.filter((t) => !draftedKeys.includes(t.template_key)).map((t) => (
                                        <button key={t.id} type="button" onClick={() => draftNotification(t.template_key)}
                                            className={`rounded-lg border px-3 py-1.5 text-xs ${(t.regulator_code === 'CBN' || (t.regulator_code === 'NDPC' && incident.is_data_breach)) ? 'border-[#B3261E]/40 text-[#B3261E]' : 'border-gray-200 text-[#2D3748]'}`}>
                                            {t.title}
                                        </button>
                                    ))}
                                </div>
                                <p className="mt-2 text-[11px] text-[#718096]">Highlighted: CBN incident reporting applies to every cyber incident; NDPC applies when personal data was breached.</p>
                            </div>
                        </div>
                    )}

                    {activeTab === 'alerts' && (
                        <div>
                            {incident.alerts?.length > 0 ? (
                                <ul className="divide-y divide-gray-50">
                                    {incident.alerts.map(alert => (
                                        <li key={alert.id} className="py-3 flex items-center justify-between">
                                            <div>
                                                <Link href={route('security-alerts.show', alert.id)} className="font-mono-data text-xs text-[#1A365D] font-medium hover:underline">
                                                    {alert.alert_id_code}
                                                </Link>
                                                <p className="text-sm text-[#2D3748]">{alert.title}</p>
                                                <p className="text-xs text-[#718096]">{alert.source || 'Unknown source'} · received {alert.received_at ? new Date(alert.received_at).toLocaleString() : '--'}</p>
                                            </div>
                                            <div className="flex items-center gap-2 text-xs">
                                                <span className="px-2 py-0.5 rounded-lg bg-gray-100 text-[#2D3748] capitalize">{alert.severity}</span>
                                                <span className="px-2 py-0.5 rounded-lg bg-[#1A365D]/5 text-[#1A365D] capitalize">{alert.status}</span>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-sm text-[#718096] py-8 text-center">No security alerts linked to this incident.</p>
                            )}
                        </div>
                    )}

                    {activeTab === 'breach' && (
                        <div>
                            {incident.is_data_breach && incident.breach ? (
                                <div className="space-y-4">
                                    <div className="bg-[#C53030]/5 border border-[#C53030]/20 rounded-lg p-4">
                                        <div className="flex items-center gap-2 mb-2">
                                            <ExclamationTriangleIcon className="w-5 h-5 text-[#C53030]" />
                                            <h4 className="text-sm font-semibold text-[#C53030]">Data Breach Confirmed</h4>
                                        </div>
                                        <p className="text-sm text-[#2D3748]">{incident.breach.description || 'No breach details provided.'}</p>
                                    </div>

                                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                        <div>
                                            <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Breach Type</h4>
                                            <p className="text-sm text-[#2D3748] capitalize">{(incident.breach.breach_type || '--').replace(/_/g, ' ')}</p>
                                        </div>
                                        <div>
                                            <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Records Affected</h4>
                                            <p className="text-lg font-bold font-mono-data text-[#2D3748]">
                                                {incident.breach.records_affected != null
                                                    ? Number(incident.breach.records_affected).toLocaleString()
                                                    : '--'}
                                            </p>
                                        </div>
                                        <div>
                                            <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">NDPA Notification</h4>
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold ${
                                                incident.breach.ndpa_notified_at
                                                    ? 'bg-[#2D7D46]/10 text-[#2D7D46]'
                                                    : incident.breach.ndpa_notification_required
                                                        ? 'bg-[#C53030]/10 text-[#C53030]'
                                                        : 'bg-gray-100 text-gray-500'
                                            } whitespace-nowrap`}>
                                                {incident.breach.ndpa_notified_at
                                                    ? 'Notified'
                                                    : incident.breach.ndpa_notification_required
                                                        ? 'Required'
                                                        : 'Not Required'}
                                            </span>
                                        </div>
                                        <div>
                                            <h4 className="text-xs text-[#718096] uppercase font-medium mb-1">Status</h4>
                                            <p className="text-sm text-[#2D3748] capitalize">{(incident.breach.status || '--').replace(/_/g, ' ')}</p>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="text-center py-8">
                                    <ShieldCheckIcon className="w-10 h-10 text-[#2D7D46] mx-auto mb-2" />
                                    <p className="text-sm text-[#718096]">This incident has not been classified as a data breach.</p>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* ATH-EAR-002 §7.4 (I-13) — blast radius inside the CBN
                30-minute response window, so the responder does not have to
                open the EA module to learn what else this breaks. */}
            {eaImpact && (
                <div className="mt-6">
                    <EaIncidentImpactPanel impact={eaImpact} />
                </div>
            )}
        </AuthenticatedLayout>
    );
}
