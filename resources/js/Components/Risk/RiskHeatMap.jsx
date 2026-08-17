import { useState } from 'react';

const LIKELIHOOD_LABELS = {
    5: 'Almost Certain',
    4: 'Likely',
    3: 'Possible',
    2: 'Unlikely',
    1: 'Rare',
};

const IMPACT_LABELS = {
    1: 'Insignificant',
    2: 'Minor',
    3: 'Moderate',
    4: 'Major',
    5: 'Catastrophic',
};

export default function RiskHeatMap({ data, type = 'inherent', onCellClick }) {
    const [hoveredCell, setHoveredCell] = useState(null);

    const getCellData = (likelihood, impact) => {
        return data?.find(d => d.likelihood === likelihood && d.impact === impact) || {
            count: 0, risks: [], score: likelihood * impact, rating: 'very_low', color: '#319795',
        };
    };

    return (
        <div>
            <div className="flex items-center justify-between mb-3">
                <h4 className="text-sm font-semibold text-[#2D3748]">
                    {type === 'inherent' ? 'Inherent' : 'Residual'} Risk Heat Map
                </h4>
            </div>

            <div className="flex">
                {/* Y-axis label */}
                <div className="flex flex-col justify-center mr-2">
                    <span className="text-xs text-[#718096] font-medium -rotate-90 whitespace-nowrap">
                        LIKELIHOOD
                    </span>
                </div>

                <div className="flex-1">
                    {/* Grid */}
                    <div className="grid grid-cols-5 gap-1">
                        {[5, 4, 3, 2, 1].map(likelihood => (
                            [1, 2, 3, 4, 5].map(impact => {
                                const cell = getCellData(likelihood, impact);
                                const isHovered = hoveredCell?.likelihood === likelihood && hoveredCell?.impact === impact;

                                return (
                                    <div
                                        key={`${likelihood}-${impact}`}
                                        className={`relative flex flex-col items-center justify-center h-14 rounded-lg cursor-pointer transition-all duration-150 border-2 ${
                                            isHovered ? 'border-[#1A365D] scale-105 z-10 shadow-lg' : 'border-transparent'
                                        }`}
                                        style={{ backgroundColor: cell.color + '25' }}
                                        onMouseEnter={() => setHoveredCell({ likelihood, impact })}
                                        onMouseLeave={() => setHoveredCell(null)}
                                        onClick={() => onCellClick?.(cell)}
                                    >
                                        <span className="text-lg font-bold font-mono-data" style={{ color: cell.color }}>
                                            {cell.count || ''}
                                        </span>
                                        <span className="text-[9px] font-medium text-gray-500">
                                            {cell.score}
                                        </span>

                                        {/* Tooltip */}
                                        {isHovered && cell.count > 0 && (
                                            <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-48 bg-[#1A365D] text-white rounded-lg p-2 shadow-xl z-20 text-xs">
                                                <p className="font-semibold">{cell.count} risk{cell.count > 1 ? 's' : ''}</p>
                                                <div className="mt-1 space-y-0.5">
                                                    {cell.risks.slice(0, 3).map(r => (
                                                        <p key={r.id} className="truncate text-blue-200">
                                                            {r.code}: {r.title}
                                                        </p>
                                                    ))}
                                                    {cell.count > 3 && (
                                                        <p className="text-blue-300">+{cell.count - 3} more</p>
                                                    )}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                );
                            })
                        ))}
                    </div>

                    {/* Y-axis labels */}
                    <div className="absolute left-12 top-0 h-full flex flex-col justify-around pointer-events-none" style={{ display: 'none' }}>
                        {[5, 4, 3, 2, 1].map(l => (
                            <span key={l} className="text-[10px] text-gray-500">{LIKELIHOOD_LABELS[l]}</span>
                        ))}
                    </div>

                    {/* X-axis labels */}
                    <div className="grid grid-cols-5 gap-1 mt-1">
                        {[1, 2, 3, 4, 5].map(i => (
                            <span key={i} className="text-[10px] text-[#718096] text-center truncate">
                                {IMPACT_LABELS[i]}
                            </span>
                        ))}
                    </div>
                    <p className="text-xs text-[#718096] font-medium text-center mt-1">IMPACT</p>
                </div>
            </div>

            {/* Legend */}
            <div className="flex items-center justify-center gap-4 mt-3">
                {[
                    { label: 'Critical', color: '#C53030' },
                    { label: 'High', color: '#DD6B20' },
                    { label: 'Medium', color: '#D4AF37' },
                    { label: 'Low', color: '#2D7D46' },
                    { label: 'Very Low', color: '#319795' },
                ].map(item => (
                    <div key={item.label} className="flex items-center gap-1">
                        <div className="w-3 h-3 rounded" style={{ backgroundColor: item.color }} />
                        <span className="text-[10px] text-gray-500">{item.label}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
