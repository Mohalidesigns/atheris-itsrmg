import { router } from '@inertiajs/react';
import { useState } from 'react';

/** Filter state that reloads the page (GET, preserveState) on change. Empty / false values are dropped from the query. */
export default function useFilters(routeName, initial, extra = {}) {
    const [f, setF] = useState(() => ({ ...initial, q: initial.q ?? '' }));
    const apply = (next) => {
        setF(next);
        const clean = Object.fromEntries(Object.entries({ ...extra, ...next }).filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== false));
        router.get(route(routeName), clean, { preserveState: true, preserveScroll: true, replace: true });
    };
    const set = (k) => (e) => apply({ ...f, [k]: e.target.value });
    const toggle = (k, v = true) => apply({ ...f, [k]: f[k] === v ? null : v });
    return { f, setF, apply, set, toggle };
}
