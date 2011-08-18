<?php
/**
 * MediaWiki page data importer
 * Copyright (C) 2003,2005 Brion Vibber <brion@pobox.com>
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

/**
 *
 * @ingroup SpecialPage
 */
class WikiRevision {
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
	var $tempfile = NULL;

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

	function setFilename( $filename ) {
		$this->filename = $filename;
	}

	function setSha1( $sha1 ) {
		$this->sha1 = trim( $sha1 );
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

	function getFilename() {
		return $this->filename;
	}

	function getSha1() {
		return $this->sha1;
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
		# Check edit permission
		if( !$this->getTitle()->userCan('edit') )
		{
			global $wgUser;
			wfDebug( __METHOD__ . ": edit permission denied for [[" . $this->title->getPrefixedText() . "]], user " . $wgUser->getName() );
			return false;
		}

		$dbw = wfGetDB( DB_MASTER );

		# Sneak a single revision into place
		$user = User::newFromName( $this->getUser() );
		if( $user ) {
			$userId = intval( $user->getId() );
			$userText = $user->getName();
		} else {
			$userId = 0;
			$userText = $this->getUser();
		}

		// avoid memory leak...?
		$linkCache = LinkCache::singleton();
		$linkCache->clear();

		$article = new Article( $this->title );
		$pageId = $article->getId();
		if( $pageId == 0 ) {
			# must create the page...
			$pageId = $article->insertOn( $dbw );
			$created = true;
		} else {
			$created = false;

			$prior = $dbw->selectField( 'revision', 'rev_id',
				array( 'rev_page' => $pageId,
					'rev_timestamp' => $dbw->timestamp( $this->timestamp ),
					'rev_user_text' => $userText,
					'rev_comment'   => $this->getComment() ),
				__METHOD__
			);
			if( $prior ) {
				$prior = Revision::newFromId( $prior );
				// FIXME: this could fail slightly for multiple matches :P
				wfDebug( __METHOD__ . ": skipping existing revision for [[" .
					$this->title->getPrefixedText() . "]], timestamp " . $this->timestamp . "\n" );
				return $prior;
			}
		}

		# FIXME: Use original rev_id optionally (better for backups)
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
		
		# To be on the safe side...
		$tempTitle = $GLOBALS['wgTitle'];
		$GLOBALS['wgTitle'] = $this->title;

		if( $created ) {
			wfDebug( __METHOD__ . ": running onArticleCreate\n" );
			Article::onArticleCreate( $this->title );

			wfDebug( __METHOD__ . ": running create updates\n" );
			$article->createUpdates( $revision );

		} elseif( $changed ) {
			wfDebug( __METHOD__ . ": running onArticleEdit\n" );
			Article::onArticleEdit( $this->title );

			wfDebug( __METHOD__ . ": running edit updates\n" );
			$article->editUpdates(
				$this->getText(),
				$this->getComment(),
				$this->minor,
				$this->timestamp,
				$revId );
		}
		$GLOBALS['wgTitle'] = $tempTitle;

		# A hack. TOdo it better?
		$revision->_imported = true;
		return $revision;
	}
	
	function importLogItem() {
		$dbw = wfGetDB( DB_MASTER );
		# FIXME: this will not record autoblocks
		if( !$this->getTitle() ) {
			wfDebug( __METHOD__ . ": skipping invalid {$this->type}/{$this->action} log time, timestamp " . 
				$this->timestamp . "\n" );
			return;
		}
		# Check edit permission
		if( !$this->getTitle()->userCan('edit') )
		{
			global $wgUser;
			wfDebug( __METHOD__ . ": edit permission denied for [[" . $this->title->getPrefixedText() . "]], user " . $wgUser->getName() );
			return false;
		}
		# Check if it exists already
		// FIXME: use original log ID (better for backups)
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
		// FIXME: this could fail slightly for multiple matches :P
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
			#'log_user_text' => $this->user_text,
			'log_namespace' => $this->getTitle()->getNamespace(),
			'log_title' => $this->getTitle()->getDBkey(),
			'log_comment' => $this->getComment(),
			'log_params' => $this->params
		);
		$dbw->insert( 'logging', $data, __METHOD__ );
	}

