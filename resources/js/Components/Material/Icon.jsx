/**
 * Material Symbols (Rounded) glyph. `name` is the ligature from fonts.google.com/icons,
 * e.g. "check_circle". Decorative by default; pass `label` when the icon carries meaning alone.
 */
export default function Icon({ name, size = 18, fill = false, weight = 400, label, className = '', style, ...rest }) {
    return (
        <span
            className={`material-symbol shrink-0 ${className}`}
            style={{ fontSize: size, width: size, height: size, '--ms-fill': fill ? 1 : 0, '--ms-wght': weight, '--ms-opsz': size <= 20 ? 20 : 24, ...style }}
            aria-hidden={label ? undefined : true}
            role={label ? 'img' : undefined}
            aria-label={label}
            title={label}
            {...rest}
        >
            {name}
        </span>
    );
}
