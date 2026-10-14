export default function Chip({ className = '', children, title }) {
    return (
        <span title={title} className={`inline-flex h-6 items-center rounded-lg px-2 text-xs font-medium whitespace-nowrap ${className}`}>
            {children}
        </span>
    );
}
