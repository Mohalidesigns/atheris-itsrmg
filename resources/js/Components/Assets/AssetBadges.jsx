import Chip from '@/Components/Material/Chip';

const criticalityChip = {
    critical: ['error', 'keyboard_double_arrow_up'],
    high: ['warning', 'keyboard_arrow_up'],
    medium: ['tertiary', 'drag_handle'],
    low: ['success', 'keyboard_arrow_down'],
};

const statusChip = {
    active: ['success', 'check_circle'],
    inactive: ['muted', 'do_not_disturb_on'],
    decommissioned: ['error', 'delete_forever'],
    under_review: ['warning', 'rate_review'],
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
    const [tone, icon] = criticalityChip[criticality] || criticalityChip.medium;
    return <Chip tone={tone} icon={icon}>{criticality || 'medium'}</Chip>;
}

export function AssetStatusBadge({ status }) {
    const [tone, icon] = statusChip[status] || statusChip.active;
    return <Chip tone={tone} icon={icon}>{status?.replace('_', ' ')}</Chip>;
}
