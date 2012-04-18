<?php
/**
 * MediaWiki page data importer
 *
 * Copyright © 2003,2005 Brion Vibber <brion@pobox.com>
 * http://www.mediawiki.org/
 *
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
 * @ingroup SpecialPage
 */

class FakeUser {
	var $name = "";
	function __construct( $name ) {
		$this->name = $name;
	}
	function getId() {
		return 0;
	}
	function getName() {
		return $this->name;
	}
}

/**
 * XML file reader for the page data importer
 *
 * implements Special:Import
 * @ingroup SpecialPage
 */
class WikiImporter {
	private $reader = null;
	private $mLogItemCallback, $mUploadCallback, $mRevisionCallback, $mPageCallback;
	private $mSiteInfoCallback, $mTargetNamespace, $mPageOutCallback;
	private $mDebug;
	private $mImportUploads = true, $mImageBasePath;
	var $mArchive = null;

	/**
	 * Creates an ImportXMLReader drawing from the source provided
	 */
	function __construct( $archive ) {
		// Default callbacks
		$this->setRevisionCallback( array( $this, "importRevision" ) );
		$this->setUploadCallback( array( $this, 'importUpload' ) );
		$this->setLogItemCallback( array( $this, 'importLogItem' ) );
		$this->setPageOutCallback( array( $this, 'finishImportPage' ) );
		$this->mArchive = $archive;
		$this->reader = new XMLReader();
		$this->reader->open( $this->mArchive->getMainPart() );
	}

	private function throwXmlError( $err ) {
		$this->debug( "FAILURE: $err" );
		wfDebug( "WikiImporter XML error: $err\n" );
	}

	private function debug( $data ) {
		if( $this->mDebug ) {
			wfDebug( "IMPORT: $data\n" );
		}
	}

	private function warn( $data ) {
		wfDebug( "IMPORT: $data\n" );
	}

	private function notice( $data ) {
		global $wgCommandLineMode;
		if( $wgCommandLineMode ) {
			print "$data\n";
		} else {
			global $wgOut;
			$wgOut->addHTML( "<li>" . htmlspecialchars( $data ) . "</li>\n" );
		}
	}

	/**
	 * Set debug mode...
	 */
	function setDebug( $debug ) {
		$this->mDebug = $debug;
	}

	/**
	 * Sets the action to perform as each new page in the stream is reached.
	 * @param $callback callback
	 * @return callback
	 */
	public function setPageCallback( $callback ) {
		$previous = $this->mPageCallback;
		$this->mPageCallback = $callback;
		return $previous;
	}

	/**
	 * Sets the action to perform as each page in the stream is completed.
	 * Callback accepts the page title (as a Title object), a second object
	 * with the original title form (in case it's been overridden into a
	 * local namespace), and a count of revisions.
	 *
	 * @param $callback callback
	 * @return callback
	 */
	public function setPageOutCallback( $callback ) {
		$previous = $this->mPageOutCallback;
		$this->mPageOutCallback = $callback;
		return $previous;
	}

	/**
	 * Sets the action to perform as each page revision is reached.
	 * @param $callback callback
	 * @return callback
	 */
	public function setRevisionCallback( $callback ) {
		$previous = $this->mRevisionCallback;
		$this->mRevisionCallback = $callback;
		return $previous;
	}

	/**
	 * Sets the action to perform as each file upload version is reached.
	 * @param $callback callback
	 * @return callback
	 */
	public function setUploadCallback( $callback ) {
		$previous = $this->mUploadCallback;
		$this->mUploadCallback = $callback;
		return $previous;
	}

	/**
	 * Sets the action to perform as each log item reached.
	 * @param $callback callback
	 * @return callback
	 */
	public function setLogItemCallback( $callback ) {
		$previous = $this->mLogItemCallback;
		$this->mLogItemCallback = $callback;
		return $previous;
	}

	/**
	 * Sets the action to perform when site info is encountered
	 * @param $callback callback
	 * @return callback
	 */
	public function setSiteInfoCallback( $callback ) {
		$previous = $this->mSiteInfoCallback;
		$this->mSiteInfoCallback = $callback;
		return $previous;
	}

	/**
	 * Set a target namespace to override the defaults
	 */
	public function setTargetNamespace( $namespace ) {
		if( is_null( $namespace ) ) {
			// Don't override namespaces
			$this->mTargetNamespace = null;
		} elseif( $namespace >= 0 ) {
			// @todo FIXME: Check for validity
			$this->mTargetNamespace = intval( $namespace );
		} else {
			return false;
		}
	}
	
