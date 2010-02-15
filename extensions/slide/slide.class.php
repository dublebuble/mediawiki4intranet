<?php

# Copyright (C) 2010 Vitaliy Filippov <vitalif@mail.ru>
# Based on (C) 2005 TooooOld <tianshuen@gmail.com>
# http://meta.wikimedia.org/wiki/User:BR/use_S5_slide_system_in_the_mediawiki/en
#
# This program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License along
# with this program; if not, write to the Free Software Foundation, Inc.,
# 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
# http://www.gnu.org/copyleft/gpl.html

/**
 * Extension to create slide show
 *
 * @author TooooOld <tianshuen@gmail.com>
 * @package MediaWiki
 * @subpackage Extensions
 */

if (!defined('MEDIAWIKI'))
    die();

class S5SlideShow
{
    var $sTitle, $sArticle;
    var $mSlides;
    var $style, $title, $subtitle, $author, $footer, $subfooter, $headingmark, $incmark, $pagebreak;

    /* Constructor. If $attr is not an array(), attributes will be taken from article content. */
    function __construct($sTitle, $attr = NULL)
    {
        if (!is_object($sTitle))
        {
            wfDebug(__CLASS__.": Error! Pass a title object, NOT a title string!\n");
            return false;
        }
        $this->sArticle = new Article($sTitle);
        $this->sTitle = $sTitle;
        if (!is_array($attr))
        {
            /* get attributes from article content using a hook */
            global $wgUser;
            $parser = new Parser;
            $parser->setHook('slide', array($this, 'slide_for_args'));
            $parser->parse($this->sArticle->getContent(), $this->sTitle, ParserOptions::newFromUser($wgUser));
        }
        else
            $this->setAttributes($attr);
        $this->loadContent($this->sArticle->getContent());
    }

    /* Parse attribute hash and save them into $this */
    function setAttributes($attr)
    {
        global $wgContLang, $egS5SlideHeadingMark, $egS5SlideIncMark, $egS5SlidePageBreak;
        /* Get attributes from tag content */
        if (preg_match_all('/(?:^|\n)\s*;\s*([^:\s]*)\s*:\s*([^\n]*)/is', $attr['content'], $m, PREG_SET_ORDER) > 0)
            foreach ($m as $set)
                $attr[$set[1]] = $set[2];
        /* Default slide show title = page title */
        $attr['title'] = trim($attr['title']);
        if (!$attr['title'])
            $attr['title'] = $this->sTitle->getText();
        /* No subtitle by default */
        /* Default author = last revision's author */
        if (!array_key_exists('author', $attr))
        {
            $u = $this->sArticle->getLastNAuthors(1);
            $u = $u[0];
            if (!is_object($u))
                $u = User::newFromName($u);
            $attr['author'] = $u->getRealName();
        }
        /* Slide show title in the footer by default */
        if (!array_key_exists('footer', $attr))
            $attr['footer'] = $attr['title'];
        /* Author and date in the subfooter by default */
        if (!array_key_exists('subfooter', $attr))
            $attr['subfooter'] = $attr['author'] . ', ' . $wgContLang->timeanddate($this->sArticle->getTimestamp(), true);
        else
            $attr['subfooter'] = str_ireplace('{{date}}', $wgContLang->timeanddate($this->sArticle->getTimestamp(), true), $attr['subfooter']);
        /* Default heading mark = $egS5SlideHeadingMark */
        $attr['headingmark'] = trim($attr['headingmark']);
        if (!$attr['headingmark'])
            $attr['headingmark'] = $egS5SlideHeadingMark;
        /* Default incremental mark = $egS5SlideIncMark */
        $attr['incmark'] = trim($attr['incmark']);
        if (!$attr['incmark'])
            $attr['incmark'] = $egS5SlideIncMark;
        /* Default page break sequence = $egS5SlidePageBreak */
        $attr['pagebreak'] = trim($attr['pagebreak']);
        if (!$attr['pagebreak'])
            $attr['pagebreak'] = $egS5SlidePageBreak;
        /* Default style = "default" */
        $attr['style'] = trim($attr['style']);
        if (!$attr['style'])
            $attr['style'] = 'default';
        /* Extract values into $this */
        foreach(split(' ', 'style title subtitle author footer subfooter headingmark incmark pagebreak') as $v)
            $this->$v = $attr[$v];
    }

