<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\MovePageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Handles the backend logic of moving a page from one title
 * to another.
 *
 * @since 1.24
 */
class MovePage {

	/**
	 * @var Title
	 */
	protected $oldTitle;

	/**
	 * @var Title
	 */
	protected $newTitle;

	/**
	 * @var ServiceOptions
	 */
	protected $options;

	/**
	 * @var ILoadBalancer
	 */
	protected $loadBalancer;

	/**
	 * @var NamespaceInfo
	 */
	protected $nsInfo;

	/**
	 * @var WatchedItemStoreInterface
	 */
	protected $watchedItems;

	/**
	 * @var PermissionManager
	 */
	protected $permMgr;

	/**
	 * @var RepoGroup
	 */
	protected $repoGroup;

	/**
	 * @var IContentHandlerFactory
	 */
	private $contentHandlerFactory;

	/**
	 * Calling this directly is deprecated in 1.34. Use MovePageFactory instead.
	 *
	 * @param Title $oldTitle
	 * @param Title $newTitle
	 * @param ServiceOptions|null $options
	 * @param ILoadBalancer|null $loadBalancer
	 * @param NamespaceInfo|null $nsInfo
	 * @param WatchedItemStoreInterface|null $watchedItems
	 * @param PermissionManager|null $permMgr
	 * @param RepoGroup|null $repoGroup
	 * @param IContentHandlerFactory|null $contentHandlerFactory
	 */
	public function __construct(
		Title $oldTitle,
		Title $newTitle,
		ServiceOptions $options = null,
		ILoadBalancer $loadBalancer = null,
		NamespaceInfo $nsInfo = null,
		WatchedItemStoreInterface $watchedItems = null,
		PermissionManager $permMgr = null,
		RepoGroup $repoGroup = null,
		IContentHandlerFactory $contentHandlerFactory = null
	) {
		$this->oldTitle = $oldTitle;
		$this->newTitle = $newTitle;
		$this->options = $options ??
			new ServiceOptions( MovePageFactory::CONSTRUCTOR_OPTIONS,
				MediaWikiServices::getInstance()->getMainConfig() );
		$this->loadBalancer =
			$loadBalancer ?? MediaWikiServices::getInstance()->getDBLoadBalancer();
		$this->nsInfo = $nsInfo ?? MediaWikiServices::getInstance()->getNamespaceInfo();
		$this->watchedItems =
			$watchedItems ?? MediaWikiServices::getInstance()->getWatchedItemStore();
		$this->permMgr = $permMgr ?? MediaWikiServices::getInstance()->getPermissionManager();
		$this->repoGroup = $repoGroup ?? MediaWikiServices::getInstance()->getRepoGroup();
		$this->contentHandlerFactory = (
			$contentHandlerFactory ?? MediaWikiServices::getInstance()->getContentHandlerFactory()
		);
	}

	/**
	 * Check if the user is allowed to perform the move.
	 *
	 * @param User $user
	 * @param string|null $reason To check against summary spam regex. Set to null to skip the check,
	 *   for instance to display errors preemptively before the user has filled in a summary.
	 * @return Status
	 */
	public function checkPermissions( User $user, $reason ) {
		$status = new Status();

		$errors = wfMergeErrorArrays(
			$this->permMgr->getPermissionErrors( 'move', $user, $this->oldTitle ),
			$this->permMgr->getPermissionErrors( 'edit', $user, $this->oldTitle ),
			$this->permMgr->getPermissionErrors( 'move-target', $user, $this->newTitle ),
			$this->permMgr->getPermissionErrors( 'edit', $user, $this->newTitle )
		);

		// Convert into a Status object
		if ( $errors ) {
			foreach ( $errors as $error ) {
				$status->fatal( ...$error );
			}
		}

		if ( $reason !== null && EditPage::matchSummarySpamRegex( $reason ) !== false ) {
			// This is kind of lame, won't display nice
			$status->fatal( 'spamprotectiontext' );
		}

		$tp = $this->newTitle->getTitleProtection();
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		if ( $tp !== false && !$permissionManager->userHasRight( $user, $tp['permission'] ) ) {
			$status->fatal( 'cantmove-titleprotected' );
		}

		Hooks::run( 'MovePageCheckPermissions',
			[ $this->oldTitle, $this->newTitle, $user, $reason, $status ]
		);

		return $status;
	}

