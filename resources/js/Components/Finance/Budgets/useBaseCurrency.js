import { usePage } from '@inertiajs/react';

/** Base currency from the page prop when the controller sends it, else the organisation currency. */
export default function useBaseCurrency(prop) {
    const { auth } = usePage().props;
    return prop || auth?.organization?.currency || 'NGN';
}
