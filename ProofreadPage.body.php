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
 * @ingroup ProofreadPage
 */

use ProofreadPage\Context;
use ProofreadPage\Page\PageContent;
use ProofreadPage\Page\PageContentBuilder;
use ProofreadPage\FileNotFoundException;
use ProofreadPage\Pagination\PageNotInPaginationException;

/*
 @todo :
 - check unicity of the index page : when index is saved too
*/

class ProofreadPage {

	/**
	 * @deprecated use Context::getPageNamespaceId
	 *
	 * Returns id of Page namespace.
	 *
	 * @return integer
	 */
	public static function getPageNamespaceId() {
		return Context::getDefaultContext()->getPageNamespaceId();
	}

	/**
	 * @deprecated use Context::getIndexNamespaceId
	 *
	 * Returns id of Index namespace.
	 *
	 * @return integer
	 */
	public static function getIndexNamespaceId() {
		return Context::getDefaultContext()->getIndexNamespaceId();
	}

	/**
	 * @deprecated
	 * @return array
	 */
	public static function getPageAndIndexNamespace() {
		static $res;
		if ( $res === null ) {
			global $wgExtraNamespaces;
			$res = [
				preg_quote( $wgExtraNamespaces[self::getPageNamespaceId()], '/' ),
				preg_quote( $wgExtraNamespaces[self::getIndexNamespaceId()], '/' ),
			];
		}
		return $res;
	}

	/**
	 * @param array $queryPages
	 * @return bool
	 */
	public static function onwgQueryPages( &$queryPages ) {
		$queryPages[] = [ 'ProofreadPages', 'IndexPages' ];
		$queryPages[] = [ 'PagesWithoutScans', 'PagesWithoutScans' ];
		return true;
	}