	/**
	 * 
	 */
	public function setImageBasePath( $dir ) {
		$this->mImageBasePath = $dir;
	}
	public function setImportUploads( $import ) {
		$this->mImportUploads = $import;
	}

	/**
	 * Default per-revision callback, performs the import.
	 * @param $revision WikiRevision
	 */
	public function importRevision( $revision ) {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->deadlockLoop( array( $revision, 'importOldRevision' ) );
	}

	/**
	 * Default per-revision callback, performs the import.
	 * @param $rev WikiRevision
	 */
	public function importLogItem( $rev ) {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->deadlockLoop( array( $rev, 'importLogItem' ) );
	}

	/**
	 * Dummy for now...
	 */
	public function importUpload( $revision ) {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->deadlockLoop( array( $revision, 'importUpload' ) );
	}

	/**
	 * Mostly for hook use
	 */
	public function finishImportPage( $title, $origTitle, $revCount, $sRevCount, $pageInfo ) {
		$args = func_get_args();
		return wfRunHooks( 'AfterImportPage', $args );
	}

	/**
	 * Alternate per-revision callback, for debugging.
	 * @param $revision WikiRevision
	 */
	public function debugRevisionHandler( &$revision ) {
		$this->debug( "Got revision:" );
		if( is_object( $revision->title ) ) {
			$this->debug( "-- Title: " . $revision->title->getPrefixedText() );
		} else {
			$this->debug( "-- Title: <invalid>" );
		}
		$this->debug( "-- User: " . $revision->user_text );
		$this->debug( "-- Timestamp: " . $revision->timestamp );
		$this->debug( "-- Comment: " . $revision->comment );
		$this->debug( "-- Text: " . $revision->text );
	}

	/**
	 * Notify the callback function when a new <page> is reached.
	 * @param $title Title
	 */
	function pageCallback( $title ) {
		if( isset( $this->mPageCallback ) ) {
			call_user_func( $this->mPageCallback, $title );
		}
	}

	/**
	 * Notify the callback function when a </page> is closed.
	 * @param $title Title
	 * @param $origTitle Title
	 * @param $revCount Integer
	 * @param $sucCount Int: number of revisions for which callback returned true
	 * @param $pageInfo Array: associative array of page information
	 */
	private function pageOutCallback( $title, $origTitle, $revCount, $sucCount, $pageInfo ) {
		if( isset( $this->mPageOutCallback ) ) {
			$args = func_get_args();
			call_user_func_array( $this->mPageOutCallback, $args );
		}
	}

	/**
	 * Notify the callback function of a revision
	 * @param $revision A WikiRevision object
	 */
	private function revisionCallback( $revision ) {
		if ( isset( $this->mRevisionCallback ) ) {
			return call_user_func_array( $this->mRevisionCallback,
					array( $revision, $this ) );
		} else {
			return false;
		}
	}

	/**
	 * Notify the callback function of a new log item
	 * @param $revision A WikiRevision object
	 */
	private function logItemCallback( $revision ) {
		if ( isset( $this->mLogItemCallback ) ) {
			return call_user_func_array( $this->mLogItemCallback,
					array( $revision, $this ) );
		} else {
			return false;
		}
	}

	/**
	 * Shouldn't something like this be built-in to XMLReader?
	 * Fetches text contents of the current element, assuming
	 * no sub-elements or such scary things.
	 * @return string
	 * @access private
	 */
	private function nodeContents() {
		if( $this->reader->isEmptyElement ) {
			return "";
		}
		$buffer = "";
		while( $this->reader->read() ) {
			switch( $this->reader->nodeType ) {
			case XmlReader::TEXT:
			case XmlReader::SIGNIFICANT_WHITESPACE:
				$buffer .= $this->reader->value;
				break;
			case XmlReader::END_ELEMENT:
				return $buffer;
			}
		}

		$this->reader->close();
		return '';
	}

	# --------------

	/** Left in for debugging */
	private function dumpElement() {
		static $lookup = null;
		if (!$lookup) {
			$xmlReaderConstants = array(
				"NONE",
				"ELEMENT",
				"ATTRIBUTE",
				"TEXT",
				"CDATA",
				"ENTITY_REF",
				"ENTITY",
				"PI",
				"COMMENT",
				"DOC",
				"DOC_TYPE",
				"DOC_FRAGMENT",
				"NOTATION",
				"WHITESPACE",
				"SIGNIFICANT_WHITESPACE",
				"END_ELEMENT",
				"END_ENTITY",
				"XML_DECLARATION",
				);
			$lookup = array();

			foreach( $xmlReaderConstants as $name ) {
				$lookup[constant("XmlReader::$name")] = $name;
			}
		}

		print( var_dump(
			$lookup[$this->reader->nodeType],
			$this->reader->name,
			$this->reader->value
		)."\n\n" );
	}

