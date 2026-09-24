<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Account;

use Foreningssystem\Domain\Membership\AssociationDate;

interface MemberAccountProvisioner
{
    public function provision(int $personId, AssociationDate $on): ProvisioningResult;
}
