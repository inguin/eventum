<?php
/* vim: set expandtab tabstop=4 shiftwidth=4: */
// +----------------------------------------------------------------------+
// | Eventum - Issue Tracking System                                      |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003, 2004 MySQL AB                                    |
// |                                                                      |
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License as published by |
// | the Free Software Foundation; either version 2 of the License, or    |
// | (at your option) any later version.                                  |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to:                           |
// |                                                                      |
// | Free Software Foundation, Inc.                                       |
// | 59 Temple Place - Suite 330                                          |
// | Boston, MA 02111-1307, USA.                                          |
// +----------------------------------------------------------------------+
// | Authors: Jo�o Prado Maia <jpm@mysql.com>                             |
// +----------------------------------------------------------------------+
//
// @(#) $Id$
//
include_once("../config.inc.php");
include_once(APP_INC_PATH . "db_access.php");
include_once(APP_INC_PATH . "class.template.php");
include_once(APP_INC_PATH . "class.auth.php");
include_once(APP_INC_PATH . "class.project.php");
include_once(APP_INC_PATH . "class.faq.php");
include_once(APP_INC_PATH . "class.customer.php");

$tpl = new Template_API();
$tpl->setTemplate("manage/index.tpl.html");

Auth::checkAuthentication(APP_COOKIE);

$tpl->assign("type", "faq");

$role_id = User::getRoleByUser(Auth::getUserID());
if (($role_id == User::getRoleID('administrator')) || ($role_id == User::getRoleID('manager'))) {
    if ($role_id == User::getRoleID('administrator')) {
        $tpl->assign("show_setup_links", true);
    }

    if (@$HTTP_POST_VARS["cat"] == "new") {
        $tpl->assign("result", FAQ::insert());
    } elseif (@$HTTP_POST_VARS["cat"] == "update") {
        $tpl->assign("result", FAQ::update());
    } elseif (@$HTTP_POST_VARS["cat"] == "delete") {
        FAQ::remove();
    } elseif (!empty($HTTP_GET_VARS['prj_id'])) {
        $tpl->assign("info", array('faq_prj_id' => $HTTP_GET_VARS['prj_id']));
        $backend_uses_support_levels = Customer::doesBackendUseSupportLevels($HTTP_GET_VARS['prj_id']);
        $tpl->assign("backend_uses_support_levels", $backend_uses_support_levels);
        if ($backend_uses_support_levels) {
            $tpl->assign("support_levels", Customer::getSupportLevelAssocList($HTTP_GET_VARS['prj_id']));
        }
    }

    if (@$HTTP_GET_VARS["cat"] == "edit") {
        $info = FAQ::getDetails($HTTP_GET_VARS["id"]);
        if (!empty($HTTP_GET_VARS['prj_id'])) {
            $info['faq_prj_id'] = $HTTP_GET_VARS['prj_id'];
        }
        $backend_uses_support_levels = Customer::doesBackendUseSupportLevels($info['faq_prj_id']);
        $tpl->assign("backend_uses_support_levels", $backend_uses_support_levels);
        if ($backend_uses_support_levels) {
            $tpl->assign("support_levels", Customer::getSupportLevelAssocList($info['faq_prj_id']));
        }
        $tpl->assign("info", $info);
    }

    $tpl->assign("list", FAQ::getList());
    $tpl->assign("project_list", Project::getAll());
} else {
    $tpl->assign("show_not_allowed_msg", true);
}

$tpl->displayTemplate();
?>