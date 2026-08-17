import { PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline';

/**
 * RowActions — the edit/delete pair every EA index row now carries.
 * Buttons are omitted, not disabled, when the role lacks the permission, so
 * the UI matches what the server will actually allow (ATH-EAR-002 §2.2).
 */
export default function RowActions({ onEdit, onDelete, canEdit = true, canDelete = true, extra }) {
    return (
        <div className="flex items-center justify-end gap-1">
            {extra}
            {onEdit && canEdit && (
                <button
                    type="button"
                    onClick={onEdit}
                    title="Edit"
                    aria-label="Edit"
                    className="rounded p-1.5 text-[#718096] hover:bg-gray-100 hover:text-[#0A1F44]"
                >
                    <PencilSquareIcon className="h-4 w-4" />
                </button>
            )}
            {onDelete && canDelete && (
                <button
                    type="button"
                    onClick={onDelete}
                    title="Delete"
                    aria-label="Delete"
                    className="rounded p-1.5 text-[#718096] hover:bg-[#B3261E]/10 hover:text-[#B3261E]"
                >
                    <TrashIcon className="h-4 w-4" />
                </button>
            )}
        </div>
    );
}
