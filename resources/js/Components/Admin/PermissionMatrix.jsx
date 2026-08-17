import Checkbox from '@/Components/Checkbox';

const actionLabels = {
    view: 'View',
    create: 'Create',
    edit: 'Edit',
    delete: 'Delete',
    approve: 'Approve',
    export: 'Export',
};

/**
 * Permission matrix — rows are modules (grouped), columns are actions.
 * `selected` is an array of permission names; `onChange` receives the new array.
 * Pass `readOnly` to render a static view (e.g. role detail page).
 */
export default function PermissionMatrix({ permissionGroups, actions, selected, onChange, readOnly = false }) {
    const selectedSet = new Set(selected);

    const setMany = (names, on) => {
        if (readOnly || !onChange) return;
        const next = new Set(selectedSet);
        names.forEach(n => (on ? next.add(n) : next.delete(n)));
        onChange([...next]);
    };

    const rowNames = (module) => Object.values(module.permissions);
    const columnNames = (group, action) =>
        group.modules.map(m => m.permissions[action]).filter(Boolean);
    const groupNames = (group) => group.modules.flatMap(rowNames);

    const allChecked = (names) => names.length > 0 && names.every(n => selectedSet.has(n));

    return (
        <div className="space-y-6">
            {permissionGroups.map(group => {
                const gNames = groupNames(group);
                return (
                    <div key={group.group} className="border border-gray-200 rounded-xl overflow-hidden">
                        <div className="flex items-center justify-between px-4 py-2.5 bg-[#1A365D]/5 border-b border-gray-200">
                            <h4 className="text-sm font-semibold text-[#1A365D]">{group.group}</h4>
                            {!readOnly && (
                                <label className="flex items-center gap-2 text-xs text-[#718096] cursor-pointer">
                                    <Checkbox
                                        checked={allChecked(gNames)}
                                        onChange={e => setMany(gNames, e.target.checked)}
                                    />
                                    Select all
                                </label>
                            )}
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-gray-50/50 border-b border-gray-100">
                                        <th className="text-left px-4 py-2 text-xs font-semibold text-[#718096] uppercase tracking-wider w-64">
                                            Module
                                        </th>
                                        {actions.map(action => {
                                            const cNames = columnNames(group, action);
                                            return (
                                                <th key={action} className="px-2 py-2 text-center">
                                                    <div className="flex flex-col items-center gap-1">
                                                        <span className="text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                                            {actionLabels[action] || action}
                                                        </span>
                                                        {!readOnly && (
                                                            <Checkbox
                                                                checked={allChecked(cNames)}
                                                                onChange={e => setMany(cNames, e.target.checked)}
                                                                title={`Toggle ${action} for all ${group.group} modules`}
                                                            />
                                                        )}
                                                    </div>
                                                </th>
                                            );
                                        })}
                                        <th className="px-3 py-2 text-center text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                            All
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {group.modules.map(module => {
                                        const rNames = rowNames(module);
                                        return (
                                            <tr key={module.module} className="hover:bg-gray-50/50">
                                                <td className="px-4 py-2 text-[#2D3748] font-medium">
                                                    {module.label}
                                                </td>
                                                {actions.map(action => {
                                                    const name = module.permissions[action];
                                                    return (
                                                        <td key={action} className="px-2 py-2 text-center">
                                                            {name ? (
                                                                readOnly ? (
                                                                    selectedSet.has(name) ? (
                                                                        <span className="inline-block w-2.5 h-2.5 rounded-full bg-[#2D7D46]" title={name} />
                                                                    ) : (
                                                                        <span className="inline-block w-2.5 h-2.5 rounded-full bg-gray-200" title={name} />
                                                                    )
                                                                ) : (
                                                                    <Checkbox
                                                                        checked={selectedSet.has(name)}
                                                                        onChange={e => setMany([name], e.target.checked)}
                                                                        title={name}
                                                                    />
                                                                )
                                                            ) : (
                                                                <span className="text-gray-300">--</span>
                                                            )}
                                                        </td>
                                                    );
                                                })}
                                                <td className="px-3 py-2 text-center">
                                                    {!readOnly ? (
                                                        <Checkbox
                                                            checked={allChecked(rNames)}
                                                            onChange={e => setMany(rNames, e.target.checked)}
                                                            title={`Toggle all actions for ${module.label}`}
                                                        />
                                                    ) : (
                                                        <span className="text-xs text-[#718096]">
                                                            {rNames.filter(n => selectedSet.has(n)).length}/{rNames.length}
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
