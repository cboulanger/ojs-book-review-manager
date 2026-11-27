<?php

/**
 * @file plugins/generic/bookReviewManager/classes/ObjectForReview.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectForReview
 * @ingroup plugins_generic_objectForReview
 *
 * Data object representing a ObjectForReview.
 */

namespace APP\plugins\generic\bookReviewManager\classes;

class ObjectForReview extends \PKP\core\DataObject {

	/** Assignment modes: first come, first served, or the book review editor chooses among bidders */
	const MODE_DIRECT = 'direct';
	const MODE_BIDDING = 'bidding';
	const MODES = [self::MODE_DIRECT, self::MODE_BIDDING];

	/** Open access status of a work (not set: unknown) */
	const OPEN_ACCESS = 'open';
	const OPEN_ACCESS_PARTIAL = 'partial';
	const OPEN_ACCESS_CLOSED = 'closed';
	const OPEN_ACCESS_STATUSES = [self::OPEN_ACCESS, self::OPEN_ACCESS_PARTIAL, self::OPEN_ACCESS_CLOSED];

	/**
	 * Offer settings (see ObjectForReviewDAO::getAdditionalFieldNames()):
	 * - assignmentMode: MODE_DIRECT or MODE_BIDDING (unset: direct)
	 * - bidDeadline: optional last day for bids (Y-m-d)
	 * - biddingClosed: set when the editor selects a bidder; unset to reopen bidding
	 * - responsibleEditorId: book review editor who receives the notifications
	 * - offerClosed: the editor has withdrawn the offer
	 * - hidden: not on the public list (everything else keeps working)
	 * Review copy (see CopyWorkflow):
	 * - publisherContactId: user in the "Publisher contact" group
	 * - journalHoldsCopy: the journal has a copy in stock
	 * - returnRequired: physical copies must be returned if no review materialises
	 *   (stored as true/false; offers from before the review copy workflow: true)
	 * - notifyPublisher: the publisher wants to be notified of the published review
	 * - publisherNotified: date the publisher was notified
	 * Invitation round (see InvitationWorkflow), while one is running:
	 * - invitationMode: 'sequential'
	 * - invitationSubject, invitationBody: the email text edited when the round started
 * - invitationLocale: its language (the invitations' deadline and links follow it)
	 * - invitationStarted, invitationStartedBy: when and by whom (the sender of the emails)
	 * - invitationExhausted: date the round reached the end of the list
	 * Review essays (one review of several works; the container owns the offer):
	 * - bundleId: the review essay (container) this work belongs to
	 * - bundlePosition: its place in the container's list
	 */
	const OFFER_FIELDS = ['assignmentMode', 'bidDeadline', 'biddingClosed', 'responsibleEditorId', 'offerClosed', 'hidden',
		'publisherContactId', 'journalHoldsCopy', 'returnRequired', 'notifyPublisher', 'publisherNotified',
		'invitationMode', 'invitationSubject', 'invitationBody', 'invitationLocale', 'invitationStarted', 'invitationStartedBy', 'invitationExhausted',
		'bundleId', 'bundlePosition'];

	/** Resource type of a review essay container */
	const RESOURCE_TYPE_REVIEW_ESSAY = 'reviewEssay';

	function isReviewEssay(): bool {
		return $this->getResourceType() === self::RESOURCE_TYPE_REVIEW_ESSAY;
	}

	function getBundleId(): ?int {
		return $this->getData('bundleId') ? (int) $this->getData('bundleId') : null;
	}

	function isBundleMember(): bool {
		return $this->getBundleId() !== null;
	}

	function isHidden(): bool {
		return (bool) $this->getData('hidden');
	}

	function isReturnRequired(): bool {
		return $this->getData('returnRequired') === null || (bool) $this->getData('returnRequired');
	}

	function getPublisherContactId(): ?int {
		return $this->getData('publisherContactId') ? (int) $this->getData('publisherContactId') : null;
	}