	/**
	 * Set up content handlers
	 *
	 * @param Title $title the title page
	 * @param string $model the content model for the page
	 * @return boolean if we have to continue the research for a content handler
	 */
	public static function onContentHandlerDefaultModelFor( Title $title, &$model ) {
		if ( $title->inNamespace( self::getPageNamespaceId() ) ) {
			$model = CONTENT_MODEL_PROOFREAD_PAGE;
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Set up our custom edition system.
	 *
	 * @param Article $article  being edited
	 * @param User $user User performing the edit
	 * @return boolean hook return value
	 */
	public static function onCustomEditor( Article $article, User $user ) {
		if ( $article->getTitle()->inNamespace( self::getIndexNamespaceId() ) ) { // TODO ExternalEditor case
			$editor = new EditProofreadIndexPage( $article );
			$editor->edit();
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Set up our custom parser hooks when initializing parser.
	 *
	 * @param Parser $parser
	 * @return boolean hook return value
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'pagelist', [ 'ProofreadPage\Parser\ParserEntryPoint', 'renderPagelistTag' ] );
		$parser->setHook( 'pages', [ 'ProofreadPage\Parser\ParserEntryPoint', 'renderPagesTag' ] );
		$parser->setHook( 'pagequality', [ 'ProofreadPage\Parser\ParserEntryPoint', 'renderPagequalityTag' ] );
		return true;
	}

	/**
	 * Query the database to find if the current page is referred in an Index page.
	 * @param Title $title
	 */
	public static function loadIndex( $title ) {
		$title->prpIndexPage = null;
		$result = ProofreadIndexDbConnector::getRowsFromTitle( $title );

		foreach ( $result as $x ) {
			$refTitle = Title::makeTitle( $x->page_namespace, $x->page_title );
			if ( $refTitle !== null && $refTitle->inNamespace( self::getIndexNamespaceId() ) ) {
				$title->prpIndexPage = ProofreadIndexPage::newFromTitle( $refTitle );
				return;
			}
		}

		$m = explode( '/', $title->getText(), 2 );
		if ( !isset( $m[1] ) ) {
			return;
		}
		$imageTitle = Title::makeTitleSafe( NS_IMAGE, $m[0] );
		if ( $imageTitle === null ) {
			return;
		}
		$image = wfFindFile( $imageTitle );
		// if it is multipage, we use the page order of the file
		if ( $image && $image->exists() && $image->isMultipage() ) {
			$indexTitle = Title::makeTitle( self::getIndexNamespaceId(), $image->getTitle()->getText() );
			if ( $indexTitle !== null ) {
				$title->prpIndexPage = ProofreadIndexPage::newFromTitle( $indexTitle );
			}
		}
	}

	/**
	 * Append javascript variables and code to the page.
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function onBeforePageDisplay( OutputPage $out ) {
		$action = $out->getRequest()->getVal( 'action' );
		$isEdit = ( $action === 'submit' || $action === 'edit' );
		$title = $out->getTitle();

		if ( $title->inNamespace( self::getIndexNamespaceId() ) ) {
			$out->addModuleStyles( 'ext.proofreadpage.base' );
		} elseif ( $title->inNamespace( self::getPageNamespaceId() ) ) {
			$out->addModules( 'ext.proofreadpage.page.navigation' );
		} elseif (
			$title->inNamespace( NS_MAIN ) &&
			( $out->isArticle() || $isEdit ) &&
			!isset( $out->proofreadPageDone )
		) {
			$out->proofreadPageDone = true;
			self::prepareArticle( $out );
		}

		return true;
	}

	/**
	 * Hook function
	 * @param $page_ids
	 * @param $colours
	 * @return bool
	 */
	public static function onGetLinkColours( $page_ids, &$colours ) {
		global $wgTitle;
		if ( !isset( $wgTitle ) ) {
			return true;
		}
		self::getLinkColours( $page_ids, $colours );
		return true;
	}

	/**
	 * Return the quality colour codes to pages linked from an index page
	 * @param array $page_ids
	 * @param array $colours
	 */
	private static function getLinkColours( $page_ids, &$colours ) {
		global $wgTitle;

		$page_namespace_id = self::getPageNamespaceId();
		$in_index_namespace = $wgTitle->inNamespace( self::getIndexNamespaceId() );

		$values = [];
		foreach ( $page_ids as $id => $pdbk ) {
			$title = Title::newFromText( $pdbk );
			// consider only link in page namespace
			if ( $title->inNamespace( $page_namespace_id ) ) {
				if ( $in_index_namespace ) {
					$colours[$pdbk] = 'quality1 prp-pagequality-1';
				} else {
					$colours[$pdbk] = 'prp-pagequality-1';
				}
				$values[] = intval( $id );
			}
		}

		// Get the names of the quality categories.  Replaces earlier code which
		// called wfMessage()->inContentLanguagE() 5 times for each page.
		// ISSUE: Should the number of quality levels be adjustable?
		// ISSUE 2: Should this array be saved as a member variable?
		// How often is this code called anyway?
		$qualityCategories = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$cat = Title::makeTitleSafe( NS_CATEGORY, wfMessage( "proofreadpage_quality{$i}_category" )->inContentLanguage()->text() );
			if ( $cat ) {
				if ( $in_index_namespace ) {
					$qualityCategories[$cat->getDBkey()] = 'quality' . $i . ' prp-pagequality-' . $i;
				} else {
					$qualityCategories[$cat->getDBkey()] = 'prp-pagequality-' . $i;
				}
			}
		}

		if ( count( $values ) ) {
			$res = ProofreadPageDbConnector::getCategoryNamesForPageIds( $values );
			foreach ( $res as $x ) {
				$pdbk = $page_ids[$x->cl_from];
				if ( array_key_exists( $x->cl_to, $qualityCategories ) ) {
					$colours[$pdbk] = $qualityCategories[$x->cl_to];
				}
			}
		}
	}

	/**
	 * @param ImagePage $imgpage
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function onImageOpenShowImageInlineBefore( ImagePage &$imgpage, OutputPage &$out ) {
		$image = $imgpage->getFile();
		if ( !$image->isMultipage() ) {
			return true;
		}
		$name = $image->getTitle()->getText();
		$title = Title::makeTitle( self::getIndexNamespaceId(), $name );
		$link = Linker::link( $title, $out->msg( 'proofreadpage_image_message' )->text(), [], [], 'known' );
		$out->addHTML( $link );
		return true;
	}

	/**
	 * Set is_toc flag (true if page is a table of contents)
	 * @param OutputPage $outputPage
	 * @param ParserOutput $parserOutput
	 * @return bool
	 */
	public static function onOutputPageParserOutput( OutputPage $outputPage, ParserOutput $parserOutput ) {
		if ( isset( $parserOutput->is_toc ) ) {
			$outputPage->is_toc = $parserOutput->is_toc;
		} else {
			$outputPage->is_toc = false;
		}
		return true;
	}

	/**
	 * Updates index data for an index referencing the specified page.
	 * @param Title $title page title object
	 * @param boolean $deleted indicates whether the page was deleted
	 */
	private static function updateIndexOfPage( Title $title, $deleted = false ) {
		self::loadIndex( $title );
		if ( $title->prpIndexPage !== null ) {
			$indexTitle = $title->prpIndexPage->getTitle();
			$indexTitle->invalidateCache();
			$index = new Article( $indexTitle );
			if ( $index ) {
				self::updatePrIndex( $index, $deleted ? $title : null );
			}
		}
	}

	/**
	 * @param WikiPage $article
	 * @return bool
	 */
	public static function onArticleSaveComplete( WikiPage &$article ) {
		$title = $article->getTitle();

		// if it's an index, update pr_index table
		if ( $title->inNamespace( ProofreadPage::getIndexNamespaceId() ) ) {	// Move this part to EditProofreadIndexPage
			ProofreadPage::updatePrIndex( $article );
			return true;
		}

		// return if it is not a page
		if ( !$title->inNamespace( ProofreadPage::getPageNamespaceId() ) ) {
			return true;
		}

		/* check if there is an index */
		if ( !isset( $title->prpIndexPage ) ) {
			ProofreadPage::loadIndex( $title );
		}
		if ( $title->prpIndexPage === null ) {
			return true;
		}

		/**
		 * invalidate the cache of the index page
		 */
		$title->prpIndexPage->getTitle()->invalidateCache();

		/**
		 * update pr_index iteratively
		 */
		$indexId = $title->prpIndexPage->getTitle()->getArticleID();
		$indexData = ProofreadIndexDbConnector::getIndexDataFromIndexPageId( $indexId );
		if ( $indexData ) {
			ProofreadIndexDbConnector::replaceIndexById( $indexData, $indexId, $article );
		}

		return true;
	}

	/**
	 * if I delete a page, I need to update the index table
	 * if I delete an index page too...
	 *
	 * @param $article Article object
	 * @return Boolean: true
	 */
	public static function onArticleDelete( Article $article ) {
		$title = $article->getTitle();

		// Process Index removal.
		if ( $title->inNamespace( self::getIndexNamespaceId() ) ) {
			ProofreadIndexDbConnector::removeIndexData( $article->getId() );

		// Process Page removal.
		} elseif ( $title->inNamespace( self::getPageNamespaceId() ) ) {
			self::updateIndexOfPage( $title, true );
		}

		return true;
	}

	/**
	 * @param Title $title Title corresponding to the article restored
	 * @param bool $create If true, the restored page didn't exist before
	 * @param string $comment Comment explaining the undeletion
	 * @param int $oldPageId ID of page previously deleted from archive table
	 * @return bool
	 */
	public static function onArticleUndelete( Title $title, $create, $comment, $oldPageId ) {
		// Process Index restoration.
		if ( $title->inNamespace( self::getIndexNamespaceId() ) ) {
			$index = new Article( $title );
			if ( $index ) {
				self::updatePrIndex( $index );
			}

		// Process Page restoration.
		} elseif ( $title->inNamespace( self::getPageNamespaceId() ) ) {
			self::updateIndexOfPage( $title );
		}

		return true;
	}

	/**
	 * @param MovePageForm $form
	 * @param Title $ot
	 * @param Title $nt
	 * @return bool
	 */
	public static function onSpecialMovepageAfterMove( MovePageForm &$form, Title &$ot, Title &$nt ) {
		if ( $ot->inNamespace( self::getPageNamespaceId() ) ) {
			self::updateIndexOfPage( $ot );
		} elseif ( $ot->inNamespace( self::getIndexNamespaceId() )
			  && !$nt->inNamespace( self::getIndexNamespaceId() ) ) {
			// The page is moved out of the Index namespace.
			// Remove all index data associated with that page.

			// $nt is used here on purpose, as we need to get the page id.
			// There is no page under the old title or it is a redirect.
			$article = new Article( $nt );
			if ( $article ) {
				ProofreadIndexDbConnector::removeIndexData( $article->getId() );
			}
		}

		if ( $nt->inNamespace( self::getPageNamespaceId() ) ) {
			self::loadIndex( $nt );
			if ( $nt->prpIndexPage !== null
				&& ( !isset( $ot->prpIndexPage ) || ( $nt->prpIndexPage->getTitle()->equals( $ot->prpIndexPage->getTitle() ) ) ) ) {
				self::updateIndexOfPage( $nt );
			}
		} elseif ( $nt->inNamespace( self::getIndexNamespaceId() ) ) {
			// Update index data.
			$article = new Article( $nt );
			if ( $article ) {
				self::updatePrIndex( $article );
			}
		}
		return true;
	}

	/**
	 * When an index page is created or purged, recompute pr_index values
	 * @param Article $article
	 * @return bool
	 */
	public static function onArticlePurge( Article $article ) {
		$title = $article->getTitle();
		if ( $title->inNamespace( self::getIndexNamespaceId() ) ) {
			self::updatePrIndex( $article );
			return true;
		}
		return true;
	}

	/**
	 * Update the pr_index entry of an article
	 * @param Article $index
	 * @param Title|null $deletedpage
	 */
	public static function updatePrIndex( Article $index, $deletedPage = null ) {
		$indexTitle = $index->getTitle();
		$indexId = $index->getId();

		// read the list of pages
		$pages = [];
		$pagination = Context::getDefaultContext()->getPaginationFactory()->getPaginationForIndexPage(
			ProofreadIndexPage::newFromTitle( $indexTitle )
		);
		foreach ( $pagination as $page ) {
			if ( $deletedPage === null || !$page->getTitle()->equals( $deletedPage ) ) {
				array_push( $pages, $page->getTitle()->getDBkey() );
			}
		}

		if ( !count( $pages ) ) {
			return;
		}

		$total = ProofreadPageDbConnector::getNumberOfExistingPagesFromPageTitle( $pages );

		if ( $total === null ) {
			return;
		}

		// proofreading status of pages
		$queryArr = [
			'tables' => [ 'page', 'categorylinks' ],
			'fields' => [ 'COUNT(page_id) AS count' ],
			'conds' => [ 'cl_to' => '', 'page_namespace' => self::getPageNamespaceId(), 'page_title' => $pages ],
			'joins' => [ 'categorylinks' => [ 'LEFT JOIN', 'cl_from=page_id' ] ]
		];

		$n0 = ProofreadPageDbConnector::queryCount( $queryArr, 'proofreadpage_quality0_category' );
		$n2 = ProofreadPageDbConnector::queryCount( $queryArr, 'proofreadpage_quality2_category' );
		$n3 = ProofreadPageDbConnector::queryCount( $queryArr, 'proofreadpage_quality3_category' );
		$n4 = ProofreadPageDbConnector::queryCount( $queryArr, 'proofreadpage_quality4_category' );
		$n1 = $total - $n0 - $n2 - $n3 - $n4;

		ProofreadIndexDbConnector::setIndexData( $pagination->getNumberOfPages(), $n0, $n1, $n2, $n3, $n4, $indexId );
	}

	/**
	 * In main namespace, display the proofreading status of transcluded pages.
	 *
	 * @param OutputPage $out
	 * @return bool
	 */
	private static function prepareArticle( OutputPage $out ) {
		$id = $out->getTitle()->getArticleID();
		if ( $id == -1 ) {
			return true;
		}
		$pageNamespaceId = self::getPageNamespaceId();
		$indexNamespaceId = self::getIndexNamespaceId();
		if ( $pageNamespaceId == null || $indexNamespaceId == null ) {
			return true;
		}

		// find the index page
		$indextitle = ProofreadPageDbConnector::getIndexTitleForPageId( $id );

		if ( isset( $out->is_toc ) && $out->is_toc ) {
			$n = 0;

			if ( $indextitle ) {
				$row = ProofreadIndexDbConnector::getIndexDataFromIndexTitle( $indextitle );
				if ( $row ) {
					$n0 = $row->pr_q0;
					$n1 = $row->pr_q1;
					$n2 = $row->pr_q2;
					$n3 = $row->pr_q3;
					$n4 = $row->pr_q4;
					$n = $row->pr_count;
					$ne = $n - ( $n0 + $n1 + $n2 + $n3 + $n4 );
				}
			}
		} else {
			// count transclusions from page namespace
			$n = ProofreadPageDbConnector::countTransclusionFromPageId( $id );
			if ( $n === null ) {
				return true;
			}

			// find the proofreading status of transclusions
			$queryArr = [
				'tables' => [ 'templatelinks', 'page', 'categorylinks' ],
				'fields' => [ 'COUNT(page_id) AS count' ],
				'conds' => [ 'tl_from' => $id, 'tl_namespace' => $pageNamespaceId, 'cl_to' => '' ],
				'joins' => [
					'page' => [ 'LEFT JOIN', 'page_title=tl_title AND page_namespace=tl_namespace' ],
					'categorylinks' => [ 'LEFT JOIN', 'cl_from=page_id' ],
				]
			];

			$n0 = ProofreadPageDbConnector::queryCount( $queryArr, 'proofreadpage_quality0_category' );
			$n2 = ProofreadPageDbConnector::queryCount( $queryArr, 'proofreadpage_quality2_category' );
			$n3 = ProofreadPageDbConnector::queryCount( $queryArr, 'proofreadpage_quality3_category' );
			$n4 = ProofreadPageDbConnector::queryCount( $queryArr, 'proofreadpage_quality4_category' );
			// quality1 is the default value
			$n1 = $n - $n0 - $n2 - $n3 - $n4;
			$ne = 0;
		}

		if ( $n == 0 ) {
			return true;
		}

		if ( $indextitle ) {
			$nt = Title::makeTitleSafe( $indexNamespaceId, $indextitle );
			$indexlink = Linker::link( $nt, $out->msg( 'proofreadpage_source' )->text(),
						[ 'title' => $out->msg( 'proofreadpage_source_message' )->text() ] );
			$out->addJsConfigVars( 'proofreadpage_source_href', $indexlink );
			$out->addModules( 'ext.proofreadpage.article' );
		}

		$q0 = $n0 * 100 / $n;
		$q1 = $n1 * 100 / $n;
		$q2 = $n2 * 100 / $n;
		$q3 = $n3 * 100 / $n;
		$q4 = $n4 * 100 / $n;
		$qe = $ne * 100 / $n;
		$void_cell = $ne ? '<td class="qualitye" style="width:' . $qe . '%;"></td>' : '';
		$textualAlternative = wfMessage( 'proofreadpage-indexquality-alt', $n4, $n3, $n1 );
		$output = '<table class="pr_quality" title="' . $textualAlternative . '">
<tr>
<td class="quality4" style="width:' . $q4 . '%;"></td>
<td class="quality3" style="width:' . $q3 . '%;"></td>
<td class="quality2" style="width:' . $q2 . '%;"></td>
<td class="quality1" style="width:' . $q1 . '%;"></td>
<td class="quality0" style="width:' . $q0 . '%;"></td>
' . $void_cell . '
</tr>
</table>';
		$out->setSubtitle( $out->getSubtitle() . $output );
		return true;
	}

	/**
	 * Make validation of the content in the edit API
	 *
	 * @param $context Object implementing the IContextSource interface.
	 * @param $content Content of the edit box, as a Content object.
	 * @param $status  Status object to represent errors, etc.
	 * @param $summary Edit summary for page
	 * @param $user  The User object representing the user whois performing the edit.
	 * @param $minoredit  Whether the edit was marked as minor by the user.
	 * @return bool
	 */
	public static function onEditFilterMergedContent( IContextSource $context, Content $content,
		Status $status, $summary, User $user, $minoredit ) {

		// If the content's model isn't ours, ignore this; there's nothing for us to do here.
		if ( ! ( $content instanceof PageContent ) ) {
			return true;
		}

		$oldContent = $context->getWikiPage()->getContent( Revision::FOR_THIS_USER, $user );
		if ( $oldContent === null ) {
			$oldContent = ContentHandler::getForModelID( CONTENT_MODEL_PROOFREAD_PAGE )->makeEmptyContent();
		}

		// Fail if the content is invalid, or the level is being removed.
		if ( !$content->isValid() ) {
			$ourStatus = Status::newFatal( 'proofreadpage_badpagetext' );
		}

		$oldLevel = $oldContent->getLevel();
		$newLevel = $content->getLevel();

		// Fail if the user changed the level and the change isn't allowed
		if ( !$oldLevel->isChangeAllowed( $newLevel ) ) {
			$ourStatus = Status::newFatal( 'proofreadpage_notallowedtext' );
		}

		if ( isset( $ourStatus ) ) {
			$ourStatus->value = EditPage::AS_HOOK_ERROR;
			$status->merge( $ourStatus );
			return false;
		}

		return true;
	}

	/**
	 * Provides text for preload API
	 *
	 * @param string $text
	 * @param Title $title
	 * @return bool
	 */
	public static function onEditFormPreloadText( &$text, Title $title ) {
		if ( !$title->inNamespace( self::getPageNamespaceId() ) ) {
			return true;
		}

		$pageContentBuilder = new PageContentBuilder( RequestContext::getMain(), Context::getDefaultContext() );
		$content = $pageContentBuilder->buildDefaultContentForPage( new ProofreadPagePage( $title ) );
		$text = $content->serialize();

		return true;
	}

	/**
	 * Add ProofreadPage preferences to the preferences menu
	 * @param $user
	 * @param $preferences array
	 * @return bool
	 */
	public static function onGetPreferences( $user, &$preferences ) {

		// Show header and footer fields when editing in the Page namespace
		$preferences['proofreadpage-showheaders'] = [
			'type'           => 'toggle',
			'label-message'  => 'proofreadpage-preferences-showheaders-label',
			'section'        => 'editing/advancedediting',
		];

		// Use horizontal layout when editing in the Page namespace
		$preferences['proofreadpage-horizontal-layout'] = [
			'type'           => 'toggle',
			'label-message'  => 'proofreadpage-preferences-horizontal-layout-label',
			'section'        => 'editing/advancedediting',
		];

		return true;
	}

	/**
	 * Adds canonical namespaces.
	 */
	public static function addCanonicalNamespaces( &$list ) {
		$list[self::getPageNamespaceId()] = 'Page';
		$list[self::getPageNamespaceId() + 1] = 'Page_talk';
		$list[self::getIndexNamespaceId()] = 'Index';
		$list[self::getIndexNamespaceId() + 1] = 'Index_talk';
		return true;
	}

	/**
	 * @param $updater DatabaseUpdater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		global $wgContentHandlerUseDB;

		$dir = __DIR__ . '/sql/';

		$updater->addExtensionTable( 'pr_index', $dir . 'ProofreadIndex.sql' );

		// fix issue with content type hardcoded in database
		if ( isset( $wgContentHandlerUseDB ) && $wgContentHandlerUseDB ) {
			$updater->addPostDatabaseUpdateMaintenance( 'FixProofreadPagePagesContentModel' );
		}

		return true;
	}

	/**
	 * @param array $tables
	 * @return bool
	 */
	public static function onParserTestTables( array &$tables ) {
		$tables[] = 'pr_index';

		return true;
	}

	/**
	 * Add the links to previous, next, index page and scan image to Page: pages.
	 * @param SkinTemplate $skin
	 * @param array $links Structured navigation links
	 */
	public static function onSkinTemplateNavigation( SkinTemplate &$skin, array &$links ) {
		$title = $skin->getTitle();
		if ( !$title->inNamespace( self::getPageNamespaceId() ) ) {
			return true;
		}
		$page = ProofreadPagePage::newFromTitle( $title );

		// Image link
		try {
			$image = Context::getDefaultContext()->getFileProvider()->getForPagePage( $page );
			$imageUrl = null;
			if ( $image->isMultipage() ) {
				$transformAttributes = [
					'width' => $image->getWidth()
				];
				$pageNumber = $page->getPageNumber();
				if ( $pageNumber !== null ) {
					$transformAttributes['page'] = $pageNumber;
				}
				$handler = $image->getHandler();
				if ( $handler && $handler->normaliseParams( $image, $transformAttributes ) ) {
					$thumbName = $image->thumbName( $transformAttributes );
					$imageUrl = $image->getThumbUrl( $thumbName );
				}
			} else {
				// The thumb returned is invalid for not multipage pages when the width requested is the image width
				$imageUrl = $image->getViewURL();
			}

			if ( $imageUrl !== null ) {
				$links['namespaces']['proofreadPageScanLink'] = [
					'class' => '',
					'href' => $imageUrl,
					'text' => wfMessage( 'proofreadpage_image' )->plain()
				];
			}
		}
		catch ( FileNotFoundException $e ) {
	 }

		// Prev, Next and Index links
		$indexPage = $page->getIndex();
		if ( $indexPage ) {
			$pagination = Context::getDefaultContext()->getPaginationFactory()->getPaginationForIndexPage( $indexPage );
			try {
				$pageNumber = $pagination->getPageNumber( $page );

				try {
					$prevPage  = $pagination->getPage( $pageNumber - 1 );
					$prevTitle = $prevPage->getTitle();
					$links['namespaces']['proofreadPagePrevLink'] = [
						'class' => ( $skin->skinname === 'vector' ) ? 'icon' : '',
						'href' => self::getLinkUrlForTitle( $prevTitle ),
						'text' => wfMessage( 'proofreadpage_prevpage' )->plain()
					];
				}
				catch ( OutOfBoundsException $e ) {
	   } // if the previous page does not exits

				try {
					$nextPage  = $pagination->getPage( $pageNumber + 1 );
					$nextTitle = $nextPage->getTitle();
					$links['namespaces']['proofreadPageNextLink'] = [
						'class' => ( $skin->skinname === 'vector' ) ? 'icon' : '',
						'href' => self::getLinkUrlForTitle( $nextTitle ),
						'text' => wfMessage( 'proofreadpage_nextpage' )->plain()
					];
				}
				catch ( OutOfBoundsException $e ) {
	   } // if the next page does not exits
			}
			catch ( PageNotInPaginationException $e ) {
	  }

			$links['namespaces']['proofreadPageIndexLink'] = [
				'class' => ( $skin->skinname === 'vector' ) ? 'icon' : '',
				'href' => $indexPage->getTitle()->getLinkURL(),
				'text' => wfMessage( 'proofreadpage_index' )->plain()
			];
		}

		return true;
	}

	/**
	 * Add proofreading status to action=info
	 * @param IContextSource $context
	 * @param array &$pageinfo The page information
	 */
	public static function onInfoAction( IContextSource $context, array &$pageInfo ) {
		if ( !$context->canUseWikiPage() ) {
			return true;
		}
		$page = $context->getWikiPage();
		$title = $page->getTitle();
		if ( !$title->inNamespace( self::getPageNamespaceId() ) ) {
			return true;
		}
		$pageid = $page->getId();

		$params = new FauxRequest( [
			'action' => 'query',
			'prop' => 'proofread',
			'pageids' => $pageid,
		] );

		$api = new ApiMain( $params );
		$api->execute();
		$data = $api->getResult()->getResultData();

		if ( array_key_exists( 'error', $data ) ) {
			return true;
		}

		$info = $data['query']['pages'][$pageid];
		if ( array_key_exists( 'proofread', $info ) ) {
			$pageInfo['header-basic'][] = [
				wfMessage( 'proofreadpage-pageinfo-status' ),
				wfMessage( "proofreadpage_quality{$info['proofread']['quality']}_category" ),
			];
		}

		return true;
	}

	protected static function getLinkUrlForTitle( Title $title ) {
		if ( $title->exists() ) {
			return $title->getLinkURL();
		} else {
			return $title->getLinkURL( 'action=edit&redlink=1' );
		}
	}

	public static function onSkinMinervaDefaultModules( Skin $skin, array &$modules ) {
		if (
			$skin->getTitle()->inNamespace( self::getIndexNamespaceId() ) ||
			$skin->getTitle()->inNamespace( self::getPageNamespaceId() )
		) {
			unset( $modules['editor'] );
		}

		return true;
	}

	/**
	 * Extension registration callback
	 */
	public static function onRegister() {
		global $wgContentHandlers;

		// L10n
		include_once ( __DIR__ . '/ProofreadPage.namespaces.php' );

		// Content handler
		define( 'CONTENT_MODEL_PROOFREAD_PAGE', 'proofread-page' );
		$wgContentHandlers[CONTENT_MODEL_PROOFREAD_PAGE] = '\ProofreadPage\Page\PageContentHandler';
	}
}
