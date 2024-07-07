<?php

namespace Skurvish\Plugin\System\Switchuser\Extension;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/*------------------------------------------------------------------------
# plg_system_switchuser - Switch User Plugin
# ------------------------------------------------------------------------
# author Artd Webdesign GmbH
# copyright Copyright (C) artd.ch. All Rights Reserved.
# @license - http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
# Websites: http://www.artd.ch
# Technical Support:  http://www.artd.ch
-------------------------------------------------------------------------*/
// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );


class Switchuser extends CMSPlugin  implements SubscriberInterface, DatabaseAwareInterface
{
	use DatabaseAwareTrait;

    protected $app; // Provided by the CSMPlugin interface

	protected $db; // Provided by the CMSPlugin interface

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterDispatch' => 'onAfterDispatch',
            'onAfterInitialise' => 'onAfterInitialise',
        ];
    }

	function __construct(&$subject, $config = [])
	{
		$this->autoloadLanguage = true;
		parent::__construct($subject, $config);
		$this->db = Factory::getContainer()->get("DatabaseDriver");
	}
	
	function onAfterDispatch(Event $event)
	{
		$input = $this->app->input;
		$option = $input->get('option', '');
		$task = $input->get('view', $option == 'com_users' ? 'view' : '');
		
		if (!$this->app->isClient('administrator') || $option != 'com_users' || $task != 'users') {
			return;
		}

		$document = Factory::getDocument();
		$content = $document->getBuffer('component');

		$pattern = '/name="cid\[\]" value="(\d+)"/';

		if (!preg_match_all($pattern, $content, $matches)) {

			return;
		}
		$userIds = $matches[1];
		$query = $this->db->getQuery(true);
		$query 
			->select('id, username, name')
			->from('#__users')
			->where('id IN ('.implode(',', $userIds).')');
		$this->db->setQuery($query);
		$users = $this->db->loadObjectList('id');

		$patterns = array();
		$replacements = array();
		foreach ($users as $userId => $user) {
			$patterns[] = '|<a href="'.addcslashes(Route::_('index.php?option=com_users&amp;task=user.edit&amp;id='.(int)$userId), '?').'"[^>]+>\s*'.htmlentities($user->name).'\s*</a>|';
			$replacements[] = '${0} <a href="'.URI::root().'index.php?option=com_users&switchuser=1&uid='.$userId.'" target="_blank" title="'.sprintf(Text::_('SWITCHUSER_FRONT_END'), htmlentities($user->username)).'"><img style="margin: 0 10px;" src="'.URI::root().'media/switchuser/images/frontend-login.png" alt="'.sprintf(Text::_('SWITCHUSER_FRONT_END'), htmlentities($user->username)).'" /></a>';
		}

		$content = preg_replace($patterns, $replacements, $content);
		$document->setBuffer($content, 'component');
	}
	function onAfterInitialise()
	{

		$input = $this->app->input;
		$query = $this->db->getQuery(true);
		$user	= $this->app->getIdentity();
		$userId = $input->get('uid', 0);
		
		if ($this->app->isClient('administrator') || $input->get('switchuser', false, 'bool') == false || !$userId) {
			return;
		}
		
		if ($user->id == $userId) {
 			$this->app->enqueueMessage(Text::_('SWITCHUSER_YOU_HAVE_ALREADY_LOGIN_AS_THIS_USER'), 'warning');
			$this->app->redirect(Route::_('index.php'));
			return;
		}
		
		if ($user->id) {
 			$this->app->enqueueMessage(Text::_('SWITCHUSER_YOU_HAVE_LOGIN_LOGOUT_FIRST'), 'warning');
			$this->app->redirect(Route::_('index.php'));
			return;
		}
		
	
		$backendSessionId = $input->cookie->get(md5(ApplicationHelper::getHash('administrator')), null);

		$query 
			->select('userid')
			->from('#__session')
			->where('session_id = ' . $this->db->Quote($backendSessionId))
			->where('client_id = 1')
			->where('guest = 0');
		$this->db->setQuery($query);
		if (!$backendUserId = $this->db->loadResult()) {
 			$this->app->enqueueMessage(Text::_('SWITCHUSER_BACKEND_USER_SESSION_EXPIRED'), 'warning');
			$this->app->redirect(Route::_('index.php'));
			return;
		}
		
		$instance =  Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId); 

		// If the user is blocked, redirect with an error
		if ($instance->get('block') == 1) {
 			$this->app->enqueueMessage(Text::_('SWITCHUSER_USER_BLOCKED'), 'warning');
			$this->app->redirect(Route::_('index.php'));
			return;
		}

		//Mark the user as logged in
		$instance->set( 'guest', 0);
		$instance->set('aid', 1);

		// Register the needed session variables
		$session = Factory::getSession();
		$session->set('user', $instance);

		// Hit the user last visit field
		$instance->setLastVisit();
 		$this->app->enqueueMessage(Text::_('SWITCHUSER_YOU_HAVE_LOGIN_SUCCESSFULLY'), 'success');
		$menuID = $this->params->get('login');
		$menus = $this->app->getMenu()->getMenu();
		if (empty($menus[$menuID])) {
			$link = "index.php";
			$itemID = "";
		} else {
			$link = $menus[$menuID]->link;
			$itemID = '&Itemid=' . $menuID;
		}
		$this->app->redirect(Route::_($link . $itemID));

	}
}