	/**
	 * Does various sanity checks that the move is
	 * valid. Only things based on the two titles
	 * should be checked here.
	 *
	 * @return Status
	 */
	public function isValidMove() {
		$status = new Status();

		if ( $this->oldTitle->equals( $this->newTitle ) ) {
			$status->fatal( 'selfmove' );
		} elseif ( $this->newTitle->getArticleID() && !$this->isValidMoveTarget() ) {
			// The move is allowed only if (1) the target doesn't exist, or (2) the target is a
			// redirect to the source, and has no history (so we can undo bad moves right after
			// they're done).
			$status->fatal( 'articleexists', $this->newTitle->getPrefixedText() );
		}

		// @todo If the old title is invalid, maybe we should check if it somehow exists in the
		// database and allow moving it to a valid name? Why prohibit the move from an empty name
		// without checking in the database?
		if ( $this->oldTitle->getDBkey() == '' ) {
			$status->fatal( 'badarticleerror' );
		} elseif ( $this->oldTitle->isExternal() ) {
			$status->fatal( 'immobile-source-namespace-iw' );
		} elseif ( !$this->oldTitle->isMovable() ) {
			$status->fatal( 'immobile-source-namespace', $this->oldTitle->getNsText() );
		} elseif ( !$this->oldTitle->exists() ) {
			$status->fatal( 'movepage-source-doesnt-exist' );
		}

		if ( $this->newTitle->isExternal() ) {
			$status->fatal( 'immobile-target-namespace-iw' );
		} elseif ( !$this->newTitle->isMovable() ) {
			$status->fatal( 'immobile-target-namespace', $this->newTitle->getNsText() );
		}
		if ( !$this->newTitle->isValid() ) {
			$status->fatal( 'movepage-invalid-target-title' );
		}

		// Content model checks
		if ( !$this->options->get( 'ContentHandlerUseDB' ) &&
			$this->oldTitle->getContentModel() !== $this->newTitle->getContentModel() ) {
			// can't move a page if that would change the page's content model
			$status->fatal(
				'bad-target-model',
				ContentHandler::getLocalizedName( $this->oldTitle->getContentModel() ),
				ContentHandler::getLocalizedName( $this->newTitle->getContentModel() )
			);
		} elseif (
			!$this->contentHandlerFactory
				->getContentHandler( $this->oldTitle->getContentModel() )
				->canBeUsedOn( $this->newTitle )
		) {
			$status->fatal(
				'content-not-allowed-here',
				ContentHandler::getLocalizedName( $this->oldTitle->getContentModel() ),
				$this->newTitle->getPrefixedText(),
				SlotRecord::MAIN
			);
		}

		// Image-specific checks
		if ( $this->oldTitle->inNamespace( NS_FILE ) ) {
			$status->merge( $this->isValidFileMove() );
		}

		if ( $this->newTitle->inNamespace( NS_FILE ) && !$this->oldTitle->inNamespace( NS_FILE ) ) {
			$status->fatal( 'nonfile-cannot-move-to-file' );
		}

		// Hook for extensions to say a title can't be moved for technical reasons
		Hooks::run( 'MovePageIsValidMove', [ $this->oldTitle, $this->newTitle, $status ] );

		return $status;
	}

	/**
	 * Sanity checks for when a file is being moved
	 *
	 * @return Status
	 */
	protected function isValidFileMove() {
		$status = new Status();

		if ( !$this->newTitle->inNamespace( NS_FILE ) ) {
			$status->fatal( 'imagenocrossnamespace' );
			// No need for further errors about the target filename being wrong
			return $status;
		}

		$file = $this->repoGroup->getLocalRepo()->newFile( $this->oldTitle );
		$file->load( File::READ_LATEST );
		if ( $file->exists() ) {
			if ( $this->newTitle->getText() != wfStripIllegalFilenameChars( $this->newTitle->getText() ) ) {
				$status->fatal( 'imageinvalidfilename' );
			}
			if ( !File::checkExtensionCompatibility( $file, $this->newTitle->getDBkey() ) ) {
				$status->fatal( 'imagetypemismatch' );
			}
		}

		return $status;
	}

