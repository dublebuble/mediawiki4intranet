<?php

/**
 * S5 extension for MediaWiki
 * Copyright (c) 2010+ Vitaliy Filippov <vitalif@mail.ru>
 *--
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
 * 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *--
 * Loosely based on 2005 extension by TooooOld <tianshuen@gmail.com>:
 * http://meta.wikimedia.org/wiki/User:BR/use_S5_slide_system_in_the_mediawiki/en
 */

/**
 * Extension to create slideshows
 *
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @package MediaWiki
 * @subpackage Extensions
 */

if (!defined('MEDIAWIKI'))
    die();

$dir = dirname(__FILE__);

//--- Default configuration ---//

// Default value for headingmark
// Empty = do not treat subsections as slides by default
if (!isset($egS5SlideHeadingMark))
    $egS5SlideHeadingMark = '';

// Default value for incmark
if (!isset($egS5SlideIncMark))
    $egS5SlideIncMark = '(step)';

// Filesystem path to slideshow template file
if (!isset($egS5SlideTemplateFile))
    $egS5SlideTemplateFile = $dir.'/slide.htm';

// In scaled slideshow mode, images are scaled proportionally
// with all other elements. This means you can set image size
// with [[File:xxx.jpg|150px]] - these 150px will also be relative
// to other elements. But, without the following hack, the
// images will be added to slides downsampled, and then, in
// slideshow mode, they will be probably scaled back, which leads
// to reduced quality.

// If this setting is true, hack into parser when in slideshow
// mode and output original images with HTML width/height set -
// i.e. hand off scaling to the browser - instead of outputting
// downsampled thumbnails. (default = true)
if (!isset($egS5BrowserScaleHack))
    $egS5BrowserScaleHack = true;

//--- End configuration ---//

/* Extension setup */

$wgExtensionMessagesFiles['S5SlideShow'] = $dir.'/S5SlideShow.i18n.php';
$wgHooks['UnknownAction'][] = 'S5SlideShowHooks::UnknownAction';
$wgAutoloadClasses['S5SlideShow'] = $dir.'/S5SlideShow.class.php';
$wgAutoloadClasses['S5SkinArticle'] = $dir.'/S5SlideShow.class.php';
$wgExtensionFunctions[] = 'S5SlideShowHooks::Setup';
$wgHooks['ParserFirstCallInit'][] = 'S5SlideShowHooks::ParserFirstCallInit';
$wgHooks['ArticleFromTitle'][] = 'S5SlideShowHooks::ArticleFromTitle';
$wgHooks['AlternateEdit'][] = 'S5SlideShowHooks::AlternateEdit';
$wgHooks['MagicWordwgVariableIDs'][] = 'S5SlideShowHooks::MagicWordwgVariableIDs';
$wgHooks['ParserGetVariableValueSwitch'][] = 'S5SlideShowHooks::ParserGetVariableValueSwitch';

