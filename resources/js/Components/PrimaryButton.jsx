export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center rounded-lg border border-transparent bg-[#1A365D] px-4 py-2.5 text-sm font-semibold text-white transition duration-150 ease-in-out hover:bg-[#2D4A7A] focus:bg-[#2D4A7A] focus:outline-none focus:ring-2 focus:ring-[#1A365D]/50 focus:ring-offset-2 active:bg-[#0F2440] ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