	/**
	 * Checks if $this can be moved to a given Title
	 * - Selects for update, so don't call it unless you mean business
	 *
	 * @since 1.25
	 * @return bool
	 */
	protected function isValidMoveTarget() {
		# Is it an existing file?
		if ( $this->newTitle->inNamespace( NS_FILE ) ) {
			$file = $this->repoGroup->getLocalRepo()->newFile( $this->newTitle );
			$file->load( File::READ_LATEST );
			if ( $file->exists() ) {
				wfDebug( __METHOD__ . ": file exists\n" );
				return false;
			}
		}
		# Is it a redirect with no history?
		if ( !$this->newTitle->isSingleRevRedirect() ) {
			wfDebug( __METHOD__ . ": not a one-rev redirect\n" );
			return false;
		}
		# Get the article text
		$rev = Revision::newFromTitle( $this->newTitle, false, Revision::READ_LATEST );
		if ( !is_object( $rev ) ) {
			return false;
		}
		$content = $rev->getContent();
		# Does the redirect point to the source?
		# Or is it a broken self-redirect, usually caused by namespace collisions?
		$redirTitle = $content ? $content->getRedirectTarget() : null;

		if ( $redirTitle ) {
			if ( $redirTitle->getPrefixedDBkey() !== $this->oldTitle->getPrefixedDBkey() &&
				$redirTitle->getPrefixedDBkey() !== $this->newTitle->getPrefixedDBkey() ) {
				wfDebug( __METHOD__ . ": redirect points to other page\n" );
				return false;
			} else {
				return true;
			}
		} else {
			# Fail safe (not a redirect after all. strange.)
			wfDebug( __METHOD__ . ": failsafe: database says " . $this->newTitle->getPrefixedDBkey() .
				" is a redirect, but it doesn't contain a valid redirect.\n" );
			return false;
		}
	}

	/**
	 * Move a page without taking user permissions into account. Only checks if the move is itself
	 * invalid, e.g., trying to move a special page or trying to move a page onto one that already
	 * exists.
	 *
	 * @param User $user
	 * @param string|null $reason
	 * @param bool|null $createRedirect
	 * @param string[] $changeTags Change tags to apply to the entry in the move log
	 * @return Status
	 */
	public function move(
		User $user, $reason = null, $createRedirect = true, array $changeTags = []
	) {
		$status = $this->isValidMove();
		if ( !$status->isOK() ) {
			return $status;
		}

		return $this->moveUnsafe( $user, $reason, $createRedirect, $changeTags );
	}

	/**
	 * Same as move(), but with permissions checks.
	 *
	 * @param User $user
	 * @param string|null $reason
	 * @param bool|null $createRedirect Ignored if user doesn't have suppressredirect permission
	 * @param string[] $changeTags Change tags to apply to the entry in the move log
	 * @return Status
	 */
	public function moveIfAllowed(
		User $user, $reason = null, $createRedirect = true, array $changeTags = []
	) {
		$status = $this->isValidMove();
		$status->merge( $this->checkPermissions( $user, $reason ) );
		if ( $changeTags ) {
			$status->merge( ChangeTags::canAddTagsAccompanyingChange( $changeTags, $user ) );
		}

		if ( !$status->isOK() ) {
			// Auto-block user's IP if the account was "hard" blocked
			$user->spreadAnyEditBlock();
			return $status;
		}

		// Check suppressredirect permission
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		if ( !$permissionManager->userHasRight( $user, 'suppressredirect' ) ) {
			$createRedirect = true;
		}

		return $this->moveUnsafe( $user, $reason, $createRedirect, $changeTags );
	}

