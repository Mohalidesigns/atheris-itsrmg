import { Link } from '@inertiajs/react';

export default function Pagination({ links }) {
    if (!links || links.length <= 3) return null;

    return (
        <nav className="flex items-center justify-between mt-4">
            <div className="flex gap-1">
                {links.map((link, i) => (
                    <Link
                        key={i}
                        href={link.url || '#'}
                        className={`px-3 py-1.5 text-sm rounded-lg transition-colors ${
                            link.active
                                ? 'bg-[#1A365D] text-white'
                                : link.url
                                    ? 'text-[#718096] hover:bg-gray-100'
                                    : 'text-gray-300 cursor-default'
                        }`}
                        preserveScroll
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ))}
            </div>
        </nav>
    );
}
