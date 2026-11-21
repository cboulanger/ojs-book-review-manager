<?php

/**
 * @file plugins/generic/bookReviewManager/mailables/ObjectsForReviewPublisherNotification.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewPublisherNotification
 *
 * @brief Notice to the publisher contact that the review has been published.
 */

namespace APP\plugins\generic\bookReviewManager\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\traits\Sender;
use PKP\security\Role;

class ObjectsForReviewPublisherNotification extends Mailable
{
    use Configurable;
    use Recipient;
    use Sender;

    protected static ?string $name = 'emails.brmPublisherNotification.name';
    protected static ?string $description = 'emails.brmPublisherNotification.description';
    protected static ?string $emailTemplateKey = 'BRM_PUBLISHER_NOTIFICATION';
    protected static array $fromRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_READER];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
