import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { BellIcon, InformationCircleIcon } from '@/Components/Material/Symbols';

const GROUPS = [
    ['Email', [
        ['email_risk_alerts', 'Risk alerts', 'New critical or high risks'],
        ['email_compliance_deadlines', 'Compliance deadlines', 'Upcoming assessment deadlines'],
        ['email_incidents', 'Incident reports', 'Security incidents reported'],
        ['email_weekly_digest', 'Weekly digest', 'Weekly summary of GRC activity'],
        ['finance_daily_digest', 'IT Finance daily digest', 'Renewals due in 30 days and approvals waiting for you'],
    ]],
    ['In-app', [
        ['inapp_task_assignments', 'Task assignments', 'Tasks assigned to you'],
        ['inapp_mentions', 'Mentions', 'Someone mentions you in a comment'],
    ]],
];

export default function Notifications({ prefs }) {
    const form = useForm(prefs);
    const submit = (e) => { e.preventDefault(); form.patch(route('settings.notifications.update'), { preserveScroll: true }); };
    return (
        <AuthenticatedLayout header="Notification Settings">
            <Head title="Notification Settings" />
            <form onSubmit={submit} className="max-w-3xl">
                <div className="flex items-center gap-3 mb-6">
                    <div className="p-2 bg-[#1A365D]/10 rounded-lg"><BellIcon className="w-6 h-6 text-[#1A365D]" /></div>
                    <div>
                        <h1 className="text-xl font-bold text-[#2D3748]">Notification Settings</h1>
                        <p className="text-sm text-[#718096]">Your personal preferences. They apply only to your account.</p>
                    </div>
                </div>
                <div className="bg-[#D4AF37]/10 border border-[#D4AF37]/20 rounded-xl p-4 mb-6 flex items-start gap-3">
                    <InformationCircleIcon className="w-5 h-5 text-[#D4AF37] mt-0.5 flex-shrink-0" />
                    <p className="text-xs text-[#718096]">Preferences are saved. Email delivery is not configured yet, so email notifications are not sent.</p>
                </div>
                {GROUPS.map(([title, items]) => (
                    <div key={title} className="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
                        <h2 className="text-base font-semibold text-[#2D3748] mb-2">{title}</h2>
                        <div className="divide-y divide-gray-100">
                            {items.map(([key, label, desc]) => (
                                <label key={key} className="flex items-center justify-between py-3 cursor-pointer">
                                    <span><span className="block text-sm font-medium text-[#2D3748]">{label}</span><span className="block text-xs text-[#718096]">{desc}</span></span>
                                    <input type="checkbox" checked={!!form.data[key]} onChange={(e) => form.setData(key, e.target.checked)} />
                                </label>
                            ))}
                        </div>
                    </div>
                ))}
                <button type="submit" disabled={form.processing} className="px-4 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Save preferences</button>
            </form>
        </AuthenticatedLayout>
    );
}