	function importUpload()
	{
		# Check edit permission
		if( !$this->getTitle()->userCan('edit') )
		{
			global $wgUser;
			wfDebug( __METHOD__ . ": edit permission denied for [[" . $this->title->getPrefixedText() . "]], user " . $wgUser->getName() );
			return false;
		}

		// @todo Fixme: upload() uses $wgUser, which is wrong here
		// it may also create a page without our desire, also wrong potentially.
		// and, it will record a *current* upload, but we might want an archive version here

		$file = wfLocalFile( $this->getTitle() );
		if( !$file ) {
			var_dump( $file );
			wfDebug( "IMPORT: Bad file. :(\n" );
			return false;
		}

		/* First check if file already exists */
		if ($file->exists())
		{
			/* Backward-compatibility: support export files without sha1 */
			if ($this->getSha1() && $file->getSha1() == $this->getSha1() ||
				!$this->getSha1() && $file->getTimestamp() == $this->getTimestamp())
			{
				wfDebug( "IMPORT: File already exists and is equal to imported (".$this->getTimestamp().").\n" );
				return false;
			}
			$history = $file->getHistory(null, $this->getTimestamp(), $this->getTimestamp());
			foreach ($history as $oldfile)
			{
				if (!$this->getSha1() || $oldfile->getSha1() == $this->getSha1())
				{
					wfDebug( "IMPORT: File revision already exists at its timestamp (".$this->getTimestamp().") and is equal to imported.\n" );
					return false;
				}
			}
		}

		/* Get file source into a temporary file */
		$source = $this->downloadSource();
		if( !$source ) {
			wfDebug( "IMPORT: Could not fetch remote file. :(\n" );
			return false;
		}

		// @fixme upload() uses $wgUser, which is wrong here
		// it may also create a page without our desire, also wrong potentially.

		if ($file->exists() && $file->getTimestamp() > $this->getTimestamp())
		{
			/* Upload an *archive* version */
			wfDebug( "Importing an archive $arch version of file (".$this->getTimestamp().")\n" );
			$status = $file->uploadIntoArchive( $source,
				$this->getComment(),
				$this->getComment(), // Initial page, if none present...
				File::DELETE_SOURCE,
				false, // props...
				$this->getTimestamp() );
		}
		else
		{
			wfDebug( "Importing a new current version of file (".$this->getTimestamp().")\n" );
			/* Upload a *current* version */
			$status = $file->upload( $source,
				$this->getComment(),
				$this->getComment(), // Initial page, if none present...
				File::DELETE_SOURCE,
				false, // props...
				$this->getTimestamp() );
		}

		if( $status->isGood() ) {
			// yay?
			wfDebug( "IMPORT: file imported OK\n" );
			return true;
		}

		wfDebug( "IMPORT: file import FAILED: " . $status->getXml() . "\n" );
		return false;

	}

	function downloadSource() {
		global $wgEnableUploads;
		if( !$wgEnableUploads ) {
			return false;
		}

		$src = $this->getSrc();
		if (!$src)
			return false;
		/* Если файл прикреплён как multipart-часть, вернём его */
		if (is_file( $src ))
			return $src;

		/* Иначе нужно заморочиться и скачать... */
		$this->tempfile = tempnam( wfTempDir(), 'download' );
		$f = fopen( $this->tempfile, 'wb' );
		if( !$f ) {
			wfDebug( "IMPORT: couldn't write to temp file ".$this->tempfile."\n" );
			return false;
		}

		// @todo Fixme!
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

	function __destruct()
	{
		if ( $this->tempfile && is_file( $this->tempfile ) )
			unlink( $this->tempfile );
	}
}

/**
 * implements Special:Import
 * @ingroup SpecialPage
 */
class WikiImporter {
	var $mDebug = false;
	var $mSource = null;
	var $mPageCallback = null;
	var $mPageOutCallback = null;
	var $mRevisionCallback = null;
	var $mLogItemCallback = null;
	var $mUploadCallback = null;
	var $mTargetNamespace = null;
	var $mXmlNamespace = false;
	var $lastfield;
	var $tagStack = array();

	function __construct( $source ) {
		$this->setRevisionCallback( array( $this, "importRevision" ) );
		$this->setUploadCallback( array( $this, "importUpload" ) );
		$this->setPageCallback( array( $this, "beginPage" ) );
		$this->setLogItemCallback( array( $this, "importLogItem" ) );
		$this->mSource = $source;
	}

	function throwXmlError( $err ) {
		$this->debug( "FAILURE: $err" );
		wfDebug( "WikiImporter XML error: $err\n" );
	}