class S5SlideShowHooks
{
    static $styles = array(
        'core.css'    => 's5-core.css',
        'base.css'    => 's5-base.css',
        'framing.css' => 's5-framing.css',
        'pretty.css'  => '$skin/pretty.css',
    );
    static $parsingSlide = false;
    // Setup parser hooks for S5
    static function ParserFirstCallInit(&$parser)
    {
        global $wgParser;
        $wgParser->setHook('slideshow', 'S5SlideShow::slideshow_view');
        $wgParser->setHook('slide', 'S5SlideShow::slideshow_legacy');
        $wgParser->setHook('slides', 'S5SlideShow::slides_view');
        $wgParser->setHook('slidecss', 'S5SlideShow::slidecss_view');
        return true;
    }
    // Setup hook for image scaling hack
    static function Setup()
    {
        global $egS5BrowserScaleHack, $wgHooks;
        if ($egS5BrowserScaleHack)
            $wgHooks['ImageBeforeProduceHTML'][] = 'S5SlideShowHooks::ImageBeforeProduceHTML';
    }
    // Hook that creates {{S5SLIDESHOW}} magic word
    static function MagicWordwgVariableIDs(&$mVariablesIDs)
    {
        wfLoadExtensionMessages('UserMagic');
        $mVariablesIDs[] = 's5slideshow';
        return true;
    }
    // Hook that evaluates {{S5SLIDESHOW}} magic word
    static function ParserGetVariableValueSwitch(&$parser, &$varCache, &$index, &$ret)
    {
        if ($index == 's5slideshow')
            $ret = empty(self::$parsingSlide) ? '' : '1';
        return true;
    }
    // Render pictures differently in slide show mode
    static function ImageBeforeProduceHTML($skin, &$title, &$file, &$frameParams, &$handlerParams, &$time, &$res)
    {
        if (empty(self::$parsingSlide) || !$file || !$file->exists() || !$handlerParams['width'])
            return true;
        $fp = &$frameParams;
        $hp = &$handlerParams;
        $center = false;
        if (isset($fp['align']) && $fp['align'] == 'center')
        {
            $center = true;
            $fp['align'] = 'none';
        }
        $thumb = $file->getUnscaledThumb( isset( $hp['page'] ) ? $hp['page'] : false );
        $thumb->height = ceil( $thumb->height * $hp['width'] / $thumb->width );
        $thumb->width = $hp['width'];
        $params = array(
            'alt' => $fp['alt'],
            'title' => $fp['title'],
        );
        if (!empty($fp['link-url']))
            $params['custom-url-link'] = $fp['link-url'];
        elseif (!empty($fp['link-title']))
            $params['custom-title-link'] = $fp['link-title'];
        elseif (!empty($fp['no-link']))
        {
        }
        else
            $params['desc-link'] = true;
        $res .= $thumb->toHtml($params);
        if (isset($fp['thumbnail']))
        {
            $outerWidth = $thumb->getWidth()+2;
            $res = "<div class=\"thumb t$fp[align]\" style='border:0'>".
                "<div class=\"thumbinner\">$res</div><div class='thumbcaption'>$fp[caption]</div></div>";
        }
        if ($fp['align'] != '')
            $res = "<div class=\"float$fp[align]\">$res</div>";
        if ($center)
            $res = "<div class=\"center\">$res</div>";
        return false;
    }
    // Hook for ?action=slide
    static function UnknownAction($action, $article)
    {
        global $wgMaxRedirects, $wgRequest;
        if ($action == 'slide')
        {
            $s5skin = trim($wgRequest->getVal('s5skin'));
            if (preg_match('/[^\w-]/', $s5skin))
                $s5skin = '';
            if ($wgRequest->getVal('s5css'))
            {
                // Get CSS for a given S5 style (from wiki-pages)
                S5SlideShow::genStyle($s5skin);
                return false;
            }
            // Check if the article is readable
            $title = $article->getTitle();
            for ($r = 0; $r < $wgMaxRedirects && $title->isRedirect(); $r++)
            {
                if (!$title->userCanRead())
                    return true;
                $title = $article->followRedirect();
                $article = new Article($title);
            }
            // Hack for CustIS live preview
            // TODO remove support for loading text from session object and
            //      replace it by support for save-staying-in-edit-mode extension
            $content = $wgRequest->getVal('wpTextbox1');
            if (!$content)
            {
                $content = $_SESSION['wpTextbox1'];
                unset($_SESSION['wpTextbox1']);
            }
            // Generate presentation HTML content
            $slideShow = new S5SlideShow($title, $content);
            $slideShow->loadContent();
            if ($s5skin)
                $slideShow->attr['style'] = $s5skin;
            $slideShow->genSlideFile();
            return false;
        }
        return true;
    }
    // Used to display CSS files on S5 skin CSS pages when they don't exist
    static function ArticleFromTitle(&$title, &$article)
    {
        if ($title->getNamespace() == NS_MEDIAWIKI &&
            preg_match('#^S5/([\w-]+)/((core|base|framing|pretty).css)$#s', $title->getText(), $m))
        {
            $file = dirname(__FILE__).'/'.str_replace('$skin', $m[1], self::$styles[$m[2]]);
            if (file_exists($file))
            {
                $article = new S5SkinArticle($title, $m[1], $file);
                return false;
            }
        }
        return true;
    }
    // Used to display CSS files on S5 skin CSS pages in edit mode
    static function AlternateEdit($editpage)
    {
        if ($editpage->mArticle instanceof S5SkinArticle &&
            !$editpage->mArticle->exists())
            $editpage->mPreloadText = $editpage->mArticle->getContent();
        return true;
    }
}
