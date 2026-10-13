const criticalityColors = {
    critical: { bg: '#FEE2E2', text: '#C53030' },
    high: { bg: '#FED7AA', text: '#C05621' },
    medium: { bg: '#FEF3C7', text: '#8A6400' },
    low: { bg: '#D1FAE5', text: '#2D7D46' },
};

const statusColors = {
    active: { bg: '#D1FAE5', text: '#2D7D46' },
    inactive: { bg: '#E2E8F0', text: '#718096' },
    decommissioned: { bg: '#FEE2E2', text: '#C53030' },
    under_review: { bg: '#FEF3C7', text: '#8A6400' },
};

export const typeLabels = {
    hardware: 'Hardware',
    software: 'Software',
    cloud_service: 'Cloud Service',
    database: 'Database',
    network: 'Network',
    facility: 'Facility',
};

export const sourceLabels = {
    manual: 'Manual entry',
    ad: 'Active Directory',
    entra: 'Microsoft Entra ID',
    aws: 'AWS',
    tenable: 'Tenable.io',
    qualys: 'Qualys VMDR',
    csv: 'CSV import',
};

export function CriticalityBadge({ criticality }) {
    const colors = criticalityColors[criticality] || criticalityColors.medium;
    return (
        <span className="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full capitalize"
            style={{ backgroundColor: colors.bg, color: colors.text }}>
            {criticality || 'medium'}
        </span>
    );
}

export function AssetStatusBadge({ status }) {
    const colors = statusColors[status] || statusColors.active;
    return (
        <span className="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full capitalize"
            style={{ backgroundColor: colors.bg, color: colors.text }}>
            {status?.replace('_', ' ')}
        </span>
    );
}