	function handleXmlNamespace ( $parser, $data, $prefix=false, $uri=false ) {
		if( preg_match( '/www.mediawiki.org/',$prefix ) ) {
			$prefix = str_replace( '/','\/',$prefix );
			$this->mXmlNamespace='/^'.$prefix.':/';
		 }
	}

	function stripXmlNamespace($name) {
		if( $this->mXmlNamespace ) {
			return(preg_replace($this->mXmlNamespace,'',$name,1));
		}
		else {
			return($name);
		}
	}

	# --------------

	function doImport() {
		if( empty( $this->mSource ) ) {
			return new WikiErrorMsg( "importnotext" );
		}

		$parser = xml_parser_create_ns( "UTF-8" );

		# case folding violates XML standard, turn it off
		xml_parser_set_option( $parser, XML_OPTION_CASE_FOLDING, false );

		xml_set_object( $parser, $this );
		xml_set_element_handler( $parser, "in_start", "" );
		xml_set_start_namespace_decl_handler( $parser, "handleXmlNamespace" );

		$offset = 0; // for context extraction on error reporting
		do {
			$chunk = $this->mSource->readChunk();
			if( !xml_parse( $parser, $chunk, $this->mSource->atEnd() ) ) {
				wfDebug( "WikiImporter::doImport encountered XML parsing error\n" );
				return new WikiXmlError( $parser, wfMsgHtml( 'import-parse-failure' ), $chunk, $offset );
			}
			$offset += strlen( $chunk );
		} while( $chunk !== false && !$this->mSource->atEnd() );
		xml_parser_free( $parser );

		return true;
	}

	function debug( $data ) {
		if( $this->mDebug ) {
			wfDebug( "IMPORT: $data\n" );
		}
	}

	function notice( $data ) {
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
	function setPageCallback( $callback ) {
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
	function setPageOutCallback( $callback ) {
		$previous = $this->mPageOutCallback;
		$this->mPageOutCallback = $callback;
		return $previous;
	}

	/**
	 * Sets the action to perform as each page revision is reached.
	 * @param $callback callback
	 * @return callback
	 */
	function setRevisionCallback( $callback ) {
		$previous = $this->mRevisionCallback;
		$this->mRevisionCallback = $callback;
		return $previous;
	}

	/**
	 * Sets the action to perform as each file upload version is reached.
	 * @param $callback callback
	 * @return callback
	 */
	function setUploadCallback( $callback ) {
		$previous = $this->mUploadCallback;
		$this->mUploadCallback = $callback;
		return $previous;
	}
	
	/**
	 * Sets the action to perform as each log item reached.
	 * @param $callback callback
	 * @return callback
	 */
	function setLogItemCallback( $callback ) {
		$previous = $this->mLogItemCallback;
		$this->mLogItemCallback = $callback;
		return $previous;
	}

	/**
	 * Set a target namespace to override the defaults
	 */
	function setTargetNamespace( $namespace ) {
		if( is_null( $namespace ) ) {
			// Don't override namespaces
			$this->mTargetNamespace = null;
		} elseif( $namespace >= 0 ) {
			// FIXME: Check for validity
			$this->mTargetNamespace = intval( $namespace );
		} else {
			return false;
		}
	}

	/**
	 * Default per-revision callback, performs the import.
	 * @param $revision WikiRevision
	 * @private
	 */
	function importRevision( $revision ) {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->deadlockLoop( array( $revision, 'importOldRevision' ) );
	}
	
	/**
	 * Default per-revision callback, performs the import.
	 * @param $rev WikiRevision
	 * @private
	 */
	function importLogItem( $rev ) {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->deadlockLoop( array( $rev, 'importLogItem' ) );
	}

	/**
	 * Per-revision file import callback, performs the upload.
	 * @param $revision WikiRevision
	 * @private
	 */
	function importUpload( $revision ) {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->deadlockLoop( array( $revision, 'importUpload' ) );
	}

	/**
	 * Alternate per-revision callback, for debugging.
	 * @param $revision WikiRevision
	 * @private
	 */
	function debugRevisionHandler( &$revision ) {
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
	 * @private
	 */
	function pageCallback( $title ) {
		if( is_callable( $this->mPageCallback ) ) {
			call_user_func( $this->mPageCallback, $title );
		}
	}

	/**
	 * Notify the callback function when a </page> is closed.
	 * @param $title Title
	 * @param $origTitle Title
	 * @param $revisionCount int
	 * @param $successCount Int: number of revisions for which callback returned true
	 * @param $lastExistingRevision Revision
	 * @param $lastLocalRevision Revision
	 * @param $lastRevision Revision
	 * @private
	 */
	function pageOutCallback() {
		if( is_callable( $this->mPageOutCallback ) ) {
			$args = func_get_args();
			call_user_func_array( $this->mPageOutCallback, $args );
		}
	}

	# XML parser callbacks from here out -- beware!
	function donothing( $parser, $x, $y="" ) {
		#$this->debug( "donothing" );
	}

	function in_start( $parser, $name, $attribs ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "in_start $name" );
		if( $name != "mediawiki" ) {
			return $this->throwXMLerror( "Expected <mediawiki>, got <$name>" );
		}
		xml_set_element_handler( $parser, "in_mediawiki", "out_mediawiki" );
	}

	function in_mediawiki( $parser, $name, $attribs ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "in_mediawiki $name" );
		if( $name == 'siteinfo' ) {
			xml_set_element_handler( $parser, "in_siteinfo", "out_siteinfo" );
		} elseif( $name == 'page' ) {
			$this->push( $name );
			$this->workRevisionCount = 0;
			$this->workSuccessCount = 0;
			$this->uploadCount = 0;
			$this->uploadSuccessCount = 0;
			$this->lastRevision = NULL;
			$this->lastLocalRevision = NULL;
			$this->lastExistingRevision = NULL;
			xml_set_element_handler( $parser, "in_page", "out_page" );
		} elseif( $name == 'logitem' ) {
			$this->push( $name );
			$this->workRevision = new WikiRevision;
			xml_set_element_handler( $parser, "in_logitem", "out_logitem" );
		} else {
			return $this->throwXMLerror( "Expected <page>, got <$name>" );
		}
	}
	function out_mediawiki( $parser, $name ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "out_mediawiki $name" );
		if( $name != "mediawiki" ) {
			return $this->throwXMLerror( "Expected </mediawiki>, got </$name>" );
		}
		xml_set_element_handler( $parser, "donothing", "donothing" );
	}


