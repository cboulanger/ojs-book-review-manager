<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewBidUnsuccessful.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewBidUnsuccessful
 *
 * @brief Notice to the other bidders once a contract has been accepted; they stay on the list.
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\traits\Sender;
use PKP\security\Role;

class ObjectsForReviewBidUnsuccessful extends Mailable
{
    use Configurable;
    use Recipient;
    use Sender;

    protected static ?string $name = 'emails.brmBidUnsuccessful.name';
    protected static ?string $description = 'emails.brmBidUnsuccessful.description';
    protected static ?string $emailTemplateKey = 'BRM_BID_UNSUCCESSFUL';
    protected static array $fromRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_READER, Role::ROLE_ID_AUTHOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
