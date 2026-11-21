<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewContractOfferAgain.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewContractOfferAgain
 *
 * @brief Contract offer to a bidder from the kept list, after the previously selected reviewer dropped out.
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\traits\Sender;
use PKP\security\Role;

class ObjectsForReviewContractOfferAgain extends Mailable
{
    use Configurable;
    use Recipient;
    use Sender;

    protected static ?string $name = 'emails.brmContractOfferAgain.name';
    protected static ?string $description = 'emails.brmContractOfferAgain.description';
    protected static ?string $emailTemplateKey = 'BRM_CONTRACT_OFFER_AGAIN';
    protected static array $fromRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_READER, Role::ROLE_ID_AUTHOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
