/** Filter strip: a responsive grid of labelled controls (use FilterField SelectFilter children). */
export default function FiltersBar({ children, cols = 'lg:grid-cols-6' }) {
    return <div className={`mb-4 grid grid-cols-2 gap-3 rounded-lg border border-gray-200 bg-white p-4 ${cols}`}>{children}</div>;
}

/** Toggle button row for chip-style filters. */
export function ChipFilter({ options, value, onChange, label }) {
    return (
        <div role="group" aria-label={label} className="flex flex-wrap items-center gap-1">
            {label && <span className="mr-1 text-xs text-[#718096]">{label}</span>}
            {options.map(([v, text]) => (
                <button key={v} type="button" aria-pressed={String(value ?? '') === String(v)} onClick={() => onChange(String(value ?? '') === String(v) ? '' : v)}
                    className={`rounded-lg border px-2.5 py-1 text-xs ${String(value ?? '') === String(v) ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 text-[#4A5568] hover:bg-gray-50'}`}>{text}</button>
            ))}
        </div>
    );
}