	/**
	 * Primary entry point
	 */
	public function doImport() {
		$this->reader->read();

		if ( $this->reader->name != 'mediawiki' ) {
			throw new MWException( "Expected <mediawiki> tag, got ".
				$this->reader->name );
		}
		$this->debug( "<mediawiki> tag is correct." );

		$this->debug( "Starting primary dump processing loop." );

		$keepReading = $this->reader->read();
		$skip = false;
		while ( $keepReading ) {
			$tag = $this->reader->name;
			$type = $this->reader->nodeType;

			if ( !wfRunHooks( 'ImportHandleToplevelXMLTag', $this ) ) {
				// Do nothing
			} elseif ( $tag == 'mediawiki' && $type == XmlReader::END_ELEMENT ) {
				break;
			} elseif ( $tag == 'siteinfo' ) {
				$this->handleSiteInfo();
			} elseif ( $tag == 'page' ) {
				$this->handlePage();
			} elseif ( $tag == 'logitem' ) {
				$this->handleLogItem();
			} elseif ( $tag != '#text' ) {
				$this->warn( "Unhandled top-level XML tag $tag" );

				$skip = true;
			}

			if ($skip) {
				$keepReading = $this->reader->next();
				$skip = false;
				$this->debug( "Skip" );
			} else {
				$keepReading = $this->reader->read();
			}
		}

		return true;
	}

	private function handleSiteInfo() {
		// Site info is useful, but not actually used for dump imports.
		// Includes a quick short-circuit to save performance.
		if ( ! $this->mSiteInfoCallback ) {
			$this->reader->next();
			return true;
		}
		throw new MWException( "SiteInfo tag is not yet handled, do not set mSiteInfoCallback" );
	}

	private function handleLogItem() {
		$this->debug( "Enter log item handler." );
		$logInfo = array();

		// Fields that can just be stuffed in the pageInfo object
		$normalFields = array( 'id', 'comment', 'type', 'action', 'timestamp',
					'logtitle', 'params' );

		while ( $this->reader->read() ) {
			if ( $this->reader->nodeType == XmlReader::END_ELEMENT &&
					$this->reader->name == 'logitem') {
				break;
			}

			$tag = $this->reader->name;

			if ( !wfRunHooks( 'ImportHandleLogItemXMLTag',
						$this, $logInfo ) ) {
				// Do nothing
			} elseif ( in_array( $tag, $normalFields ) ) {
				$logInfo[$tag] = $this->nodeContents();
			} elseif ( $tag == 'contributor' ) {
				$logInfo['contributor'] = $this->handleContributor();
			} elseif ( $tag != '#text' ) {
				$this->warn( "Unhandled log-item XML tag $tag" );
			}
		}

		$this->processLogItem( $logInfo );
	}

	private function processLogItem( $logInfo ) {
		$revision = new WikiRevision;

		$revision->setID( $logInfo['id'] );
		$revision->setType( $logInfo['type'] );
		$revision->setAction( $logInfo['action'] );
		$revision->setTimestamp( $logInfo['timestamp'] );
		$revision->setParams( $logInfo['params'] );
		$revision->setTitle( Title::newFromText( $logInfo['logtitle'] ) );

		if ( isset( $logInfo['comment'] ) ) {
			$revision->setComment( $logInfo['comment'] );
		}

		if ( isset( $logInfo['contributor']['ip'] ) ) {
			$revision->setUserIP( $logInfo['contributor']['ip'] );
		}
		if ( isset( $logInfo['contributor']['username'] ) ) {
			$revision->setUserName( $logInfo['contributor']['username'] );
		}

		return $this->logItemCallback( $revision );
	}

	/**
	 * Get the last non-null revision of $title for reporting "page changed locally"
	 * @param Title $title
	 */
	function lastLocalRevision( $title ) {
		$fields = Revision::selectFields();
		$fields[] = 'page_namespace';
		$fields[] = 'page_title';
		$fields[] = 'page_latest';
		$dbr = wfGetDB( DB_MASTER );
		$res = $dbr->select(
			array( 'page', 'revision' ),
			$fields,
			array( 'page_id=rev_page',
			       'page_namespace' => $title->getNamespace(),
			       'page_title'     => $title->getDBkey(),
			       'rev_parent_id'  => 0 ),
			'Revision::fetchRow',
			array( 'LIMIT' => 1,
			       'ORDER BY' => 'rev_timestamp DESC' ) );
		$row = $res->fetchObject();
		$res->free();
		if ( $row ) {
			return new Revision( $row );
		}
		return NULL;
	}

