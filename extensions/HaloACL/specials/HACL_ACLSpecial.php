<?php

/* Copyright 2010+, Vitaliy Filippov <vitalif[d.o.g]mail.ru>
 *                  Stas Fomin <stas.fomin[d.o.g]yandex.ru>
 * This file is part of heavily modified "Web 1.0" HaloACL-extension.
 * http://wiki.4intra.net/Mediawiki4Intranet
 * $Id: $
 *
 * Copyright 2009, ontoprise GmbH
 *
 * The HaloACL-Extension is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * The HaloACL-Extension is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * A special page for defining and managing HaloACL objects
 *
 * @author Vitaliy Filippov
 */

if (!defined('MEDIAWIKI'))
    die();

class HaloACLSpecial extends SpecialPage
{
    static $actions = array(
        'acllist'     => 1,
        'acl'         => 1,
        'quickaccess' => 1,
        'grouplist'   => 1,
        'group'       => 1,
        'whitelist'   => 1,
    );

    var $aclTargetTypes = array(
        'protect' => array('page' => 1, 'namespace' => 1, 'category' => 1, 'property' => 1),
        'define' => array('right' => 1, 'template' => 1),
    );

    /* Identical to Xml::element, but does no htmlspecialchars() on $contents */
    static function xelement($element, $attribs = null, $contents = '', $allowShortTag = true)
    {
        if (is_null($contents))
            return Xml::openElement($element, $attribs);
        elseif ($contents == '')
            return Xml::element($element, $attribs, $contents, $allowShortTag);
        return Xml::openElement($element, $attribs) . $contents . Xml::closeElement($element);
    }

    /* Constructor of HaloACL special page class */
    public function __construct()
    {
        if (!defined('SMW_NS_PROPERTY'))
        {
            $this->hasProp = false;
            unset($this->aclTargetTypes['protect']['property']);
        }
        parent::__construct('HaloACL');
    }

    /* Entry point */
    public function execute()
    {
        global $wgOut, $wgRequest, $wgUser, $haclgHaloScriptPath;
        haclCheckScriptPath();
        $q = $wgRequest->getValues();
        if ($wgUser->isLoggedIn())
        {
            wfLoadExtensionMessages('HaloACL');
            $wgOut->setPageTitle(wfMsg('hacl_special_page'));
            if (!self::$actions[$q['action']])
                $q['action'] = 'acllist';
            $f = 'html_'.$q['action'];
            $wgOut->addLink(array(
                'rel' => 'stylesheet',
                'type' => 'text/css',
                'media' => 'screen, projection',
                'href' => $haclgHaloScriptPath.'/skins/haloacl.css',
            ));
            if ($f == 'html_acllist')
                $wgOut->addHTML('<p style="margin-top: -8px">'.wfMsgExt('hacl_acllist_hello', 'parseinline').'</p>');
            $this->_actions($q);
            $this->$f($q);
        }
        else
            $wgOut->showErrorPage('hacl_login_first_title', 'hacl_login_first_text');
    }

    /* View list of all ACL definitions, filtered and loaded using AJAX */
    public function html_acllist(&$q)
    {
        global $wgOut, $wgUser, $wgScript, $haclgHaloScriptPath, $haclgContLang;
        ob_start();
        require(dirname(__FILE__).'/HACL_ACLList.tpl.php');
        $html = ob_get_contents();
        ob_end_clean();
        $wgOut->setPageTitle(wfMsg('hacl_acllist'));
        $wgOut->addHTML($html);
    }

    /* Create/edit ACL definition using interactive editor */
    public function html_acl(&$q)
    {
        global $wgOut, $wgUser, $wgScript, $haclgHaloScriptPath, $haclgContLang, $wgContLang;
        $predefinedRightsExist = HACLStorage::getDatabase()->getSDForPE(0, 'right');
        if (!($q['sd'] &&
            ($aclTitle = Title::newFromText($q['sd'], HACL_NS_ACL)) &&
            ($t = HACLEvaluator::hacl_type($aclTitle)) &&
            ($t == 'sd' || $t == 'right') &&
            ($aclArticle = new Article($aclTitle)) &&
            $aclArticle->exists()))
        {
            $aclTitle = NULL;
            $aclArticle = NULL;
        }
        else
        {
            $aclSDName = $aclTitle->getText();
            list($aclPEName, $aclPEType) = HACLSecurityDescriptor::nameOfPE($aclSDName);
        }
        ob_start();
        require(dirname(__FILE__).'/HACL_ACLEditor.tpl.php');
        $html = ob_get_contents();
        ob_end_clean();
        $wgOut->setPageTitle($aclTitle ? wfMsg('hacl_acl_edit', $aclTitle->getText()) : wfMsg('hacl_acl_create'));
        $wgOut->addHTML($html);
    }

