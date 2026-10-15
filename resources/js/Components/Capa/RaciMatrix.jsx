import { Card, RACI_TONE, RACI_WORD } from './shared';

export default function RaciMatrix({ capa, config }) {
    const roles = config.raciRoles || [];
    const rows = (capa.raci?.length ? capa.raci : config.raci) || [];
    return (
        <Card title="RACI matrix" icon="grid_view">
            <div className="-mx-4 overflow-x-auto sm:mx-0">
                <table className="min-w-full text-xs">
                    <thead>
                        <tr className="text-left text-[11px] text-[#718096]">
                            <th className="px-2 py-1.5 font-medium">Stage</th>
                            {roles.map((r) => <th key={r.key} className={`px-2 py-1.5 text-center font-medium ${(capa.my_raci_roles || []).includes(r.key) ? 'text-[#0A1F44]' : ''}`} title={r.label}>{r.label.split(' / ')[0].replace('IT Infrastructure & Operations', 'IT Ops').replace('Information Security', 'InfoSec').replace('Internal IT Auditor', 'Audit')}</th>)}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.map((row) => (
                            <tr key={row.stage} className={row.stage === capa.stage ? 'bg-[#C9A86A]/10' : ''}>
                                <td className={`px-2 py-1.5 ${row.stage === capa.stage ? 'font-semibold text-[#0A1F44]' : 'text-[#2D3748]'}`}>{row.label}</td>
                                {roles.map((r) => {
                                    const l = row.assignments?.[r.key];
                                    return <td key={r.key} className="px-2 py-1.5 text-center">{l && <span title={RACI_WORD[l]} className={`inline-flex h-6 w-6 items-center justify-center rounded-lg font-bold ${RACI_TONE[l]}`}>{l}</span>}</td>;
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="mt-2 text-[10px] text-[#718096]">A Accountable · R Responsible · C Consulted · I Informed. Roles: {roles.map((r) => r.label).join('; ')}.</p>
        </Card>
    );
}