	private function handlePage() {
		// Handle page data.
		$this->debug( "Enter page handler." );
		$pageInfo = array(
			'revisionCount' => 0,
			'successfulRevisionCount' => 0,
			'lastRevision' => 0,
			'lastLocalRevision' => 0,
			'lastExistingRevision' => 0,
		);

		// Fields that can just be stuffed in the pageInfo object
		$normalFields = array( 'title', 'id', 'redirect', 'restrictions' );

		$skip = false;
		$badTitle = false;

		while ( $skip ? $this->reader->next() : $this->reader->read() ) {
			if ( $this->reader->nodeType == XmlReader::END_ELEMENT &&
					$this->reader->name == 'page') {
				break;
			}

			$tag = $this->reader->name;

			if ( $badTitle ) {
				// The title is invalid, bail out of this page
				$skip = true;
			} elseif ( !wfRunHooks( 'ImportHandlePageXMLTag', array( $this,
						&$pageInfo ) ) ) {
				// Do nothing
			} elseif ( in_array( $tag, $normalFields ) ) {
				$pageInfo[$tag] = $this->nodeContents();
				if ( $tag == 'title' ) {
					$title = $this->processTitle( $pageInfo['title'] );

					if ( !$title ) {
						$badTitle = true;
						$skip = true;
					} else {
						$pageInfo['lastLocalRevision'] = $this->lastLocalRevision( $title[0] );
						# Check edit permission
						if ( !$title[0]->userCan( 'edit' ) ) {
							global $wgUser;
							wfDebug( __METHOD__ . ": edit permission denied for [[" .
								$title[0]->getPrefixedText() . "]], user " . $wgUser->getName() );
							$skip = true;
						}
					}

					$this->pageCallback( $title );
					list( $pageInfo['_title'], $origTitle ) = $title;
				}
			} elseif ( $tag == 'revision' ) {
				$this->handleRevision( $pageInfo );
			} elseif ( $tag == 'upload' ) {
				if ( !isset( $pageInfo['fileRevisionsUploaded'] ) ) {
					$pageInfo['fileRevisionsUploaded'] = 0;
				}
				if ( $this->handleUpload( $pageInfo ) ) {
					$pageInfo['fileRevisionsUploaded']++;
				}
			} elseif ( $tag != '#text' ) {
				$this->warn( "Unhandled page XML tag $tag" );
				$skip = true;
			}
		}

		$this->pageOutCallback( $pageInfo['_title'], $origTitle,
					$pageInfo['revisionCount'],
					$pageInfo['successfulRevisionCount'],
					$pageInfo );
	}

	private function handleRevision( &$pageInfo ) {
		$this->debug( "Enter revision handler" );
		$revisionInfo = array();

		$normalFields = array( 'id', 'timestamp', 'comment', 'minor', 'text' );

		$skip = false;

		while ( $skip ? $this->reader->next() : $this->reader->read() ) {
			if ( $this->reader->nodeType == XmlReader::END_ELEMENT &&
					$this->reader->name == 'revision') {
				break;
			}

			$tag = $this->reader->name;

			if ( !wfRunHooks( 'ImportHandleRevisionXMLTag', $this,
						$pageInfo, $revisionInfo ) ) {
				// Do nothing
			} elseif ( in_array( $tag, $normalFields ) ) {
				$revisionInfo[$tag] = $this->nodeContents();
			} elseif ( $tag == 'contributor' ) {
				$revisionInfo['contributor'] = $this->handleContributor();
			} elseif ( $tag != '#text' ) {
				$this->warn( "Unhandled revision XML tag $tag" );
				$skip = true;
			}
		}

		$pageInfo['revisionCount']++;
		$ok = $this->processRevision( $pageInfo, $revisionInfo );
		if ( $ok ) {
			if ( is_object( $ok ) && !empty( $ok->_imported ) ) {
				$pageInfo['lastRevision'] = $ok;
				$pageInfo['successfulRevisionCount']++;
			} elseif ( is_object( $ok ) && ( !$pageInfo['lastExistingRevision'] ||
				$ok->getTimestamp() > $pageInfo['lastExistingRevision']->getTimestamp() ) ) {
				$pageInfo['lastExistingRevision'] = $ok;
			}
		}

	}

