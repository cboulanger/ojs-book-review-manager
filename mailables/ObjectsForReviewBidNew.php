<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewBidNew.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewBidNew
 *
 * @brief Automatic notice to the responsible book review editor about a new bid.
 *  Sent from the journal contact (no Sender trait, so from() can be used).
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class ObjectsForReviewBidNew extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.brmBidNew.name';
    protected static ?string $description = 'emails.brmBidNew.description';
    protected static ?string $emailTemplateKey = 'BRM_BID_NEW';
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