    /* Split $content to slides and save them into $this->mSlides */
    function loadContent($content)
    {
        $secs = preg_split('/(^==[^=].*?==)(?!\S)/mi', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $secCount = count($secs);
        $this->mSlides = array();
        for ($i = 1; $i < $secCount; $i += 2)
        {
            $title = preg_replace('/^==\s*(.*?)\s*==$/is', '\1', $secs[$i]);
            /* check for heading mark */
            if ($this->headingmark && !self::strCheck($title, $this->headingmark))
                continue;
            $inc = false;
            if ($this->incmark && self::strCheck($title, $this->incmark))
                $inc = true;
            $this->mSlides[] = array(
                'title'   => $title,
                'content' => $secs[$i+1],
                'inc'     => $inc,
            );
        }
        return true;
    }

    function genSlideFile()
    {
        global $egS5SlideTemplateFile;
        global $wgUser, $wgContLang, $wgOut, $wgParser;

        if (empty($this->mSlides))
            return false;

        # get template
        $ce_slide_tpl = @file_get_contents($egS5SlideTemplateFile);
        if (!$ce_slide_tpl)
            return false;

        # generate content
        $fc = '';

        $options =& ParserOptions::newFromUser($wgUser);
        $fileParser = clone $wgParser;
        $fileParser->setHook('slide', 'S5SlideShow::fakeSlide');

        foreach ($this->mSlides as $slide)
        {
            if ($this->pagebreak)
                $ms = preg_split('/'.preg_quote($this->pagebreak).'$/mi', $slide['content']);
            else
                $ms = array(0 => $slide);
            $sc = count($ms);
            foreach ($ms as $i => $ss)
            {
                $title = $slide['title'];
                $output =& $fileParser->parse($ss."\n__NOTOC__\n__NOEDITSECTION__", $this->sTitle, $options);
                $slideContent = $output->getText();
                /* make slide lists incremental if needed */
                if ($slide['inc'])
                {
                    $slideContent = str_replace('<ul>', '<ul class="incremental">', $slideContent);
                    $slideContent = str_replace('<ol>', '<ol class="incremental">', $slideContent);
                }
                if ($sc > 1)
                    $title .= " (".($i+1)."/$sc)";
                $title = $fileParser->parse($title, $this->sTitle, $options, false, false);
                $fc .= "<div class=\"slide\"><h1>$title</h1><div class=\"slidecontent\">$slideContent</div></div>\n";
            }
        }

        # parse strings
        $p = array();
        foreach(split(' ', 'title subtitle author footer subfooter') as $v)
            $p[$v] = $fileParser->parse($this->$v, $this->sTitle, $options, false)->getText();

        # substitute values
        $search = array('[style]', '[content]', '[title]', '[subtitle]', '[author]', '[footer]', '[subfooter]');
        $replace = array($this->style, $fc, $p['title'], $p['subtitle'], $p['author'], $p['footer'], $p['subfooter'], $p['style']);
        $fileContent = str_replace($search, $replace, $ce_slide_tpl);
        $fileContent = $wgContLang->Convert($fileContent);

        # output content
        $wgOut->disable();
        header("Content-Type: text/html; charset=utf-8");
        echo $fileContent;
    }

    /* Hook function to extract arguments from article content */
    function slide_for_args($content, $attr, $parser)
    {
        $attr['content'] = $content;
        $this->setAttributes($attr);
        return '';
    }

    /* Normal parser hook for <slide> */
    static function slide($content, $attr, $parser)
    {
        global $wgScriptPath, $wgParser;
        if (!($title = $parser->getTitle()))
        {
            wfDebug(__CLASS__.': no title object in parser');
            return '';
        }
        /* Create slide show object */
        $attr['content'] = $content;
        $slideShow = new S5SlideShow($title, $attr);
        $url = $title->escapeLocalURL("action=slide");
        return "<div class=\"floatright\"><span>
<a href=\"$url\" class=\"image\" title=\"Slide Show\" target=\"_blank\">
<img src=\"$wgScriptPath/extensions/slide/".$slideShow->style."/preview.png\" alt=\"Slide Show\" width=\"240px\" /><br />
Slide Show</a></span></div>" . $wgParser->parse($content, $title, $wgParser->mOptions, false, false)->getText();
    }

    /* Empty parser hook for <slide> */
    static function fakeSlide() { return ''; }

    /* Check whether $haystack begins or ends with $needle, and if yes,
       remove $needle from it and return true. */
    static function strCheck(&$haystack, $needle)
    {
        $needle = mb_strtolower($needle);
        if (mb_strtolower(mb_substr($haystack, 0, mb_strlen($needle))) == $needle)
            $haystack = trim(mb_substr($haystack, mb_strlen($needle)));
        else if (mb_strtolower(mb_substr($haystack, -mb_strlen($needle))) == $needle)
            $haystack = trim(mb_substr($haystack, 0, -mb_strlen($needle)));
        else
            return false;
        return true;
    }
}