    /* Manage Quick Access ACL list */
    public function html_quickaccess(&$args)
    {
        global $wgOut, $wgUser, $wgScript, $haclgHaloScriptPath, $wgRequest;
        /* Handle save */
        $args = $wgRequest->getValues();
        if ($args['save'])
        {
            $ids = array();
            foreach ($args as $k => $v)
                if (substr($k, 0, 3) == 'qa_')
                    $ids[] = substr($k, 3);
            HACLStorage::getDatabase()->saveQuickAcl($wgUser->getId(), $ids);
            wfGetDB(DB_MASTER)->commit();
            header("Location: $wgScript?title=Special:HaloACL&action=quickaccess&like=".urlencode($args['like']));
            exit;
        }
        /* Load data */
        $templates = HACLStorage::getDatabase()->getSDs2('right', $args['like']);
        if ($aclOwnTemplate = HACLStorage::getDatabase()->getSDForPE($wgUser->getId(), 'template'))
        {
            $aclOwnTemplate = HACLSecurityDescriptor::newFromId($aclOwnTemplate);
            $aclOwnTemplate->owntemplate = true;
            array_unshift($templates, $aclOwnTemplate);
        }
        $quickacl = HACLQuickacl::newForUserId($wgUser->getId());
        $quickacl_ids = array_flip($quickacl->getSD_IDs());
        foreach ($templates as $sd)
        {
            $sd->selected = array_key_exists($sd->getSDId(), $quickacl_ids);
            $sd->editlink = $wgScript.'?title=Special:HaloACL&action=acl&sd='.urlencode($sd->getSDName());
            $sd->viewlink = Title::newFromText($sd->getSDName(), HACL_NS_ACL)->getLocalUrl();
        }
        /* Run template */
        ob_start();
        require(dirname(__FILE__).'/HACL_QuickACL.tpl.php');
        $html = ob_get_contents();
        ob_end_clean();
        $wgOut->setPageTitle(wfMsg('hacl_quickaccess_manage'));
        $wgOut->addHTML($html);
    }

    /* Add header with available actions */
    public function _actions(&$q)
    {
        global $wgScript, $wgOut, $wgUser;
        $ownt = HACLStorage::getDatabase()->getSDForPE($wgUser->getId(), 'template');
        if ($ownt)
            $ownt = Title::newFromId($ownt);
        $act = $q['action'];
        if ($act == 'acl' && $q['sd'])
        {
            if ($ownt && Title::newFromText($q['sd'], HACL_NS_ACL)->getArticleId() == $ownt->getArticleId())
                $act = 'owntemplate';
            else
                $act = 'acledit';
        }
        elseif ($act == 'group' && $q['group'])
            $act = 'groupedit';
        $html = array();
        foreach (array('acllist', 'acl', 'owntemplate', 'quickaccess', 'grouplist', 'group', 'whitelist') as $action)
        {
            $a = '<b>'.wfMsg("hacl_action_$action").'</b>';
            if ($act != $action)
            {
                $url = "$wgScript?title=Special:HaloACL&action=$action";
                if ($action == 'owntemplate')
                {
                    if ($ownt)
                        $url = "$wgScript?title=Special:HaloACL&action=acl&sd=".$ownt->getText();
                    else
                        continue;
                }
                $a = '<a href="'.htmlspecialchars($url).'">'.$a.'</a>';
            }
            $html[] = $a;
        }
        $html = '<p>'.implode(' &nbsp; &nbsp; ', $html).'</p>';
        $wgOut->addHTML($html);
    }

    /* Manage groups */
    public function html_grouplist(&$q)
    {
        global $wgOut, $wgUser, $wgScript, $haclgHaloScriptPath, $haclgContLang;
        ob_start();
        require(dirname(__FILE__).'/HACL_GroupList.tpl.php');
        $html = ob_get_contents();
        ob_end_clean();
        $wgOut->setPageTitle(wfMsg('hacl_grouplist'));
        $wgOut->addHTML($html);
    }

    /* Create or edit a group */
    public function html_group(&$q)
    {
        global $wgOut, $wgUser, $wgScript, $haclgHaloScriptPath, $wgContLang, $haclgContLang;
        if (!($q['group'] &&
            ($grpTitle = Title::newFromText($q['group'], HACL_NS_ACL)) &&
            HACLEvaluator::hacl_type($grpTitle) == 'group' &&
            ($grpArticle = new Article($grpTitle)) &&
            $grpArticle->exists()))
        {
            $grpTitle = NULL;
            $grpArticle = NULL;
        }
        else
            list($grpPrefix, $grpName) = explode('/', $grpTitle->getText(), 2);
        /* Run template */
        ob_start();
        require(dirname(__FILE__).'/HACL_GroupEditor.tpl.php');
        $html = ob_get_contents();
        ob_end_clean();
        $wgOut->setPageTitle($grpTitle ? wfMsg('hacl_grp_editing', $grpTitle->getText()) : wfMsg('hacl_grp_creating'));
        $wgOut->addHTML($html);
    }

