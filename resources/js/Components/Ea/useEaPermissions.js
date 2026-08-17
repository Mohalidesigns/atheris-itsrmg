import { usePage } from '@inertiajs/react';

/**
 * EA permission helper.
 *
 * ATH-EAR-002 §2.2: the module now enforces `create ea` / `edit ea` /
 * `delete ea` / `approve ea` on every write route and in every controller
 * action. The UI must agree with the server or a Viewer sees buttons that
 * return 403. This reads the permissions HandleInertiaRequests already shares.
 */
export default function useEaPermissions() {
    const { auth } = usePage().props;
    const perms = auth?.user?.permissions || [];
    const roles = auth?.user?.roles || [];
    const isSuperAdmin = roles.includes('Super Admin');
    const has = (p) => isSuperAdmin || perms.includes(p);

    return {
        canView: has('view ea'),
        canCreate: has('create ea'),
        canEdit: has('edit ea'),
        canDelete: has('delete ea'),
        canApprove: has('approve ea'),
        canExport: has('export ea'),
        // Feed syncs and bulk ingestion — mirrors EaPolicy::admin().
        canAdmin: has('delete ea'),
    };
}