	function in_siteinfo( $parser, $name, $attribs ) {
		// no-ops for now
		$name = $this->stripXmlNamespace($name);
		$this->debug( "in_siteinfo $name" );
		switch( $name ) {
		case "sitename":
		case "base":
		case "generator":
		case "case":
		case "namespaces":
		case "namespace":
			break;
		default:
			return $this->throwXMLerror( "Element <$name> not allowed in <siteinfo>." );
		}
	}

	function out_siteinfo( $parser, $name ) {
		$name = $this->stripXmlNamespace($name);
		if( $name == "siteinfo" ) {
			xml_set_element_handler( $parser, "in_mediawiki", "out_mediawiki" );
		}
	}

	function beginPage( $title )
	{
		$fields = Revision::selectFields();
		$fields[] = 'page_namespace';
		$fields[] = 'page_title';
		$fields[] = 'page_latest';
		$dbr = wfGetDB( DB_MASTER );
		$res = $dbr->select(
			array( 'page', 'revision' ),
			$fields,
			array( 'page_id=rev_page',
			       'page_namespace' => $this->pageTitle->getNamespace(),
			       'page_title'     => $this->pageTitle->getDBkey(),
			       'rev_len IS NOT NULL' ),
			'Revision::fetchRow',
			array( 'LIMIT' => 1,
			       'ORDER BY' => 'rev_timestamp DESC' ) );
		$row = $res->fetchObject();
		$res->free();
		if ($row)
			$this->lastLocalRevision = new Revision( $row );
	}

	function in_page( $parser, $name, $attribs ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "in_page $name" );
		switch( $name ) {
		case "id":
		case "title":
		case "redirect":
		case "restrictions":
			$this->appendfield = $name;
			$this->appenddata = "";
			xml_set_element_handler( $parser, "in_nothing", "out_append" );
			xml_set_character_data_handler( $parser, "char_append" );
			break;
		case "revision":
			$this->push( "revision" );
			if( is_object( $this->pageTitle ) ) {
				$this->workRevision = new WikiRevision;
				$this->workRevision->setTitle( $this->pageTitle );
				$this->workRevisionCount++;
			} else {
				// Skipping items due to invalid page title
				$this->workRevision = null;
			}
			xml_set_element_handler( $parser, "in_revision", "out_revision" );
			break;
		case "upload":
			$this->push( "upload" );
			if( is_object( $this->pageTitle ) ) {
				$this->workRevision = new WikiRevision;
				$this->workRevision->setTitle( $this->pageTitle );
				$this->uploadCount++;
			} else {
				// Skipping items due to invalid page title
				$this->workRevision = null;
			}
			xml_set_element_handler( $parser, "in_upload", "out_upload" );
			break;
		default:
			return $this->throwXMLerror( "Element <$name> not allowed in a <page>." );
		}
	}