	/**
	 * Move the source page's subpages to be subpages of the target page, without checking user
	 * permissions. The caller is responsible for moving the source page itself. We will still not
	 * do moves that are inherently not allowed, nor will we move more than $wgMaximumMovedPages.
	 *
	 * @param User $user
	 * @param string|null $reason The reason for the move
	 * @param bool|null $createRedirect Whether to create redirects from the old subpages to
	 *  the new ones
	 * @param string[] $changeTags Applied to entries in the move log and redirect page revision
	 * @return Status Good if no errors occurred. Ok if at least one page succeeded. The "value"
	 *  of the top-level status is an array containing the per-title status for each page. For any
	 *  move that succeeded, the "value" of the per-title status is the new page title.
	 */
	public function moveSubpages(
		User $user, $reason = null, $createRedirect = true, array $changeTags = []
	) {
		return $this->moveSubpagesInternal( false, $user, $reason, $createRedirect, $changeTags );
	}

	/**
	 * Move the source page's subpages to be subpages of the target page, with user permission
	 * checks. The caller is responsible for moving the source page itself.
	 *
	 * @param User $user
	 * @param string|null $reason The reason for the move
	 * @param bool|null $createRedirect Whether to create redirects from the old subpages to
	 *  the new ones. Ignored if the user doesn't have the 'suppressredirect' right.
	 * @param string[] $changeTags Applied to entries in the move log and redirect page revision
	 * @return Status Good if no errors occurred. Ok if at least one page succeeded. The "value"
	 *  of the top-level status is an array containing the per-title status for each page. For any
	 *  move that succeeded, the "value" of the per-title status is the new page title.
	 */
	public function moveSubpagesIfAllowed(
		User $user, $reason = null, $createRedirect = true, array $changeTags = []
	) {
		return $this->moveSubpagesInternal( true, $user, $reason, $createRedirect, $changeTags );
	}

	/**
	 * @param bool $checkPermissions
	 * @param User $user
	 * @param string $reason
	 * @param bool $createRedirect
	 * @param array $changeTags
	 * @return Status
	 */
	private function moveSubpagesInternal(
		$checkPermissions, User $user, $reason, $createRedirect, array $changeTags
	) {
		global $wgMaximumMovedPages;
		$services = MediaWikiServices::getInstance();

		if ( $checkPermissions ) {
			if ( !$services->getPermissionManager()->userCan(
				'move-subpages', $user, $this->oldTitle )
			) {
				return Status::newFatal( 'cant-move-subpages' );
			}
		}

		$nsInfo = $services->getNamespaceInfo();

		// Do the source and target namespaces support subpages?
		if ( !$nsInfo->hasSubpages( $this->oldTitle->getNamespace() ) ) {
			return Status::newFatal( 'namespace-nosubpages',
				$nsInfo->getCanonicalName( $this->oldTitle->getNamespace() ) );
		}
		if ( !$nsInfo->hasSubpages( $this->newTitle->getNamespace() ) ) {
			return Status::newFatal( 'namespace-nosubpages',
				$nsInfo->getCanonicalName( $this->newTitle->getNamespace() ) );
		}

		// Return a status for the overall result. Its value will be an array with per-title
		// status for each subpage. Merge any errors from the per-title statuses into the
		// top-level status without resetting the overall result.
		$topStatus = Status::newGood();
		$perTitleStatus = [];
		$subpages = $this->oldTitle->getSubpages( $wgMaximumMovedPages + 1 );
		$count = 0;
		foreach ( $subpages as $oldSubpage ) {
			$count++;
			if ( $count > $wgMaximumMovedPages ) {
				$status = Status::newFatal( 'movepage-max-pages', $wgMaximumMovedPages );
				$perTitleStatus[$oldSubpage->getPrefixedText()] = $status;
				$topStatus->merge( $status );
				$topStatus->setOK( true );
				break;
			}

			// We don't know whether this function was called before or after moving the root page,
			// so check both titles
			if ( $oldSubpage->getArticleID() == $this->oldTitle->getArticleID() ||
				$oldSubpage->getArticleID() == $this->newTitle->getArticleID()
			) {
				// When moving a page to a subpage of itself, don't move it twice
				continue;
			}
			$newPageName = preg_replace(
					'#^' . preg_quote( $this->oldTitle->getDBkey(), '#' ) . '#',
					StringUtils::escapeRegexReplacement( $this->newTitle->getDBkey() ), # T23234
					$oldSubpage->getDBkey() );
			if ( $oldSubpage->isTalkPage() ) {
				$newNs = $this->newTitle->getTalkPage()->getNamespace();
			} else {
				$newNs = $this->newTitle->getSubjectPage()->getNamespace();
			}
			// T16385: we need makeTitleSafe because the new page names may be longer than 255
			// characters.
			$newSubpage = Title::makeTitleSafe( $newNs, $newPageName );

			$mp = new MovePage( $oldSubpage, $newSubpage );
			$method = $checkPermissions ? 'moveIfAllowed' : 'move';
			/** @var Status $status */
			$status = $mp->$method( $user, $reason, $createRedirect, $changeTags );
			if ( $status->isOK() ) {
				$status->setResult( true, $newSubpage->getPrefixedText() );
			}
			$perTitleStatus[$oldSubpage->getPrefixedText()] = $status;
			$topStatus->merge( $status );
			$topStatus->setOK( true );
		}

		$topStatus->value = $perTitleStatus;
		return $topStatus;
	}

