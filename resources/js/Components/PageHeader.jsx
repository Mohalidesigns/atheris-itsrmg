import Breadcrumbs from './Breadcrumbs';

export default function PageHeader({ title, subtitle, actions, breadcrumbs }) {
    return (
        <div className="mb-6">
            {breadcrumbs && breadcrumbs.length > 0 && <Breadcrumbs items={breadcrumbs} />}
            <div className="flex items-start justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-[#0A1F44]">{title}</h2>
                    {subtitle && <p className="text-sm text-[#718096] mt-1">{subtitle}</p>}
                </div>
                {actions && <div className="flex items-center gap-2">{actions}</div>}
            </div>
        </div>
    );
}
