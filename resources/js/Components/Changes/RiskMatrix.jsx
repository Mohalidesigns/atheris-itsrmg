import { RISK_COLOUR } from '@/Utils/changes';

/** 5×5 impact × likelihood grid with the selected cell highlighted (ChangeRiskScorer bands). */
export default function RiskMatrix({ impact, likelihood, size = 'h-7' }) {
    return (
        <div className="grid grid-cols-6 gap-0.5 text-[9px] text-[#718096]">
            {[5, 4, 3, 2, 1].map((l) => (
                <div key={l} className="contents">
                    <div className="flex items-center justify-end pr-1">{l}</div>
                    {[1, 2, 3, 4, 5].map((i) => {
                        const level = i * l >= 16 ? 'critical' : i * l >= 10 ? 'high' : i * l >= 5 ? 'medium' : 'low';
                        const active = Number(impact) === i && Number(likelihood) === l;
                        return <div key={i} className={`${size} rounded ${active ? 'ring-2 ring-[#0A1F44] ring-offset-1' : ''}`} style={{ backgroundColor: RISK_COLOUR[level], opacity: active ? 1 : 0.35 }} title={`Impact ${i} × Likelihood ${l} = ${i * l}`} />;
                    })}
                </div>
            ))}
            <div />
            {[1, 2, 3, 4, 5].map((i) => <div key={i} className="text-center">{i}</div>)}
            <div className="col-span-6 mt-1 text-center text-[9px]">Impact → · Likelihood ↑</div>
        </div>
    );
}