	/**
	 * Moves *without* any sort of safety or sanity checks. Hooks can still fail the move, however.
	 *
	 * @param User $user
	 * @param string $reason
	 * @param bool $createRedirect
	 * @param string[] $changeTags Change tags to apply to the entry in the move log
	 * @return Status
	 */
	private function moveUnsafe( User $user, $reason, $createRedirect, array $changeTags ) {
		$status = Status::newGood();
		Hooks::run( 'TitleMove', [ $this->oldTitle, $this->newTitle, $user, $reason, &$status ] );
		if ( !$status->isOK() ) {
			// Move was aborted by the hook
			return $status;
		}

		$dbw = $this->loadBalancer->getConnection( DB_MASTER );
		$dbw->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );

		Hooks::run( 'TitleMoveStarting', [ $this->oldTitle, $this->newTitle, $user ] );

		$pageid = $this->oldTitle->getArticleID( Title::READ_LATEST );
		$protected = $this->oldTitle->isProtected();

		// Do the actual move; if this fails, it will throw an MWException(!)
		$nullRevision = $this->moveToInternal( $user, $this->newTitle, $reason, $createRedirect,
			$changeTags );

		// Refresh the sortkey for this row.  Be careful to avoid resetting
		// cl_timestamp, which may disturb time-based lists on some sites.
		// @todo This block should be killed, it's duplicating code
		// from LinksUpdate::getCategoryInsertions() and friends.
		$prefixes = $dbw->select(
			'categorylinks',
			[ 'cl_sortkey_prefix', 'cl_to' ],
			[ 'cl_from' => $pageid ],
			__METHOD__
		);
		$type = $this->nsInfo->getCategoryLinkType( $this->newTitle->getNamespace() );
		foreach ( $prefixes as $prefixRow ) {
			$prefix = $prefixRow->cl_sortkey_prefix;
			$catTo = $prefixRow->cl_to;
			$dbw->update( 'categorylinks',
				[
					'cl_sortkey' => Collation::singleton()->getSortKey(
							$this->newTitle->getCategorySortkey( $prefix ) ),
					'cl_collation' => $this->options->get( 'CategoryCollation' ),
					'cl_type' => $type,
					'cl_timestamp=cl_timestamp' ],
				[
					'cl_from' => $pageid,
					'cl_to' => $catTo ],
				__METHOD__
			);
		}

		$redirid = $this->oldTitle->getArticleID();