	private function processRevision( $pageInfo, $revisionInfo ) {
		$revision = new WikiRevision;

		if( isset( $revisionInfo['id'] ) ) {
			$revision->setID( $revisionInfo['id'] );
		}
		if ( isset( $revisionInfo['text'] ) ) {
			$revision->setText( $revisionInfo['text'] );
		}
		$revision->setTitle( $pageInfo['_title'] );

		if ( isset( $revisionInfo['timestamp'] ) ) {
			$revision->setTimestamp( $revisionInfo['timestamp'] );
		} else {
			$revision->setTimestamp( wfTimestampNow() );
		}

		if ( isset( $revisionInfo['comment'] ) ) {
			$revision->setComment( $revisionInfo['comment'] );
		}

		if ( isset( $revisionInfo['minor'] ) ) {
			$revision->setMinor( true );
		}
		if ( isset( $revisionInfo['contributor']['ip'] ) ) {
			$revision->setUserIP( $revisionInfo['contributor']['ip'] );
		}
		if ( isset( $revisionInfo['contributor']['username'] ) ) {
			$revision->setUserName( $revisionInfo['contributor']['username'] );
		}

		return $this->revisionCallback( $revision );
	}

	private function handleUpload( &$pageInfo ) {
		$this->debug( "Enter upload handler" );
		$uploadInfo = array();

		$normalFields = array( 'timestamp', 'comment', 'filename', 'text',
					'src', 'size', 'sha1base36', 'rel' );

		$skip = false;

		while ( $skip ? $this->reader->next() : $this->reader->read() ) {
			if ( $this->reader->nodeType == XmlReader::END_ELEMENT &&
					$this->reader->name == 'upload') {
				break;
			}

			$tag = $this->reader->name;

			if ( !wfRunHooks( 'ImportHandleUploadXMLTag', $this,
						$pageInfo ) ) {
				// Do nothing
			} elseif ( in_array( $tag, $normalFields ) ) {
				$uploadInfo[$tag] = $this->nodeContents();
			} elseif ( $tag == 'contributor' ) {
				$uploadInfo['contributor'] = $this->handleContributor();
			} elseif ( $tag == 'contents' ) {
				$contents = $this->nodeContents();
				$encoding = $this->reader->getAttribute( 'encoding' );
				if ( $encoding === 'base64' ) {
					$uploadInfo['fileSrc'] = $this->dumpTemp( base64_decode( $contents ) );
					$uploadInfo['isTempSrc'] = true;
				}
			} elseif ( $tag != '#text' ) {
				$this->warn( "Unhandled upload XML tag $tag" );
				$skip = true;
			}
		}
		
		if ( $this->mImageBasePath && isset( $uploadInfo['rel'] ) ) {
			$path = "{$this->mImageBasePath}/{$uploadInfo['rel']}";
			if ( file_exists( $path ) ) {
				$uploadInfo['fileSrc'] = $path;
				$uploadInfo['isTempSrc'] = false;
			}
		}

		if ( $this->mImportUploads ) {
			return $this->processUpload( $pageInfo, $uploadInfo );
		}
	}
	
	private function dumpTemp( $contents ) {
		$filename = tempnam( wfTempDir(), 'importupload' );
		file_put_contents( $filename, $contents );
		return $filename;
	}


	private function processUpload( $pageInfo, $uploadInfo ) {
		$revision = new WikiRevision;
		$text = isset( $uploadInfo['text'] ) ? $uploadInfo['text'] : '';

		$revision->setTitle( $pageInfo['_title'] );
		$revision->setID( $pageInfo['id'] );
		$revision->setTimestamp( $uploadInfo['timestamp'] );
		$revision->setText( $text );
		$revision->setFilename( $uploadInfo['filename'] );
		$path = $this->mArchive->getBinary( $uploadInfo['src'] );
		if ( $path ) {
			$revision->setFileSrc( $path, true );
		} else {
			$path = $uploadInfo['src'];
		}
		$revision->setSrc( $uploadInfo['src'] );
		if ( isset( $uploadInfo['sha1base36'] ) ) {
			$revision->setSha1Base36( trim( $uploadInfo['sha1base36'] ) );
		}
		$revision->setSize( intval( $uploadInfo['size'] ) );
		$revision->setComment( $uploadInfo['comment'] );

		if ( isset( $uploadInfo['contributor']['ip'] ) ) {
			$revision->setUserIP( $uploadInfo['contributor']['ip'] );
		}
		if ( isset( $uploadInfo['contributor']['username'] ) ) {
			$revision->setUserName( $uploadInfo['contributor']['username'] );
		}

		return call_user_func( $this->mUploadCallback, $revision );
	}

	private function handleContributor() {
		$fields = array( 'id', 'ip', 'username' );
		$info = array();

		while ( $this->reader->read() ) {
			if ( $this->reader->nodeType == XmlReader::END_ELEMENT &&
					$this->reader->name == 'contributor') {
				break;
			}

			$tag = $this->reader->name;

			if ( in_array( $tag, $fields ) ) {
				$info[$tag] = $this->nodeContents();
			}
		}

		return $info;
	}

