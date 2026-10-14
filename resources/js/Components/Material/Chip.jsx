import Icon from '@/Components/Material/Icon';

/**
 * Material 3 tonal chip roles, tuned to the Atheris navy/gold palette.
 * `container` is the chip fill, `on` the label colour, `rail` the solid accent (calendar rails, dots).
 */
export const TONES = {
    primary: { container: '#DDE3F0', on: '#0A1F44', rail: '#0A1F44' },
    tertiary: { container: '#F3E9D2', on: '#5E4818', rail: '#C9A86A' },
    success: { container: '#DCEFE1', on: '#1B5530', rail: '#2D7D46' },
    error: { container: '#F9DEDC', on: '#8C1D18', rail: '#B3261E' },
    warning: { container: '#FCEBC4', on: '#5F4300', rail: '#E5A100' },
    info: { container: '#DFE6FB', on: '#1E3A8A', rail: '#1D4ED8' },
    neutral: { container: '#E9EBEF', on: '#43474E', rail: '#74777F' },
    muted: { container: '#F1F2F4', on: '#74777F', rail: '#A0A4AB' },
};

/**
 * Compact Material 3 assist chip: 8px corners, tonal fill, optional leading symbol. Never wraps.
 * `variant="outlined"` gives the M3 outline chip for neutral/filter-style tags.
 */
export default function Chip({ tone = 'neutral', icon, iconFill = true, variant = 'tonal', size = 'sm', title, className = '', children }) {
    const t = TONES[tone] || TONES.neutral;
    const dims = size === 'xs' ? 'h-5 gap-0.5 px-1.5 text-[11px]' : 'h-6 gap-1 px-2 text-xs';
    const style = variant === 'outlined'
        ? { color: t.on, boxShadow: `inset 0 0 0 1px ${t.rail}55` }
        : { backgroundColor: t.container, color: t.on };
    return (
        <span title={title} style={style}
            className={`inline-flex max-w-full shrink-0 items-center whitespace-nowrap rounded-lg font-medium leading-none tracking-[0.1px] ${dims} ${icon ? (size === 'xs' ? 'pl-1' : 'pl-1.5') : ''} ${className}`}>
            {icon && <Icon name={icon} size={size === 'xs' ? 14 : 16} fill={iconFill} weight={500} />}
            <span className="block truncate leading-4 first-letter:uppercase">{children}</span>
        </span>
    );
}
