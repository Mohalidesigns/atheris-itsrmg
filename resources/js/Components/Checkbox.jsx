export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-gray-300 text-[#1A365D] shadow-sm focus:ring-[#1A365D]/30 ' +
                className
            }
        />
    );
}