	function out_page( $parser, $name ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "out_page $name" );
		$this->pop();
		if( $name != "page" ) {
			return $this->throwXMLerror( "Expected </page>, got </$name>" );
		}
		xml_set_element_handler( $parser, "in_mediawiki", "out_mediawiki" );

		$this->pageOutCallback( $this->pageTitle, $this->origTitle,
			$this->workRevisionCount, $this->workSuccessCount,
			$this->lastExistingRevision, $this->lastLocalRevision,
			$this->lastRevision );

		$this->workTitle = null;
		$this->workRevision = null;
		$this->workRevisionCount = 0;
		$this->workSuccessCount = 0;
		$this->pageTitle = null;
		$this->origTitle = null;
	}

	function in_nothing( $parser, $name, $attribs ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "in_nothing $name" );
		return $this->throwXMLerror( "No child elements allowed here; got <$name>" );
	}

	function char_append( $parser, $data ) {
		$this->debug( "char_append '$data'" );
		$this->appenddata .= $data;
	}

	function out_append( $parser, $name ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "out_append $name" );
		if( $name != $this->appendfield ) {
			return $this->throwXMLerror( "Expected </{$this->appendfield}>, got </$name>" );
		}

		switch( $this->appendfield ) {
		case "title":
			$this->workTitle = $this->appenddata;
			$this->origTitle = Title::newFromText( $this->workTitle );
			if( !is_null( $this->mTargetNamespace ) && !is_null( $this->origTitle ) ) {
				$this->pageTitle = Title::makeTitle( $this->mTargetNamespace,
					$this->origTitle->getDBkey() );
			} else {
				$this->pageTitle = Title::newFromText( $this->workTitle );
			}
			if( is_null( $this->pageTitle ) ) {
				// Invalid page title? Ignore the page
				$this->notice( "Skipping invalid page title '$this->workTitle'" );
			} elseif( $this->pageTitle->getInterwiki() != '' ) {
				$this->notice( "Skipping interwiki page title '$this->workTitle'" );
				$this->pageTitle = null;
			} else {
				$this->pageCallback( $this->workTitle );
			}
			break;
		case "id":
			if ( $this->parentTag() == 'revision' || $this->parentTag() == 'logitem' ) {
				if( $this->workRevision )
					$this->workRevision->setID( $this->appenddata );
			}
			break;
		case "text":
			if( $this->workRevision )
				$this->workRevision->setText( $this->appenddata );
			break;
		case "username":
			if( $this->workRevision )
				$this->workRevision->setUsername( $this->appenddata );
			break;
		case "ip":
			if( $this->workRevision )
				$this->workRevision->setUserIP( $this->appenddata );
			break;
		case "timestamp":
			if( $this->workRevision )
				$this->workRevision->setTimestamp( $this->appenddata );
			break;
		case "comment":
			if( $this->workRevision )
				$this->workRevision->setComment( $this->appenddata );
			break;
		case "type":
			if( $this->workRevision )
				$this->workRevision->setType( $this->appenddata );
			break;
		case "action":
			if( $this->workRevision )
				$this->workRevision->setAction( $this->appenddata );
			break;
		case "logtitle":
			if( $this->workRevision )
				$this->workRevision->setTitle( Title::newFromText( $this->appenddata ) );
			break;
		case "params":
			if( $this->workRevision )
				$this->workRevision->setParams( $this->appenddata );
			break;
		case "minor":
			if( $this->workRevision )
				$this->workRevision->setMinor( true );
			break;
		case "filename":
			if( $this->workRevision )
				$this->workRevision->setFilename( $this->appenddata );
			break;
		case "src":
			if( $this->workRevision )
			{
				/* Передаём путь к файлу, если он уже загружен */
				if ( substr( $this->appenddata, 0, 12 ) == 'multipart://' )
				{
					if ( $p = $this->mSource->parts[ substr( $this->appenddata, 12 ) ] )
						$this->workRevision->setSrc( $p['tempfile'] );
				}
				/* Иначе передаём URL */
				else
					$this->workRevision->setSrc( $this->appenddata );
			}
			break;
		case "size":
			if( $this->workRevision )
				$this->workRevision->setSize( intval( $this->appenddata ) );
			break;
		default:
			$this->debug( "Bad append: {$this->appendfield}" );
		}
		$this->appendfield = "";
		$this->appenddata = "";

		$parent = $this->parentTag();
		xml_set_element_handler( $parser, "in_$parent", "out_$parent" );
		xml_set_character_data_handler( $parser, "donothing" );
	}

	function in_revision( $parser, $name, $attribs ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "in_revision $name" );
		switch( $name ) {
		case "id":
		case "timestamp":
		case "comment":
		case "minor":
		case "text":
			$this->appendfield = $name;
			xml_set_element_handler( $parser, "in_nothing", "out_append" );
			xml_set_character_data_handler( $parser, "char_append" );
			break;
		case "contributor":
			$this->push( "contributor" );
			xml_set_element_handler( $parser, "in_contributor", "out_contributor" );
			break;
		default:
			return $this->throwXMLerror( "Element <$name> not allowed in a <revision>." );
		}
	}

	function out_revision( $parser, $name ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "out_revision $name" );
		$this->pop();
		if( $name != "revision" ) {
			return $this->throwXMLerror( "Expected </revision>, got </$name>" );
		}
		xml_set_element_handler( $parser, "in_page", "out_page" );

		if( $this->workRevision ) {
			$ok = call_user_func_array( $this->mRevisionCallback,
				array( $this->workRevision, $this ) );
			if( is_object($ok) && $ok->_imported ) {
				$this->lastRevision = $ok;
				$this->workSuccessCount++;
			} else if ( is_object($ok) && ( !$this->lastExistingRevision ||
				$ok->getTimestamp() > $this->lastExistingRevision->getTimestamp() ) )
				$this->lastExistingRevision = $ok;
		}
	}

	function in_logitem( $parser, $name, $attribs ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "in_logitem $name" );
		switch( $name ) {
		case "id":
		case "timestamp":
		case "comment":
		case "type":
		case "action":
		case "logtitle":
		case "params":
			$this->appendfield = $name;
			xml_set_element_handler( $parser, "in_nothing", "out_append" );
			xml_set_character_data_handler( $parser, "char_append" );
			break;
		case "contributor":
			$this->push( "contributor" );
			xml_set_element_handler( $parser, "in_contributor", "out_contributor" );
			break;
		default:
			return $this->throwXMLerror( "Element <$name> not allowed in a <revision>." );
		}
	}

	function out_logitem( $parser, $name ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "out_logitem $name" );
		$this->pop();
		if( $name != "logitem" ) {
			return $this->throwXMLerror( "Expected </logitem>, got </$name>" );
		}
		xml_set_element_handler( $parser, "in_mediawiki", "out_mediawiki" );

		if( $this->workRevision ) {
			$ok = call_user_func_array( $this->mLogItemCallback,
				array( $this->workRevision, $this ) );
			if( $ok ) {
				$this->workSuccessCount++;
			}
		}
	}

	function in_upload( $parser, $name, $attribs ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "in_upload $name" );
		switch( $name ) {
		case "timestamp":
		case "comment":
		case "text":
		case "filename":
		case "src":
			if ($this->workRevision && $attribs['sha1'])
				$this->workRevision->setSha1( $attribs['sha1'] );
		case "size":
			$this->appendfield = $name;
			xml_set_element_handler( $parser, "in_nothing", "out_append" );
			xml_set_character_data_handler( $parser, "char_append" );
			break;
		case "contributor":
			$this->push( "contributor" );
			xml_set_element_handler( $parser, "in_contributor", "out_contributor" );
			break;
		default:
			return $this->throwXMLerror( "Element <$name> not allowed in an <upload>." );
		}
	}

	function out_upload( $parser, $name ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "out_revision $name" );
		$this->pop();
		if( $name != "upload" ) {
			return $this->throwXMLerror( "Expected </upload>, got </$name>" );
		}
		xml_set_element_handler( $parser, "in_page", "out_page" );

		if( $this->workRevision ) {
			$ok = call_user_func_array( $this->mUploadCallback,
				array( $this->workRevision, $this ) );
			if( $ok ) {
				$this->workUploadSuccessCount++;
			}
		}
	}

	function in_contributor( $parser, $name, $attribs ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "in_contributor $name" );
		switch( $name ) {
		case "username":
		case "ip":
		case "id":
			$this->appendfield = $name;
			xml_set_element_handler( $parser, "in_nothing", "out_append" );
			xml_set_character_data_handler( $parser, "char_append" );
			break;
		default:
			$this->throwXMLerror( "Invalid tag <$name> in <contributor>" );
		}
	}

	function out_contributor( $parser, $name ) {
		$name = $this->stripXmlNamespace($name);
		$this->debug( "out_contributor $name" );
		$this->pop();
		if( $name != "contributor" ) {
			return $this->throwXMLerror( "Expected </contributor>, got </$name>" );
		}
		$parent = $this->parentTag();
		xml_set_element_handler( $parser, "in_$parent", "out_$parent" );
	}

	private function push( $name ) {
		array_push( $this->tagStack, $name );
		$this->debug( "PUSH $name" );
	}

	private function pop() {
		$name = array_pop( $this->tagStack );
		$this->debug( "POP $name" );
		return $name;
	}

	private function parentTag() {
		$name = $this->tagStack[count( $this->tagStack ) - 1];
		$this->debug( "PARENT $name" );
		return $name;
	}

}