	function getAssignmentMode(): string {
		return $this->getData('assignmentMode') === self::MODE_BIDDING ? self::MODE_BIDDING : self::MODE_DIRECT;
	}

	function isBidding(): bool {
		return $this->getAssignmentMode() === self::MODE_BIDDING;
	}

	function getResponsibleEditorId(): ?int {
		return $this->getData('responsibleEditorId') ? (int) $this->getData('responsibleEditorId') : null;
	}

	/**
	 * Set or delete an offer setting (unset settings are deleted from the settings table).
	 */
	function setOfferSetting(string $name, $value): void {
		if ($value === null || $value === '' || $value === false) {
			$this->unsetData($name);
		} else {
			$this->setData($name, $value);
		}
	}

	//
	// Get/set methods
	//

	/**
	 * Get context ID.
	 * @return int
	 */
	function getContextId(){
		return $this->getData('contextId');
	}

	/**
	 * Set context ID.
	 * @param $contextId int
	 */
	function setContextId($contextId) {
		return $this->setData('contextId', $contextId);
	}

	/**
	 * Get submission ID.
	 * @return int
	 */
	function getSubmissionId(){
		return $this->getData('submissionId');
	}

	/**
	 * Set submission ID.
	 * @param $submissionId int
	 */
	function setSubmissionId($submissionId) {
		return $this->setData('submissionId', $submissionId);
	}

	/**
	 * Get user ID.
	 * @return int
	 */
	function getUserId(){
		return $this->getData('userId');
	}

	/**
	 * Set user ID.
	 * @param $userId int
	 */
	function setUserId($userId) {
		return $this->setData('userId', $userId);
	}	

	/**
	 * Get objectId.
	 * @return int|null
	 */
	function getId(): ?int {
		$objectId = $this->getData('objectId');
		return $objectId === null ? null : (int) $objectId;
	}

	/**
	 * Set objectId.
	 * @param $objectId string
	 */ 
	function setId($objectId) {
		return $this->setData('objectId', $objectId);
	}

	/**
	 * Get identifier.
	 * @return string
	 */
	function getIdentifier() {
		return $this->getData('identifier');
	}

	/**
	 * Set identifier.
	 * @param $identifier string
	 */
	function setIdentifier($identifier) {
		return $this->setData('identifier', $identifier);
	}

	/**
	 * Get identifierType.
	 * @return string
	 */
	function getIdentifierType() {
		return $this->getData('identifierType');
	}

	/**
	 * Set identifierType.
	 * @param $identifierType string
	 */
	function setIdentifierType($identifierType) {
		return $this->setData('identifierType', $identifierType);
	}

	/**
	 * Get resourceType.
	 * @return string
	 */
	function getResourceType() {
		return $this->getData('resourceType');
	}

	/**
	 * Set resourceType.
	 * @param $resourceType string
	 */
	function setResourceType($resourceType) {
		return $this->setData('resourceType', $resourceType);
	}

	/**
	 * Get the citation: "Authors: Title. Publisher. Year."
	 * @return string
	 */
	function getCitation() {
		if ($this->isReviewEssay()) {
			$citations = array_map(fn (ObjectForReview $m) => $m->getCitation(), (new ObjectForReviewDAO())->getMembers((int) $this->getId()));
			return $citations ? $this->getTitle() . ' (' . implode('; ', $citations) . ')' : (string) $this->getTitle();
		}
		$description = $this->getData('title');
		if ($this->getData('authors'))
			$description = $this->getData('authors') . ": " . $description;
		if ($this->getData('publisher'))
			$description =  $description . ". " . $this->getData('publisher');
		if ($this->getData('year'))
			$description =  $description . ". " . $this->getData('year') . ".";
		return $description;
	}

	/**
	 * Get a URL for the identifier, if it can be linked (see IdentifierLinks).
	 * @return string|null
	 */
	function getIdentifierUrl() {
		return IdentifierLinks::url($this->getData('identifierType'), $this->getData('identifier'));
	}