	private function processTitle( $text ) {
		$workTitle = $text;
		$origTitle = Title::newFromText( $workTitle );

		if( !is_null( $this->mTargetNamespace ) && !is_null( $origTitle ) ) {
			$title = Title::makeTitle( $this->mTargetNamespace,
				$origTitle->getDBkey() );
		} else {
			$title = Title::newFromText( $workTitle );
		}

		if( is_null( $title ) ) {
			// Invalid page title? Ignore the page
			$this->notice( "Skipping invalid page title '$workTitle'" );
			return false;
		} elseif( $title->getInterwiki() != '' ) {
			$this->notice( "Skipping interwiki page title '$workTitle'" );
			return false;
		}

		return array( $title, $origTitle );
	}
}

class XMLReader2 extends XMLReader {
	function nodeContents() {
		if( $this->isEmptyElement ) {
			return "";
		}
		$buffer = "";
		while( $this->read() ) {
			switch( $this->nodeType ) {
			case XmlReader::TEXT:
			case XmlReader::SIGNIFICANT_WHITESPACE:
				$buffer .= $this->value;
				break;
			case XmlReader::END_ELEMENT:
				return $buffer;
			}
		}
		return $this->close();
	}
}

/**
 * @todo document (e.g. one-sentence class description).
 * @ingroup SpecialPage
 */
class WikiRevision {
	var $importer = null;
	var $title = null;
	var $id = 0;
	var $timestamp = "20010115000000";
	var $user = 0;
	var $user_text = "";
	var $text = "";
	var $comment = "";
	var $minor = false;
	var $type = "";
	var $action = "";
	var $params = "";
	var $fileSrc = '';
	var $sha1base36 = false;
	var $isTemp = false;
	protected $tempfile = NULL;

	function setTitle( $title ) {
		if( is_object( $title ) ) {
			$this->title = $title;
		} elseif( is_null( $title ) ) {
			throw new MWException( "WikiRevision given a null title in import. You may need to adjust \$wgLegalTitleChars." );
		} else {
			throw new MWException( "WikiRevision given non-object title in import." );
		}
	}

	function setID( $id ) {
		$this->id = $id;
	}

	function setTimestamp( $ts ) {
		# 2003-08-05T18:30:02Z
		$this->timestamp = wfTimestamp( TS_MW, $ts );
	}

	function setUsername( $user ) {
		$this->user_text = $user;
	}

	function setUserIP( $ip ) {
		$this->user_text = $ip;
	}

	function setText( $text ) {
		$this->text = $text;
	}

	function setComment( $text ) {
		$this->comment = $text;
	}

	function setMinor( $minor ) {
		$this->minor = (bool)$minor;
	}

	function setSrc( $src ) {
		$this->src = $src;
	}
	function setFileSrc( $src, $isTemp ) {
		$this->fileSrc = $src;
		$this->fileIsTemp = $isTemp;
	}
	function setSha1Base36( $sha1base36 ) { 
		$this->sha1base36 = $sha1base36;
	}

	function setFilename( $filename ) {
		$this->filename = $filename;
	}

	function setSize( $size ) {
		$this->size = intval( $size );
	}

	function setType( $type ) {
		$this->type = $type;
	}

	function setAction( $action ) {
		$this->action = $action;
	}

	function setParams( $params ) {
		$this->params = $params;
	}

	/**
	 * @return Title
	 */
	function getTitle() {
		return $this->title;
	}

	function getID() {
		return $this->id;
	}

	function getTimestamp() {
		return $this->timestamp;
	}

	function getUser() {
		return $this->user_text;
	}

	function getText() {
		return $this->text;
	}

	function getComment() {
		return $this->comment;
	}

	function getMinor() {
		return $this->minor;
	}

	function getSrc() {
		return $this->src;
	}
	function getSha1() {
		if ( $this->sha1base36 ) {
			return wfBaseConvert( $this->sha1base36, 36, 16, 40 );
		}
		return false;
	}
	function getSha1Base36() {
		return $this->sha1base36;
	}
	function getFileSrc() {
		return $this->fileSrc;
	}
	function isTempSrc() {
		return $this->isTemp;
	}

	function getFilename() {
		return $this->filename;
	}

	function getSize() {
		return $this->size;
	}

	function getType() {
		return $this->type;
	}

	function getAction() {
		return $this->action;
	}

	function getParams() {
		return $this->params;
	}