/**
 * @todo document (e.g. one-sentence class description).
 * @ingroup SpecialPage
 */
class ImportStringSource {
	function __construct( $string ) {
		$this->mString = $string;
		$this->mRead = false;
	}

	function atEnd() {
		return $this->mRead;
	}

	function readChunk() {
		if( $this->atEnd() ) {
			return false;
		} else {
			$this->mRead = true;
			return $this->mString;
		}
	}

	function nextPart() {
		return false;
	}
}

/**
 * @todo document (e.g. one-sentence class description).
 * @ingroup SpecialPage
 */
class ImportStreamSource {

	var $buf;
	var $eop;
	var $boundary;

	const BUF_SIZE = 65536;

	function __construct( $handle )
	{
		$this->mHandle = $handle;
		$this->eop = false;
		$this->buf = '';
		$this->boundary = '';
		$pos = ftell($this->mHandle);
		$s = fgets($this->mHandle);
		/* multipart-файл? */
		if (preg_match("/Content-Type:\s*multipart\/related; boundary=([^\r\n]+)\r*\n/s", $s, $m))
		{
			$this->boundary = $m[1];
			$this->parts = array();
			/* Распаковываем файл на части.
			 * Смысл в том, что процедура импорта загруженных файлов
			 * должна видеть части. Но они идут после XML-файла в multipart
			 * документе. Точнее, в принципе, в произвольном месте.
			 */
			while (!feof($this->mHandle))
			{
				$s = trim(fgets($this->mHandle));
				if ($s != $this->boundary)
					break;
				$part = array();
				/* Читаем заголовки */
				while ($s != "\n" && $s != "\r\n")
				{
					$s = fgets($this->mHandle);
					if (preg_match('/([a-z0-9\-\_]+):\s*(.*?)\s*$/is', $s, $m))
						$part[str_replace('-','_',strtolower($m[1]))] = $m[2];
				}
				/* Читаем данные */
				$tempfile = tempnam(wfTempDir(), "imp");
				$tempfp = fopen($tempfile, "wb");
				if (is_numeric($part['content_length']))
				{
					$done = 0;
					$buf = true;
					while ($done < $part['content_length'] && $buf)
					{
						$buf = fread($this->mHandle, min(self::BUF_SIZE, $part['content_length'] - $done));
						if ($tempfp)
							fwrite($tempfp, $buf);
						$done += strlen($buf);
					}
				}
				else
				{
					$buf = true;
					while ($buf)
					{
						$buf = fread($this->mHandle, self::BUF_SIZE);
						if (($p = strpos($buf, "\n".$this->boundary)) !== false)
						{
							$pp = ftell($this->mHandle);
							fseek($this->mHandle, $p+1-strlen($buf), 1);
							fwrite($tempfp, substr($buf, 0, $p+1));
							break;
						}
						else
						{
							/* Для ситуации, когда $this->boundary попадёт на границу буфера */
							if (strlen($buf) == self::BUF_SIZE &&
								($p = strrpos($buf, "\n")) !== false)
							{
								fseek($this->mHandle, $p+1-self::BUF_SIZE, 1);
								$buf = substr($buf, 0, $p+1);
							}
							fwrite($tempfp, $buf);
						}
					}
				}
				fclose($tempfp);
				/* Запоминаем часть */
				$part['tempfile'] = $tempfile;
				if ($part['content_id'])
				{
					$part['sha1'] = File::sha1Base36($part['tempfile']);
					$this->parts[$part['content_id']] = $part;
				}
				else
					unlink($tempfile);
			}
			/* Открываем XML-часть */
			if ($this->parts['Revisions'])
			{
				fclose($this->mHandle);
				$this->mHandle = fopen($this->parts['Revisions']['tempfile'], 'rb');
			}
		}
		/* Обычный XML-файл (не multipart) */
		else
			fseek($this->mHandle, $pos, 0);
	}