	/**
	 * The work's page at the publisher, as entered (may be empty, see getWorkUrl()).
	 */
	function getPublisherUrl(): ?string {
		return $this->getData('publisherUrl') ?: null;
	}

	function setPublisherUrl(?string $url): void {
		$url = trim((string) $url);
		$url === '' ? $this->unsetData('publisherUrl') : $this->setData('publisherUrl', $url);
	}

	/**
	 * Where readers find out more about the work: the publisher's page if entered,
	 * otherwise the DOI or the WorldCat entry of the ISBN (null if there is none).
	 */
	function getWorkUrl(): ?string {
		return $this->getPublisherUrl() ?? $this->getIdentifierUrl();
	}

	/**
	 * Open access status: one of OPEN_ACCESS_STATUSES, null if unknown.
	 */
	function getOpenAccess(): ?string {
		$status = $this->getData('openAccess');
		return in_array($status, self::OPEN_ACCESS_STATUSES, true) ? $status : null;
	}

	function setOpenAccess(?string $status): void {
		in_array($status, self::OPEN_ACCESS_STATUSES, true) ? $this->setData('openAccess', $status) : $this->unsetData('openAccess');
	}

	/**
	 * A URL that may be stored as the publisher's page: http(s) only.
	 */
	static function isValidUrl(?string $url): bool {
		$url = trim((string) $url);
		return $url === '' || (filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url));
	}

	/**
	 * The identifier as "type: identifier", e.g. "isbn: 9780262046305".
	 */
	function getIdentifierLabel(): string {
		return IdentifierLinks::label($this->getData('identifierType'), $this->getData('identifier'));
	}

	/**
	 * The identifier as HTML "type: identifier", linked to its resource where possible.
	 */
	function getIdentifierHtml(): string {
		return IdentifierLinks::html($this->getData('identifierType'), $this->getData('identifier'));
	}

	/**
	 * Get authors.
	 * @return string
	 */
	function getAuthors() {
		return $this->getData('authors');
	}

	/**
	 * Set authors.
	 * @param $authors string
	 */
	function setAuthors($authors) {
		return $this->setData('authors', $authors);
	}

	/**
	 * Set title.
	 * @param $title string
	 */
	function setTitle($title) {
		return $this->setData('title', $title);
	}	

	/**
	 * Get title.
	 * @return string
	 */
	function getTitle() {
		return $this->getData('title');
	}

	/**
	 * Set description (rich text: what the work is about, why it is offered).
	 * @param $description string
	 */
	function setDescription($description) {
		return $this->setData('description', $description);
	}

	/**
	 * Get description (rich text).
	 * @return string|null
	 */
	function getDescription() {
		return $this->getData('description');
	}

	/**
	 * Set publisher.
	 * @param $publisher string
	 */
	function setPublisher($publisher) {
		return $this->setData('publisher', $publisher);
	}

	/**
	 * Get publisher.
	 * @return string
	 */
	function getPublisher() {
		return $this->getData('publisher');
	}	

	/**
	 * Set year.
	 * @param $year string
	 */
	function setYear($year) {
		return $this->setData('year', $year);
	}

	/**
	 * Get year.
	 * @return string
	 */
	function getYear() {
		return $this->getData('year');
	}

	/**
	 * Get creator.
	 * @return string
	 */
	function getCreator() {
		return $this->getData('creator');
	}

	/**
	 * Set creator.
	 * @param $creator string
	 */
	function setCreator($creator) {
		return $this->setData('creator', $creator);
	}

	/**
	 * Get dateCreated.
	 * @return string
	 */
	function getDateCreated() {
		return $this->getData('dateCreated');
	}

	/**
	 * Set dateCreated.
	 * @param $dateCreated string
	 */
	function setDateCreated($dateCreated) {
		return $this->setData('dateCreated', $dateCreated);
	}	

}

?>