		if ( $protected ) {
			# Protect the redirect title as the title used to be...
			$res = $dbw->select(
				'page_restrictions',
				[ 'pr_type', 'pr_level', 'pr_cascade', 'pr_user', 'pr_expiry' ],
				[ 'pr_page' => $pageid ],
				__METHOD__,
				'FOR UPDATE'
			);
			$rowsInsert = [];
			foreach ( $res as $row ) {
				$rowsInsert[] = [
					'pr_page' => $redirid,
					'pr_type' => $row->pr_type,
					'pr_level' => $row->pr_level,
					'pr_cascade' => $row->pr_cascade,
					'pr_user' => $row->pr_user,
					'pr_expiry' => $row->pr_expiry
				];
			}
			$dbw->insert( 'page_restrictions', $rowsInsert, __METHOD__, [ 'IGNORE' ] );

			// Build comment for log
			$comment = wfMessage(
				'prot_1movedto2',
				$this->oldTitle->getPrefixedText(),
				$this->newTitle->getPrefixedText()
			)->inContentLanguage()->text();
			if ( $reason ) {
				$comment .= wfMessage( 'colon-separator' )->inContentLanguage()->text() . $reason;
			}

			// reread inserted pr_ids for log relation
			$insertedPrIds = $dbw->select(
				'page_restrictions',
				'pr_id',
				[ 'pr_page' => $redirid ],
				__METHOD__
			);
			$logRelationsValues = [];
			foreach ( $insertedPrIds as $prid ) {
				$logRelationsValues[] = $prid->pr_id;
			}

			// Update the protection log
			$logEntry = new ManualLogEntry( 'protect', 'move_prot' );
			$logEntry->setTarget( $this->newTitle );
			$logEntry->setComment( $comment );
			$logEntry->setPerformer( $user );
			$logEntry->setParameters( [
				'4::oldtitle' => $this->oldTitle->getPrefixedText(),
			] );
			$logEntry->setRelations( [ 'pr_id' => $logRelationsValues ] );
			$logEntry->addTags( $changeTags );
			$logId = $logEntry->insert();
			$logEntry->publish( $logId );
		}

		// Update *_from_namespace fields as needed
		if ( $this->oldTitle->getNamespace() != $this->newTitle->getNamespace() ) {
			$dbw->update( 'pagelinks',
				[ 'pl_from_namespace' => $this->newTitle->getNamespace() ],
				[ 'pl_from' => $pageid ],
				__METHOD__
			);
			$dbw->update( 'templatelinks',
				[ 'tl_from_namespace' => $this->newTitle->getNamespace() ],
				[ 'tl_from' => $pageid ],
				__METHOD__
			);
			$dbw->update( 'imagelinks',
				[ 'il_from_namespace' => $this->newTitle->getNamespace() ],
				[ 'il_from' => $pageid ],
				__METHOD__
			);
		}

		# Update watchlists
		$oldtitle = $this->oldTitle->getDBkey();
		$newtitle = $this->newTitle->getDBkey();
		$oldsnamespace = $this->nsInfo->getSubject( $this->oldTitle->getNamespace() );
		$newsnamespace = $this->nsInfo->getSubject( $this->newTitle->getNamespace() );
		if ( $oldsnamespace != $newsnamespace || $oldtitle != $newtitle ) {
			$this->watchedItems->duplicateAllAssociatedEntries( $this->oldTitle, $this->newTitle );
		}

		// If it is a file then move it last.
		// This is done after all database changes so that file system errors cancel the transaction.
		if ( $this->oldTitle->getNamespace() == NS_FILE ) {
			$status = $this->moveFile( $this->oldTitle, $this->newTitle );
			if ( !$status->isOK() ) {
				$dbw->cancelAtomic( __METHOD__ );
				return $status;
			}
		}

		Hooks::run(
			'TitleMoveCompleting',
			[ $this->oldTitle, $this->newTitle,
				$user, $pageid, $redirid, $reason, $nullRevision ]
		);

		$dbw->endAtomic( __METHOD__ );

		$params = [
			&$this->oldTitle,
			&$this->newTitle,
			&$user,
			$pageid,
			$redirid,
			$reason,
			$nullRevision
		];
		// Keep each single hook handler atomic
		DeferredUpdates::addUpdate(
			new AtomicSectionUpdate(
				$dbw,
				__METHOD__,
				function () use ( $params ) {
					Hooks::run( 'TitleMoveComplete', $params );
				}
			)
		);