	/* Деструктор. Уничтожает временные файлы. */
	function __destruct()
	{
		wfSuppressWarnings();
		if ($this->mHandle)
			fclose ($this->mHandle);
		if ($this->parts)
			foreach ($this->parts as $part)
				unlink ($part['tempfile']);
		wfRestoreWarnings();
	}

	function atEnd() {
		return feof( $this->mHandle );
	}

	/* read next XML part chunk */
	function readChunk() {
		return fread( $this->mHandle, self::BUF_SIZE );
	}

	static function newFromFile( $filename ) {
		$file = @fopen( $filename, 'rb' );
		if( !$file ) {
			return new WikiErrorMsg( "importcantopen" );
		}
		return new ImportStreamSource( $file );
	}

	static function newFromUpload( $fieldname = "xmlimport" ) {
		$upload =& $_FILES[$fieldname];

		if( !isset( $upload ) || !$upload['name'] ) {
			return new WikiErrorMsg( 'importnofile' );
		}
		if( !empty( $upload['error'] ) ) {
			switch($upload['error']){
				case 1: # The uploaded file exceeds the upload_max_filesize directive in php.ini.
					return new WikiErrorMsg( 'importuploaderrorsize' );
				case 2: # The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.
					return new WikiErrorMsg( 'importuploaderrorsize' );
				case 3: # The uploaded file was only partially uploaded
					return new WikiErrorMsg( 'importuploaderrorpartial' );
				case 6: #Missing a temporary folder. Introduced in PHP 4.3.10 and PHP 5.0.3.
					return new WikiErrorMsg( 'importuploaderrortemp' );
				# case else: # Currently impossible
			}

		}
		$fname = $upload['tmp_name'];
		if( is_uploaded_file( $fname ) ) {
			return ImportStreamSource::newFromFile( $fname );
		} else {
			return new WikiErrorMsg( 'importnofile' );
		}
	}