    /* Manage page whitelist */
    public function html_whitelist(&$q)
    {
        global $wgOut;
        
    }

    /* Recursively get rights of SD by name or ID */
    static function getRights($sdnameorid)
    {
        if (!$sdnameorid)
            return array();
        if (!is_numeric($sdnameorid))
        {
            if ($t = Title::newFromText($sdnameorid, HACL_NS_ACL))
                $sdid = $t->getArticleId();
        }
        else
            $sdid = $sdnameorid;
        if (!$sdid)
            return array();
        $st = HACLStorage::getDatabase();
        $res = array();
        /* Inline rights */
        $rights = $st->getInlineRightsOfSDs($sdid, true);
        foreach ($rights as $r)
        {
            /* get action names */
            $actmask = $r->getActions();
            $actions = array();
            if ($actmask & HACLLanguage::RIGHT_READ)
                $actions[] = 'read';
            if ($actmask & HACLLanguage::RIGHT_EDIT)
                $actions[] = 'edit';
            if ($actmask & HACLLanguage::RIGHT_CREATE)
                $actions[] = 'create';
            if ($actmask & HACLLanguage::RIGHT_MOVE)
                $actions[] = 'move';
            if ($actmask & HACLLanguage::RIGHT_DELETE)
                $actions[] = 'delete';
            $members = array();
            /* get user names */
            foreach ($st->getUserNames($r->getUsers()) as $u)
                $members[] = 'User:'.$u['user_name'];
            /* get group names */
            foreach ($st->getGroupNames($r->getGroups()) as $g)
                $members[] = 'Group/'.$g['group_name'];
            /* merge into result */
            foreach ($members as $m)
                foreach ($actions as $a)
                    $res[$m][$a] = true;
        }
        /* Predefined rights */
        $predef = $st->getPredefinedRightsOfSD($sdid, false);
        foreach ($predef as $id)
        {
            $sub = self::getRights($id);
            foreach ($sub as $m => $acts)
                foreach ($acts as $a => $true)
                    $res[$m][$a] = true;
        }
        return $res;
    }

    /* "Real" ACL list, loaded using AJAX */
    static function haclAcllist($t, $n, $limit = 101)
    {
        global $wgScript, $wgTitle, $haclgHaloScriptPath, $wgUser;
        haclCheckScriptPath();
        /* Load data */
        $t = $t ? explode(',', $t) : NULL;
        $sds = HACLStorage::getDatabase()->getSDs2($t, $n, $limit);
        if (count($sds) == $limit)
        {
            array_pop($sds);
            $max = true;
        }
        $lists = array();
        foreach ($sds as $sd)
        {
            $d = array(
                'name' => $sd->getSDName(),
                'real' => $sd->getSDName(),
                'editlink' => $wgScript.'?title=Special:HaloACL&action=acl&sd='.urlencode($sd->getSDName()),
                'viewlink' => Title::newFromText($sd->getSDName(), HACL_NS_ACL)->getLocalUrl(),
            );
            if ($p = strpos($d['real'], '/'))
            {
                $d['real'] = substr($d['real'], $p+1);
                if ($sd->getPEType() == 'template' && $d['real'] == $wgUser->getName())
                    $d['real'] = wfMsg('hacl_acllist_own_template', $d['real']);
            }
            $lists[$sd->getPEType()][] = $d;
        }
        /* Run template */
        ob_start();
        require(dirname(__FILE__).'/HACL_ACLListContents.tpl.php');
        $html = ob_get_contents();
        ob_end_clean();
        return $html;
    }

    /* "Real" group list, loaded using AJAX */
    static function haclGrouplist($n, $limit = 101)
    {
        global $wgScript, $haclgHaloScriptPath;
        haclCheckScriptPath();
        /* Load data */
        $groups = HACLStorage::getDatabase()->getGroups($n, $limit);
        if (count($groups) == $limit)
        {
            array_pop($groups);
            $max = true;
        }
        foreach ($groups as &$g)
        {
            $g = array(
                'name' => $g->getGroupName(),
                'real' => $g->getGroupName(),
                'editlink' => $wgScript.'?title=Special:HaloACL&action=group&group='.urlencode($g->getGroupName()),
                'viewlink' => Title::newFromText($g->getGroupName(), HACL_NS_ACL)->getLocalUrl(),
            );
            if ($p = strpos($g['real'], '/'))
                $g['real'] = substr($g['real'], $p+1);
        }
        /* Run template */
        ob_start();
        require(dirname(__FILE__).'/HACL_GroupListContents.tpl.php');
        $html = ob_get_contents();
        ob_end_clean();
        return $html;
    }
}
