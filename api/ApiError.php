<?php

/**
 * @file plugins/generic/bookReviewManager/api/ApiError.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ApiError
 * @ingroup plugins_generic_bookReviewManager
 */

namespace APP\plugins\generic\bookReviewManager\api;

use Illuminate\Http\JsonResponse;

/**
 * @brief An error response of ObjectsForReviewController, thrown from deep inside a request.
 */
class ApiError extends \Exception {
	public function __construct(public int $status, public string $error, string $message, public array $extra = []) {
		parent::__construct($message);
	}

	public function response(): JsonResponse {
		return response()->json(['error' => $this->error, 'errorMessage' => $this->getMessage()] + $this->extra, $this->status);
	}
}
