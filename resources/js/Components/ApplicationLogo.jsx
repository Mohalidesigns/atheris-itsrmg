// Govern brand wordmark. Use variant="white" on navy/dark backgrounds, "dark" on light ones.
export default function ApplicationLogo({ variant = 'dark', className = '', ...props }) {
    return (
        <img
            src={`/Govern/Govern-${variant === 'white' ? 'white' : 'dark'}.svg`}
            alt="Govern"
            className={className}
            {...props}
        />
    );
}