		return Status::newGood();
	}

	/**
	 * Move a file associated with a page to a new location.
	 * Can also be used to revert after a DB failure.
	 *
	 * @private
	 * @param Title $oldTitle Old location to move the file from.
	 * @param Title $newTitle New location to move the file to.
	 * @return Status
	 */
	private function moveFile( $oldTitle, $newTitle ) {
		$status = Status::newFatal(
			'cannotdelete',
			$oldTitle->getPrefixedText()
		);

		$file = $this->repoGroup->getLocalRepo()->newFile( $oldTitle );
		$file->load( File::READ_LATEST );
		if ( $file->exists() ) {
			$status = $file->move( $newTitle );
		}

		// Clear RepoGroup process cache
		$this->repoGroup->clearCache( $oldTitle );
		$this->repoGroup->clearCache( $newTitle ); # clear false negative cache
		return $status;
	}

	/**
	 * Move page to a title which is either a redirect to the
	 * source page or nonexistent
	 *
	 * @todo This was basically directly moved from Title, it should be split into
	 *   smaller functions
	 * @param User $user the User doing the move
	 * @param Title &$nt The page to move to, which should be a redirect or non-existent
	 * @param string $reason The reason for the move
	 * @param bool $createRedirect Whether to leave a redirect at the old title. Does not check
	 *   if the user has the suppressredirect right
	 * @param string[] $changeTags Change tags to apply to the entry in the move log
	 * @return Revision the revision created by the move
	 * @throws MWException
	 */
	private function moveToInternal( User $user, &$nt, $reason = '', $createRedirect = true,
		array $changeTags = []
	) {
		if ( $nt->exists() ) {
			$moveOverRedirect = true;
			$logType = 'move_redir';
		} else {
			$moveOverRedirect = false;
			$logType = 'move';
		}

		if ( $moveOverRedirect ) {
			$overwriteMessage = wfMessage(
					'delete_and_move_reason',
					$this->oldTitle->getPrefixedText()
				)->inContentLanguage()->text();
			$newpage = WikiPage::factory( $nt );
			$errs = [];
			$status = $newpage->doDeleteArticleReal(
				$overwriteMessage,
				/* $suppress */ false,
				$nt->getArticleID(),
				/* $commit */ false,
				$errs,
				$user,
				$changeTags,
				'delete_redir'
			);

			if ( !$status->isGood() ) {
				throw new MWException( 'Failed to delete page-move revision: '
					. $status->getWikiText( false, false, 'en' ) );
			}

			$nt->resetArticleID( false );
		}

		if ( $createRedirect ) {
			if ( $this->oldTitle->getNamespace() == NS_CATEGORY
				&& !wfMessage( 'category-move-redirect-override' )->inContentLanguage()->isDisabled()
			) {
				$redirectContent = new WikitextContent(
					wfMessage( 'category-move-redirect-override' )
						->params( $nt->getPrefixedText() )->inContentLanguage()->plain() );
			} else {
				$redirectContent = $this->contentHandlerFactory
					->getContentHandler( $this->oldTitle->getContentModel() )
					->makeRedirectContent(
						$nt,
						wfMessage( 'move-redirect-text' )->inContentLanguage()->plain()
					);
			}

			// NOTE: If this page's content model does not support redirects, $redirectContent will be null.
		} else {
			$redirectContent = null;
		}

		// Figure out whether the content model is no longer the default
		$oldDefault = ContentHandler::getDefaultModelFor( $this->oldTitle );
		$contentModel = $this->oldTitle->getContentModel();
		$newDefault = ContentHandler::getDefaultModelFor( $nt );
		$defaultContentModelChanging = ( $oldDefault !== $newDefault
			&& $oldDefault === $contentModel );

		// T59084: log_page should be the ID of the *moved* page
		$oldid = $this->oldTitle->getArticleID();
		$logTitle = clone $this->oldTitle;

		$logEntry = new ManualLogEntry( 'move', $logType );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $logTitle );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( [
			'4::target' => $nt->getPrefixedText(),
			'5::noredir' => $redirectContent ? '0' : '1',
		] );

		$formatter = LogFormatter::newFromEntry( $logEntry );
		$formatter->setContext( RequestContext::newExtraneousContext( $this->oldTitle ) );
		$comment = $formatter->getPlainActionText();
		if ( $reason ) {
			$comment .= wfMessage( 'colon-separator' )->inContentLanguage()->text() . $reason;
		}

		$dbw = $this->loadBalancer->getConnection( DB_MASTER );

		$oldpage = WikiPage::factory( $this->oldTitle );
		$oldcountable = $oldpage->isCountable();

		$newpage = WikiPage::factory( $nt );

		# Change the name of the target page:
		$dbw->update( 'page',
			/* SET */ [
				'page_namespace' => $nt->getNamespace(),
				'page_title' => $nt->getDBkey(),
			],
			/* WHERE */ [ 'page_id' => $oldid ],
			__METHOD__
		);

		# Save a null revision in the page's history notifying of the move
		$nullRevision = Revision::newNullRevision( $dbw, $oldid, $comment, true, $user );
		if ( !is_object( $nullRevision ) ) {
			throw new MWException( 'Failed to create null revision while moving page ID '
				. $oldid . ' to ' . $nt->getPrefixedDBkey() );
		}

		$nullRevId = $nullRevision->insertOn( $dbw );
		$logEntry->setAssociatedRevId( $nullRevId );

		/**
		 * T163966
		 * Increment user_editcount during page moves
		 * Moved from SpecialMovepage.php per T195550
		 */
		$user->incEditCount();

		if ( !$redirectContent ) {
			// Clean up the old title *before* reset article id - T47348
			WikiPage::onArticleDelete( $this->oldTitle );
		}

		$this->oldTitle->resetArticleID( 0 ); // 0 == non existing
		$nt->resetArticleID( $oldid );
		$newpage->loadPageData( WikiPage::READ_LOCKING ); // T48397

		$newpage->updateRevisionOn( $dbw, $nullRevision );

		Hooks::run( 'NewRevisionFromEditComplete',
			[ $newpage, $nullRevision, $nullRevision->getParentId(), $user ] );

		$newpage->doEditUpdates( $nullRevision, $user,
			[ 'changed' => false, 'moved' => true, 'oldcountable' => $oldcountable ] );

		// If the default content model changes, we need to populate rev_content_model
		if ( $defaultContentModelChanging ) {
			$dbw->update(
				'revision',
				[ 'rev_content_model' => $contentModel ],
				[ 'rev_page' => $nt->getArticleID(), 'rev_content_model IS NULL' ],
				__METHOD__
			);
		}

		WikiPage::onArticleCreate( $nt );

		# Recreate the redirect, this time in the other direction.
		if ( $redirectContent ) {
			$redirectArticle = WikiPage::factory( $this->oldTitle );
			$redirectArticle->loadFromRow( false, WikiPage::READ_LOCKING ); // T48397
			$newid = $redirectArticle->insertOn( $dbw );
			if ( $newid ) { // sanity
				$this->oldTitle->resetArticleID( $newid );
				$redirectRevision = new Revision( [
					'title' => $this->oldTitle, // for determining the default content model
					'page' => $newid,
					'user_text' => $user->getName(),
					'user' => $user->getId(),
					'comment' => $comment,
					'content' => $redirectContent ] );
				$redirectRevId = $redirectRevision->insertOn( $dbw );
				$redirectArticle->updateRevisionOn( $dbw, $redirectRevision, 0 );

				Hooks::run( 'NewRevisionFromEditComplete',
					[ $redirectArticle, $redirectRevision, false, $user ] );

				$redirectArticle->doEditUpdates( $redirectRevision, $user, [ 'created' => true ] );

				// make a copy because of log entry below
				$redirectTags = $changeTags;
				if ( in_array( 'mw-new-redirect', ChangeTags::getSoftwareTags() ) ) {
					$redirectTags[] = 'mw-new-redirect';
				}
				ChangeTags::addTags( $redirectTags, null, $redirectRevId, null );
			}
		}

		# Log the move
		$logid = $logEntry->insert();

		$logEntry->addTags( $changeTags );
		$logEntry->publish( $logid );

		return $nullRevision;
	}
}