	static function newFromURL( $url, $method = 'GET' ) {
		wfDebug( __METHOD__ . ": opening $url\n" );
		# Use the standard HTTP fetch function; it times out
		# quicker and sorts out user-agent problems which might
		# otherwise prevent importing from large sites, such
		# as the Wikimedia cluster, etc.
		$data = Http::request( $method, $url );
		if( $data !== false ) {
			$file = tmpfile();
			fwrite( $file, $data );
			fflush( $file );
			fseek( $file, 0 );
			return new ImportStreamSource( $file );
		} else {
			return new WikiErrorMsg( 'importcantopen' );
		}
	}

	public static function newFromInterwiki( $interwiki, $page, $history = false, $templates = false, $pageLinkDepth = 0 ) {
		if( $page == '' ) {
			return new WikiErrorMsg( 'import-noarticle' );
		}
		$link = Title::newFromText( "$interwiki:Special:Export/$page" );
		if( is_null( $link ) || $link->getInterwiki() == '' ) {
			return new WikiErrorMsg( 'importbadinterwiki' );
		} else {
			$params = array();
			if ( $history ) $params['history'] = 1;
			if ( $templates ) $params['templates'] = 1;
			if ( $pageLinkDepth ) $params['pagelink-depth'] = $pageLinkDepth;
			$url = $link->getFullUrl( $params );
			# For interwikis, use POST to avoid redirects.
			return ImportStreamSource::newFromURL( $url, "POST" );
		}
	}
}
