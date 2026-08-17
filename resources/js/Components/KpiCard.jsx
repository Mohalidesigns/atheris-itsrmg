export default function KpiCard({ label, value, sublabel, tone = 'navy', icon: Icon }) {
    const tones = {
        navy: 'bg-[#0A1F44] text-white',
        gold: 'bg-[#C9A86A] text-[#0A1F44]',
        green: 'bg-[#2D7D46] text-white',
        red: 'bg-[#B3261E] text-white',
        amber: 'bg-[#E5A100] text-white',
        white: 'bg-white border border-gray-200 text-[#2D3748]',
    };
    return (
        <div className={`rounded-xl p-4 ${tones[tone] || tones.white} shadow-sm`}>
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-xs font-medium opacity-80 uppercase tracking-wide">{label}</p>
                    <p className="text-2xl font-bold mt-1">{value}</p>
                    {sublabel && <p className="text-xs opacity-80 mt-1">{sublabel}</p>}
                </div>
                {Icon && <Icon className="w-6 h-6 opacity-80" />}
            </div>
        </div>
    );
}
