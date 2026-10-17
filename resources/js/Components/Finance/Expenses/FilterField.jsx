import { FIELD, LABEL } from '@/Utils/finance';

export function SelectFilter({ id, label, value, onChange, children, all = 'All' }) {
    return <div><label className={LABEL} htmlFor={id}>{label}</label><select id={id} value={value ?? ''} onChange={onChange} className={FIELD}><option value="">{all}</option>{children}</select></div>;
}

export function SearchFilter({ id, value, setValue, apply, placeholder }) {
    return (
        <div className="col-span-2 lg:col-span-1"><label className={LABEL} htmlFor={id}>Search</label>
            <input id={id} value={value} onChange={(e) => setValue(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && apply()} onBlur={() => apply()} placeholder={placeholder} className={FIELD} /></div>
    );
}

export function Toggle({ label, checked, onChange }) {
    return <label className="flex items-center gap-2 text-sm text-[#2D3748]"><input type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} /> {label}</label>;
}
