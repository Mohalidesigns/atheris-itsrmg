import InputError from '@/Components/InputError';
import { LABEL } from '@/Utils/finance';

export default function Field({ id, label, error, className = '', hint, children }) {
    return <div className={className}><label className={LABEL} htmlFor={id}>{label}</label>{children}{hint && <p className="mt-1 text-[11px] text-[#718096]">{hint}</p>}<InputError message={error} /></div>;
}
