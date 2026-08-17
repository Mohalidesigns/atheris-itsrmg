<?php

namespace App\Events\Ea;

use App\Models\Ea\EvidencePack;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §7.5: Ea\Events\EvidencePackGenerated → EvidenceVault\Listeners\Register
 *
 * Contract I-10. §7.3: packs currently "sit on local disk" — an auditor looking
 * in the Evidence Vault does not find them.
 */
class EvidencePackGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(public EvidencePack $pack)
    {
    }
}
