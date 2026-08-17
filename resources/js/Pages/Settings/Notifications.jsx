import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
    BellIcon,
    EnvelopeIcon,
    DevicePhoneMobileIcon,
    InformationCircleIcon,
} from '@heroicons/react/24/outline';

function TogglePlaceholder({ label, description, defaultOn = false }) {
    return (
        <div className="flex items-center justify-between py-3">
            <div>
                <p className="text-sm font-medium text-[#2D3748]">{label}</p>
                <p className="text-xs text-[#718096]">{description}</p>
            </div>
            <div
                className={`relative w-10 h-5 rounded-full transition-colors cursor-not-allowed ${
                    defaultOn ? 'bg-[#2D7D46]' : 'bg-gray-300'
                }`}
            >
                <div
                    className={`absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform ${
                        defaultOn ? 'left-5' : 'left-0.5'
                    }`}
                />
            </div>
        </div>
    );
}

export default function Notifications() {
    return (
        <AuthenticatedLayout header="Notification Settings">
            <Head title="Notification Settings" />

            <div className="max-w-3xl">
                {/* Header */}
                <div className="mb-6">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-[#1A365D]/10 rounded-lg">
                            <BellIcon className="w-6 h-6 text-[#1A365D]" />
                        </div>
                        <div>
                            <h1 className="text-xl font-bold text-[#2D3748]">Notification Settings</h1>
                            <p className="text-sm text-[#718096]">Configure how you receive alerts and updates</p>
                        </div>
                    </div>
                </div>

                {/* Coming Soon Banner */}
                <div className="bg-[#D4AF37]/10 border border-[#D4AF37]/20 rounded-xl p-4 mb-6">
                    <div className="flex items-start gap-3">
                        <InformationCircleIcon className="w-5 h-5 text-[#D4AF37] mt-0.5 flex-shrink-0" />
                        <div>
                            <p className="text-sm font-medium text-[#2D3748]">Coming Soon</p>
                            <p className="text-xs text-[#718096] mt-0.5">
                                Notification preferences configuration is under development. The toggles below are previews of upcoming functionality.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Email Notifications */}
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6 opacity-75">
                    <div className="flex items-center gap-2 mb-4">
                        <EnvelopeIcon className="w-5 h-5 text-[#1A365D]" />
                        <h2 className="text-base font-semibold text-[#2D3748]">Email Notifications</h2>
                    </div>

                    <div className="divide-y divide-gray-100">
                        <TogglePlaceholder
                            label="Risk Alerts"
                            description="Get notified when new critical or high risks are identified"
                            defaultOn={true}
                        />
                        <TogglePlaceholder
                            label="Compliance Deadlines"
                            description="Receive reminders for upcoming compliance assessment deadlines"
                            defaultOn={true}
                        />
                        <TogglePlaceholder
                            label="Incident Reports"
                            description="Get notified when security incidents are reported"
                            defaultOn={true}
                        />
                        <TogglePlaceholder
                            label="Policy Updates"
                            description="Receive notifications when policies are published or updated"
                            defaultOn={false}
                        />
                        <TogglePlaceholder
                            label="Weekly Digest"
                            description="Receive a weekly summary of all GRC activities"
                            defaultOn={false}
                        />
                    </div>
                </div>

                {/* In-App Notifications */}
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6 opacity-75">
                    <div className="flex items-center gap-2 mb-4">
                        <DevicePhoneMobileIcon className="w-5 h-5 text-[#1A365D]" />
                        <h2 className="text-base font-semibold text-[#2D3748]">In-App Notifications</h2>
                    </div>

                    <div className="divide-y divide-gray-100">
                        <TogglePlaceholder
                            label="Task Assignments"
                            description="Get notified when tasks are assigned to you"
                            defaultOn={true}
                        />
                        <TogglePlaceholder
                            label="Mentions"
                            description="Get notified when someone mentions you in comments"
                            defaultOn={true}
                        />
                        <TogglePlaceholder
                            label="Status Changes"
                            description="Get notified when items you follow change status"
                            defaultOn={false}
                        />
                        <TogglePlaceholder
                            label="Vendor Assessments"
                            description="Get notified about vendor assessment updates"
                            defaultOn={false}
                        />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
