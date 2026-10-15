import Modal from '@/Components/Modal';
import { XMarkIcon } from '@/Components/Material/Symbols';
import { BTN_PRIMARY, BTN_SECONDARY } from '@/Utils/finance';

export function Card({ title, right, children, className = '' }) {
    return (
        <div className={`rounded-lg border border-gray-200 bg-white ${className}`}>
            {(title || right) && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">{title}</h3>
                    {right && <div className="flex items-center gap-2">{right}</div>}
                </div>
            )}
            {children}
        </div>
    );
}

export function Table({ head, children, empty }) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
                <thead className="bg-gray-50 text-xs uppercase tracking-wide text-[#718096]">
                    <tr>{head.map((h, i) => <th key={i} className={`whitespace-nowrap px-4 py-2 font-medium ${h === '' ? 'text-right' : ''}`}>{h}</th>)}</tr>
                </thead>
                <tbody className="divide-y divide-gray-50">{children}</tbody>
            </table>
            {empty}
        </div>
    );
}

export function Empty({ children }) {
    return <p className="px-5 py-8 text-center text-sm text-[#718096]">{children}</p>;
}

export function AddButton({ onClick, children }) {
    return <button type="button" onClick={onClick} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white">{children}</button>;
}

export function RowActions({ onEdit, onDelete }) {
    return (
        <div className="flex justify-end gap-3 text-xs">
            {onEdit && <button type="button" onClick={onEdit} className="text-[#0A1F44] underline">Edit</button>}
            {onDelete && <button type="button" onClick={onDelete} className="text-[#B3261E] underline">Delete</button>}
        </div>
    );
}

export function FormModal({ show, title, onClose, onSubmit, processing, submitLabel = 'Save', children, maxWidth = 'xl' }) {
    return (
        <Modal show={show} onClose={onClose} maxWidth={maxWidth}>
            <form onSubmit={(e) => { e.preventDefault(); onSubmit(); }}>
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">{title}</h3>
                    <button type="button" onClick={onClose} aria-label="Close" className="text-[#718096]"><XMarkIcon className="h-5 w-5" /></button>
                </div>
                <div className="space-y-3 px-5 py-4">{children}</div>
                <div className="flex justify-end gap-2 border-t border-gray-100 px-5 py-3">
                    <button type="button" onClick={onClose} className={BTN_SECONDARY}>Cancel</button>
                    <button disabled={processing} className={BTN_PRIMARY}>{submitLabel}</button>
                </div>
            </form>
        </Modal>
    );
}

export function Check({ label, checked, onChange, disabled }) {
    return (
        <label className="flex items-center gap-2 text-sm text-[#2D3748]">
            <input type="checkbox" disabled={disabled} checked={!!checked} onChange={(e) => onChange(e.target.checked)} className="rounded border-gray-300" /> {label}
        </label>
    );
}

export function SaveBar({ processing, dirty = true, error }) {
    return (
        <div className="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3">
            {error && <span className="text-xs text-[#B3261E]">{error}</span>}
            <button disabled={processing || !dirty} className={BTN_PRIMARY}>{processing ? 'Saving…' : 'Save changes'}</button>
        </div>
    );
}

/** Active/inactive helper chip text. */
export const yesNo = (v) => (v ? 'Yes' : 'No');
