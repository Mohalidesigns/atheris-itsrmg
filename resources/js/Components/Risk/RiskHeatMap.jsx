import { useState } from 'react';
import { IMPACT_LABELS, LIKELIHOOD_LABELS, RATING_BANDS, ratingColor, ratingFor } from '@/Utils/risk';

export default function RiskHeatMap({ data, type = 'inherent', onCellClick }) {
    const [hoveredCell, setHoveredCell] = useState(null);

    const getCellData = (likelihood, impact) => {
        const score = likelihood * impact;
        return data?.find(d => d.likelihood === likelihood && d.impact === impact) || {
            count: 0, risks: [], score, rating: ratingFor(score), color: ratingColor(score),
        };
    };

    const total = (data || []).reduce((sum, c) => sum + (c.count || 0), 0);

    return (
        <div>
            <div className="flex items-center justify-between mb-3">
                <h4 className="text-sm font-semibold text-[#2D3748]">
                    {type === 'inherent' ? 'Inherent' : 'Residual'} Risk Heat Map
                </h4>
                <span className="text-xs text-[#718096]">{total} active risk{total === 1 ? '' : 's'} plotted{onCellClick ? ' · click a cell to drill down' : ''}</span>
            </div>

            <div className="flex">
                <div className="flex flex-col justify-center mr-2">
                    <span className="text-xs text-[#718096] font-medium -rotate-90 whitespace-nowrap">LIKELIHOOD</span>
                </div>

                <div className="grid grid-rows-5 gap-1 mr-1">
                    {[5, 4, 3, 2, 1].map(l => (
                        <span key={l} className="h-14 flex items-center justify-end text-[10px] text-[#718096] text-right leading-tight w-16">
                            {LIKELIHOOD_LABELS[l]}
                        </span>
                    ))}
                </div>

                <div className="flex-1">
                    <div className="grid grid-cols-5 gap-1">
                        {[5, 4, 3, 2, 1].map(likelihood => (
                            [1, 2, 3, 4, 5].map(impact => {
                                const cell = getCellData(likelihood, impact);
                                const isHovered = hoveredCell?.likelihood === likelihood && hoveredCell?.impact === impact;
                                const clickable = onCellClick && cell.count > 0;

                                return (
                                    <div
                                        key={`${likelihood}-${impact}`}
                                        role={clickable ? 'button' : undefined}
                                        aria-label={`Likelihood ${likelihood}, impact ${impact}: ${cell.count} risk(s)`}
                                        className={`relative flex flex-col items-center justify-center h-14 rounded-lg transition-all duration-150 border-2 ${
                                            clickable ? 'cursor-pointer' : 'cursor-default'
                                        } ${isHovered ? 'border-[#1A365D] scale-105 z-10 shadow-lg' : 'border-transparent'}`}
                                        style={{ backgroundColor: cell.color + (cell.count > 0 ? '40' : '18') }}
                                        onMouseEnter={() => setHoveredCell({ likelihood, impact })}
                                        onMouseLeave={() => setHoveredCell(null)}
                                        onClick={() => clickable && onCellClick(cell)}
                                    >
                                        <span className="text-lg font-bold font-mono-data" style={{ color: cell.color }}>
                                            {cell.count || ''}
                                        </span>
                                        <span className="text-[9px] font-medium text-gray-500">{cell.score}</span>

                                        {isHovered && cell.count > 0 && (
                                            <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-56 bg-[#1A365D] text-white rounded-lg p-2 shadow-xl z-20 text-xs">
                                                <p className="font-semibold">{cell.count} risk{cell.count > 1 ? 's' : ''} · score {cell.score}</p>
                                                <div className="mt-1 space-y-0.5">
                                                    {cell.risks.slice(0, 4).map(r => (
                                                        <p key={r.id} className="truncate text-blue-200">{r.code}: {r.title}</p>
                                                    ))}
                                                    {cell.count > 4 && <p className="text-blue-300">+{cell.count - 4} more</p>}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                );
                            })
                        ))}
                    </div>

                    <div className="grid grid-cols-5 gap-1 mt-1">
                        {[1, 2, 3, 4, 5].map(i => (
                            <span key={i} className="text-[10px] text-[#718096] text-center truncate">{IMPACT_LABELS[i]}</span>
                        ))}
                    </div>
                    <p className="text-xs text-[#718096] font-medium text-center mt-1">IMPACT</p>
                </div>
            </div>

            <div className="flex items-center justify-center flex-wrap gap-4 mt-3">
                {RATING_BANDS.map(b => (
                    <div key={b.key} className="flex items-center gap-1">
                        <div className="w-3 h-3 rounded" style={{ backgroundColor: b.color }} />
                        <span className="text-[10px] text-gray-500">{b.label} ({b.min}+)</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
