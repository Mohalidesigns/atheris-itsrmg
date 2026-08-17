import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function FrameworksIndex({ frameworks }) {
    return (
        <AuthenticatedLayout header="Regulatory Frameworks">
            <Head title="Frameworks" />
            <p className="text-sm text-[#718096] mb-6">{frameworks.length} frameworks loaded</p>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {frameworks.map(fw => (
                    <Link key={fw.id} href={route('frameworks.show', fw.id)}
                        className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow group">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-base font-semibold text-[#2D3748] group-hover:text-[#1A365D]">{fw.short_name}</h3>
                                <p className="text-xs text-[#718096] mt-1">{fw.name}</p>
                            </div>
                            <span className={`text-xs px-2 py-0.5 rounded-full ${fw.jurisdiction === 'NG' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700'}`}>
                                {fw.jurisdiction === 'NG' ? 'Nigerian' : 'Global'}
                            </span>
                        </div>
                        <div className="mt-3 flex items-center gap-4 text-xs text-[#718096]">
                            <span>{fw.requirements_count} requirements</span>
                            <span className="capitalize">{fw.category}</span>
                            {fw.version && <span>v{fw.version}</span>}
                        </div>
                        {fw.issuing_body && <p className="text-xs text-[#718096] mt-2">By {fw.issuing_body}</p>}
                    </Link>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
