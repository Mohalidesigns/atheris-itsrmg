<?php

namespace App\Modules\CBNCSAT\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatMaNarrative extends Model
{
    protected $table = 'csat_ma_narratives';

    /** CBN maturity narrative questions (Sheet 15): [domain, reference, question]. */
    public const TEMPLATES = [
        // Domain 1: Cyber Risk Management & Oversight
        [1, 'D1.1', 'Describe the institution\'s cyber risk management governance structure and how it aligns with the enterprise risk framework.'],
        [1, 'D1.2', 'Explain how the board and senior management are informed about cybersecurity risks and performance.'],
        [1, 'D1.3', 'Describe the budget allocation process for cybersecurity initiatives.'],
        [1, 'D1.4', 'Explain the cyber risk appetite statement and how it is enforced.'],
        [1, 'D1.5', 'Describe the cybersecurity workforce development and training programme.'],
        [1, 'D1.6', 'Detail the institution\'s approach to cybersecurity culture building.'],

        // Domain 2: Threat Intelligence & Collaboration
        [2, 'D2.1', 'Describe threat intelligence sources used and how intelligence is integrated into operations.'],
        [2, 'D2.2', 'Explain participation in industry information sharing groups (e.g., CBN CSIRT, FS-ISAC).'],
        [2, 'D2.3', 'Describe the process for monitoring and responding to emerging threats in the Nigerian banking sector.'],
        [2, 'D2.4', 'Detail threat modelling practices and their frequency.'],

        // Domain 3: Cybersecurity Controls
        [3, 'D3.1', 'Describe the preventative controls architecture (firewalls, IPS, endpoint protection).'],
        [3, 'D3.2', 'Explain the detective controls and monitoring capabilities (SIEM, SOC, log management).'],
        [3, 'D3.3', 'Describe the access control and identity management framework.'],
        [3, 'D3.4', 'Explain the data protection and encryption standards in place.'],
        [3, 'D3.5', 'Describe the vulnerability management programme and patching cadence.'],
        [3, 'D3.6', 'Detail the secure software development lifecycle (SDLC) practices.'],
        [3, 'D3.7', 'Describe network segmentation and microsegmentation controls.'],

        // Domain 4: External Dependency Management
        [4, 'D4.1', 'Describe the third-party risk management programme and due diligence process.'],
        [4, 'D4.2', 'Explain how outsourced services are monitored for cybersecurity compliance (per CBN outsourcing guidelines).'],
        [4, 'D4.3', 'Describe the cloud security governance framework and controls.'],
        [4, 'D4.4', 'Detail supply chain risk assessment practices for technology vendors.'],
        [4, 'D4.5', 'Describe the process for managing cyber risk in FinTech partnerships and API integrations.'],

        // Domain 5: Cyber Incident Management & Resilience
        [5, 'D5.1', 'Describe the incident response plan and team structure.'],
        [5, 'D5.2', 'Explain the escalation and notification procedures (including CBN 24-hour incident reporting requirement).'],
        [5, 'D5.3', 'Describe the business continuity and disaster recovery capabilities for cyber events.'],
        [5, 'D5.4', 'Detail the lessons-learned process and how incidents drive programme improvements.'],
        [5, 'D5.5', 'Describe cyber resilience testing practices (tabletop exercises, red team, penetration testing frequency).'],
    ];

    /** Create the narrative questions for an assessment (idempotent; keeps existing answers). */
    public static function provisionFor(int $assessmentId): void
    {
        foreach (self::TEMPLATES as $i => [$domainCode, $refCode, $questionText]) {
            static::firstOrCreate(
                ['assessment_id' => $assessmentId, 'question_number' => $i + 1],
                ['domain_code' => $domainCode, 'question_text' => $questionText]
            );
        }
    }

    protected $fillable = [
        'assessment_id', 'domain_code', 'question_number', 'question_text',
        'response_text', 'ai_draft_text', 'is_mandatory', 'min_characters', 'responded_by',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
