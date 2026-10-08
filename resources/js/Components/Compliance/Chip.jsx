export default function Chip({ className = '', children, title }) {
    return (
        <span title={title} className={`inline-flex items-center text-xs px-2 py-0.5 rounded-full font-medium whitespace-nowrap ${className}`}>
            {children}
        </span>
    );
}
