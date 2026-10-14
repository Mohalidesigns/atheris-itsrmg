<?php

namespace App\Services\Ea\Maturity;

/**
 * The CMMI maturity scale used for the CBN EA maturity assessment (ATH-VAL-015).
 *
 * The CBN IT blueprint (ITSB v2.1) scores architecture maturity on a 0-5 scale and sets targets by
 * institution category (Category One: level 3; Category Two: level 2). CMMI's five levels are used
 * here because each one says what has to be true, which is what an assessor needs to rate against;
 * the ITSB name for each level is kept alongside so the return reads in the regulator's terms.
 */
class Cmmi
{
    public const LEVELS = [
        1 => ['name' => 'Initial', 'itsb' => 'Initial / ad hoc',
            'descriptor' => 'Performed ad hoc and depends on individuals; little or no documentation.'],
        2 => ['name' => 'Managed', 'itsb' => 'Repeatable',
            'descriptor' => 'Planned and performed for projects or units, with some documentation and named owners.'],
        3 => ['name' => 'Defined', 'itsb' => 'Defined',
            'descriptor' => 'An organisation-wide standard: documented, owned, kept current and used consistently.'],
        4 => ['name' => 'Quantitatively Managed', 'itsb' => 'Managed and measurable',
            'descriptor' => 'Measured: metrics or KRIs are tracked against targets and reviewed by management.'],
        5 => ['name' => 'Optimising', 'itsb' => 'Optimised',
            'descriptor' => 'Continuously improved from the measurements, with improvements piloted and adopted.'],
    ];

    /** Rating at or above this needs an evidence note; at or above `MEASURED` it also needs repository evidence. */
    public const EVIDENCE_NOTE = 3;

    public const MEASURED = 4;

    /**
     * Level the repository's documentation supports on its own (0-100 completeness). Capped at 4:
     * documentation can show an organisation-wide, measured practice, never that it is optimising.
     */
    public static function indicativeLevel(float $score): int
    {
        return match (true) {
            $score >= 85 => 4,
            $score >= 65 => 3,
            $score >= 40 => 2,
            $score >= 15 => 1,
            default => 0,
        };
    }

    /** A self-rating this many levels above the indicative level is flagged for the approver. */
    public const OVERSTATEMENT_GAP = 2;

    public static function name(?int $level): string
    {
        return match (true) {
            $level === null => 'Not rated',
            $level === 0 => 'Not evidenced',
            default => self::LEVELS[$level]['name'] ?? '—',
        };
    }
}
