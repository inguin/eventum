<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

namespace Eventum\Controller;

use Auth;
use AuthCookie;
use Project;

class SwitchController extends BaseController
{
    /** @var int */
    private $prj_id;

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $request = $this->getRequest();

        $this->prj_id = $request->request->get('current_project');
    }

    /**
     * {@inheritdoc}
     */
    protected function canAccess()
    {
        Auth::checkAuthentication();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function defaultAction(): void
    {
        if (!Project::exists($this->prj_id)) {
            $this->error('The specified project does not exist');
        }

        AuthCookie::setProjectCookie($this->prj_id);
        $this->messages->addInfoMessage(ev_gettext('The project has been switched'));

        $url = $this->getRedirectUrl();
        $this->redirect($url);
    }

    private function getRedirectUrl()
    {
        $request = $this->getRequest();
        $url = $request->get('current_page');

        // if url is 'view.php', use 'list.php',
        // otherwise autoswitcher will switch back to the project where the issue was :)
        if (!$url || stripos($url, 'view.php') !== false) {
            $url = APP_RELATIVE_URL . 'list.php';
        }

        return $url;
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareTemplate(): void
    {
    }
}