	function importOldRevision() {
		$dbw = wfGetDB( DB_MASTER );

		# Sneak a single revision into place
		$user = User::newFromName( $this->getUser() );
		if( $user ) {
			$userId = intval( $user->getId() );
			$userText = $user->getName();
			$userObj = $user;
		} else {
			$userId = 0;
			$userText = $this->getUser();
			$userObj = new FakeUser( $this->getUser() );
		}

		// avoid memory leak...?
		$linkCache = LinkCache::singleton();
		$linkCache->clear();

		$article = new Article( $this->title );
		$pageId = $article->getId();
		$dbTimestamp = $dbw->timestamp( $this->timestamp );
		if( $pageId == 0 ) {
			# must create the page...
			$pageId = $article->insertOn( $dbw );
			$created = true;
			$oldcountable = null;
		} else {
			$created = false;

			$prior = $dbw->selectField( 'revision', 'rev_id',
				array( 'rev_page' => $pageId,
					'rev_timestamp' => $dbTimestamp,
					'rev_user_text' => $userText,
					'rev_comment'   => $this->getComment() ),
				__METHOD__
			);
			if( $prior ) {
				$prior = Revision::newFromId( $prior );
				// @todo FIXME: This could fail slightly for multiple matches :P
				wfDebug( __METHOD__ . ": skipping existing revision for [[" .
					$this->title->getPrefixedText() . "]], timestamp " . $this->timestamp . "\n" );
				return $prior;
			}
			$oldcountable = $article->isCountable();
		}

		# @todo FIXME: Use original rev_id optionally (better for backups)
		# Insert the row
		$revision = new Revision( array(
			'page'       => $pageId,
			'text'       => $this->getText(),
			'comment'    => $this->getComment(),
			'user'       => $userId,
			'user_text'  => $userText,
			'timestamp'  => $this->timestamp,
			'minor_edit' => $this->minor,
			) );
		$revId = $revision->insertOn( $dbw );
		$changed = $article->updateIfNewerOn( $dbw, $revision );

		# Restore edit/create recent changes entry
		global $wgUseRCPatrol, $wgUseNPPatrol, $wgUser;
		# Mark as patrolled if importing user can do so
		$patrolled = ( $wgUseRCPatrol || $wgUseNPPatrol ) && $this->title->userCan( 'autopatrol' );
		$prevRev = $dbw->selectRow( 'revision', '*',
			array( 'rev_page' => $pageId, "rev_timestamp < $dbTimestamp" ), __METHOD__,
			array( 'LIMIT' => '1', 'ORDER BY' => 'rev_timestamp DESC' ) );
		if ( $prevRev ) {
			$rc = RecentChange::notifyEdit( $this->timestamp, $this->title, $this->minor,
				$userObj, $this->getComment(), $prevRev->rev_id, $prevRev->rev_timestamp, $wgUser->isAllowed( 'bot' ),
				'', $prevRev->rev_len, strlen( $this->getText() ), $revId, $patrolled );
		} else {
			$rc = RecentChange::notifyNew( $this->timestamp, $this->title, $this->minor,
				$userObj, $this->getComment(), $wgUser->isAllowed( 'bot' ), '',
				strlen( $this->getText() ), $revId, $patrolled );
			if ( !$created ) {
				# If we are importing the first revision, but the page already exists,
				# that means there was another first revision. Mark it as non-first,
				# so that import does not depend on revision sequence.
				$dbw->update( 'recentchanges',
					array( 'rc_type' => RC_EDIT ),
					array(
						'rc_namespace' => $this->title->getNamespace(),
						'rc_title' => $this->title->getDBkey(),
						'rc_type' => RC_NEW,
					),
					__METHOD__ );
			}
		}
		# Log auto-patrolled edits
		if ( $patrolled ) {
			PatrolLog::record( $rc, true );
		}

		if ( $changed !== false ) {
			wfDebug( __METHOD__ . ": running updates\n" );
			$article->doEditUpdates( $revision, $wgUser, array( 'created' => $created, 'oldcountable' => $oldcountable ) );
		}

		# A hack. TOdo it better?
		$revision->_imported = true;
		return $revision;
	}

