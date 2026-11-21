<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewContractDeclined.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewContractDeclined
 *
 * @brief Automatic notice to the responsible book review editor that a contract offer was declined or has expired.
 *  Sent from the journal contact (no Sender trait, so from() can be used).
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class ObjectsForReviewContractDeclined extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.brmContractDeclined.name';
    protected static ?string $description = 'emails.brmContractDeclined.description';
    protected static ?string $emailTemplateKey = 'BRM_CONTRACT_DECLINED';
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
