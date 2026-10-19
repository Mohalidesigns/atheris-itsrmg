import { FIELD } from '@/Utils/finance';

/** Edits {tagKey: dimension} as rows. `value` is an array of {key, dimension}; the parent converts. */
export default function TagMappingEditor({ rows, onChange, dimensions, error }) {
    const set = (i, patch) => onChange(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));
    return (
        <div>
            <p className="text-xs font-medium text-[#4A5568]">Tag mapping</p>
            <p className="text-[11px] text-[#718096]">Which resource tag identifies a cost centre, application or business service. Untagged spend falls to the default cost centre.</p>
            <div className="mt-2 space-y-2">
                {rows.map((r, i) => (
                    <div key={i} className="flex items-center gap-2">
                        <input aria-label="Tag key" maxLength={128} placeholder="Tag key, e.g. cost-centre" value={r.key} onChange={(e) => set(i, { key: e.target.value })} className={`${FIELD} mt-0 flex-1`} />
                        <select aria-label="Dimension" value={r.dimension} onChange={(e) => set(i, { dimension: e.target.value })} className={`${FIELD} mt-0 w-44`}>
                            {dimensions.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}
                        </select>
                        <button type="button" onClick={() => onChange(rows.filter((_, j) => j !== i))} className="text-xs text-[#B3261E] underline">Remove</button>
                    </div>
                ))}
            </div>
            <button type="button" onClick={() => onChange([...rows, { key: '', dimension: dimensions[0]?.value ?? 'cost_centre' }])} className="mt-2 text-xs text-[#0A1F44] underline">Add tag</button>
            {error && <p className="mt-1 text-sm text-[#B3261E]">{error}</p>}
        </div>
    );
}