	function importLogItem() {
		$dbw = wfGetDB( DB_MASTER );
		# @todo FIXME: This will not record autoblocks
		if( !$this->getTitle() ) {
			wfDebug( __METHOD__ . ": skipping invalid {$this->type}/{$this->action} log time, timestamp " .
				$this->timestamp . "\n" );
			return;
		}
		# Check if it exists already
		// @todo FIXME: Use original log ID (better for backups)
		$prior = $dbw->selectField( 'logging', '1',
			array( 'log_type' => $this->getType(),
				'log_action'    => $this->getAction(),
				'log_timestamp' => $dbw->timestamp( $this->timestamp ),
				'log_namespace' => $this->getTitle()->getNamespace(),
				'log_title'     => $this->getTitle()->getDBkey(),
				'log_comment'   => $this->getComment(),
				#'log_user_text' => $this->user_text,
				'log_params'    => $this->params ),
			__METHOD__
		);
		// @todo FIXME: This could fail slightly for multiple matches :P
		if( $prior ) {
			wfDebug( __METHOD__ . ": skipping existing item for Log:{$this->type}/{$this->action}, timestamp " .
				$this->timestamp . "\n" );
			return false;
		}
		$log_id = $dbw->nextSequenceValue( 'logging_log_id_seq' );
		$data = array(
			'log_id' => $log_id,
			'log_type' => $this->type,
			'log_action' => $this->action,
			'log_timestamp' => $dbw->timestamp( $this->timestamp ),
			'log_user' => User::idFromName( $this->user_text ),
			'log_user_text' => $this->user_text,
			'log_namespace' => $this->getTitle()->getNamespace(),
			'log_title' => $this->getTitle()->getDBkey(),
			'log_comment' => $this->getComment(),
			'log_params' => $this->params
		);
		$dbw->insert( 'logging', $data, __METHOD__ );
	}

	function importUpload() {
		# Construct a file
		$file = wfLocalFile( $this->getTitle() );
		$archiveName = false;

		if ( $file->exists() && $file->getTimestamp() > $this->getTimestamp() ) {
			$archiveName = 'T' . $this->getTimestamp() . '!' . $file->getPhys();
			$file = OldLocalFile::newFromArchiveName( $this->getTitle(),
				RepoGroup::singleton()->getLocalRepo(), $archiveName );
			wfDebug( __METHOD__ . ": Importing archived file as $archiveName\n" );
		} else {
			wfDebug( __METHOD__ . ': Importing new file as ' . $file->getName() . "\n" );
		}

		# Check if file already exists
		if ( $file->exists() ) {
			# Backwards-compatibility: support export files without sha1
			if ( $this->getSha1Base36() && $file->getSha1() == $this->getSha1Base36() ||
				!$this->getSha1Base36() && $file->getTimestamp() == $this->getTimestamp() ) {
				wfDebug( __METHOD__ . ": File already exists and is equal to imported (".$this->getTimestamp().").\n" );
				return false;
			}
		}

		if( !$file ) {
			wfDebug( __METHOD__ . ': Bad file for ' . $this->getTitle() . "\n" );
			return false;
		}
		
		# Get the file source or download if necessary
		$source = $this->getFileSrc();
		$flags = $this->isTempSrc() ? File::DELETE_SOURCE : 0;
		if ( !$source ) {
			$source = $this->downloadSource();
			$flags |= File::DELETE_SOURCE;
		}
		if( !$source ) {
			wfDebug( __METHOD__ . ": Could not fetch remote file.\n" );
			return false;
		}
		$sha1 = $this->getSha1();
		if ( $sha1 && ( $sha1 !== sha1_file( $source ) ) ) {
			if ( $flags & File::DELETE_SOURCE ) {
				# Broken file; delete it if it is a temporary file
				unlink( $source );
			}
			wfDebug( __METHOD__ . ": Corrupt file $source.\n" );
			return false;
		}

		$user = User::newFromName( $this->user_text );
		if( !$user ) {
			$user = new FakeUser( $this->user_text );
		}
		
		# Do the actual upload
		if ( $archiveName ) {
			$status = $file->uploadOld( $source, $archiveName, 
				$this->getTimestamp(), $this->getComment(), $user, $flags );
		} else {
			$status = $file->upload( $source, $this->getComment(), $this->getComment(), 
				$flags, false, $this->getTimestamp(), $user );
		}
		
		if ( $status->isGood() ) {
			wfDebug( __METHOD__ . ": Succesful\n" );
			return true;
		} else {
			wfDebug( __METHOD__ . ': failed: ' . $status->getXml() . "\n" );
			return false;
		}
	}

	function downloadSource() {
		global $wgEnableUploads;
		if( !$wgEnableUploads ) {
			return false;
		}

		$this->tempfile = tempnam( wfTempDir(), 'download' );
		$f = fopen( $this->tempfile, 'wb' );
		if( !$f ) {
			wfDebug( "IMPORT: couldn't write to temp file $this->tempfile\n" );
			return false;
		}

		// @todo FIXME!
		$src = $this->getSrc();
		$data = Http::get( $src );
		if( !$data ) {
			wfDebug( "IMPORT: couldn't fetch source $src\n" );
			fclose( $f );
			unlink( $this->tempfile );
			return false;
		}

		fwrite( $f, $data );
		fclose( $f );

		return $this->tempfile;
	}

}
