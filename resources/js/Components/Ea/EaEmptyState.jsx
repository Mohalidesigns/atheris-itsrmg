import { PlusIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';

/**
 * EaEmptyState — ATH-EAR-002 WS 0.6.
 *
 * "A blank EA repository must tell the user what to do, not show an empty
 * table." Every empty state carries a first action, and — because population
 * is the module's hardest problem (§2.4, RC-4) — a secondary route to bulk
 * import for anyone who has a spreadsheet rather than one record to type.
 */
export default function EaEmptyState({
    title,
    description,
    actionLabel,
    onAction,
    canAct = true,
    secondaryHref,
    secondaryLabel = 'Bulk import from CSV',
    icon: Icon,
    filtered = false,
    onClearFilter,
}) {
    if (filtered) {
        return (
            <div className="rounded-xl border border-dashed border-gray-200 bg-white p-10 text-center">
                <p className="text-sm font-medium text-[#2D3748]">No records match your filters</p>
                <p className="mt-1 text-xs text-[#718096]">
                    Try a broader search term or clear the active filters.
                </p>
                {onClearFilter && (
                    <button
                        type="button"
                        onClick={onClearFilter}
                        className="mt-4 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                    >
                        Clear filters
                    </button>
                )}
            </div>
        );
    }

    return (
        <div className="rounded-xl border border-dashed border-gray-200 bg-white p-10 text-center">
            {Icon && (
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-[#0A1F44]/5">
                    <Icon className="h-6 w-6 text-[#0A1F44]" />
                </div>
            )}
            <p className="text-sm font-semibold text-[#0A1F44]">{title}</p>
            {description && (
                <p className="mx-auto mt-2 max-w-md text-xs leading-relaxed text-[#718096]">
                    {description}
                </p>
            )}
            <div className="mt-5 flex items-center justify-center gap-2">
                {onAction && canAct && (
                    <button
                        type="button"
                        onClick={onAction}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-[#0A1F44] px-4 py-2 text-xs font-semibold text-white hover:bg-[#1A365D]"
                    >
                        <PlusIcon className="h-4 w-4" />
                        {actionLabel}
                    </button>
                )}
                {secondaryHref && canAct && (
                    <Link
                        href={secondaryHref}
                        className="rounded-lg border border-gray-200 bg-white px-4 py-2 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                    >
                        {secondaryLabel}
                    </Link>
                )}
            </div>
            {!canAct && (
                <p className="mt-4 text-[11px] text-[#718096]">
                    Your role has read-only access to the architecture repository.
                </p>
            )}
        </div>
    );
}
