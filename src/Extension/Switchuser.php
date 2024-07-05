<?php

namespace Skurvish\Plugin\System\Switchuser\Extension;

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
		
		//if (!$app->isAdmin() || $option != 'com_users' || $task != 'view') {
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
			
			//$patterns[] = '|<a href="'.addcslashes(JRoute::_('index.php?option=com_users&amp;view=user&amp;task=edit&amp;cid[]='.(int)$userId), '?').'"[^>]+>\s*'.$user->name.'\s*</a>|';
			//$replacements[] = '${0} <a href="'.JURI::root().'index.php?switchuser=1&uid='.$userId.'" target="_blank" title="'.JText::sprintf('SWITCHUSER_FRONT_END', $user->username).'"><img style="margin: 0 10px;" src="'.JURI::root().'plugins/system/switchuser/switchuser/images/frontend-login.png" alt="'.JText::_('SWITCHUSER_FRONT_END').'" /></a>';
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
		
		if ($this->app->isClient('administrator')|| $input->get('switchuser', 0) || !$userId) {
			return;
		}
		
		if ($user->id == $userId) {
			$this->app->redirect('index.php', JText::_('SWITCHUSER_YOU_HAVE_ALREADY_LOGIN_AS_THIS_USER'));
			return;
		}
		
		if ($user->id) {
 			$this->app->enqueueMessage(Text::_('SWITCHUSER_YOU_HAVE_LOGIN_LOGOUT_FIRST'), 'warning');
			$this->app->redirect('index.php');
			return;
		}
		
		//$backendSessionId = JRequest::getVar(md5(JUtility::getHash('administrator')), null ,"COOKIE");

		
		$backendSessionId = JRequest::getVar(md5(JApplication::getHash('administrator')), null ,"COOKIE");

		$query 
			->select('userid')
			->form('#__session')
			->where('session_id = ' . $db->Quote($backendSessionId))
			->where('client_id = 1')
			->where('guest = 0');
		$this->db->setQuery($query);
		if (!$backendUserId = $this->db->loadResult()) {
 			$this->app->enqueueMessage(Text::_('SWITCHUSER_BACKEND_USER_SESSION_EXPIRED'), 'warning');
			$this->app->redirect('index.php');
			return;
		}
		
		$instance =  Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId); 

		// If _getUser returned an error, then pass it back.
//		if (JError::isError($instance)) {
//			$app->redirect('index.php');
//			return;
//		}

		// If the user is blocked, redirect with an error
		if ($instance->get('block') == 1) {
 			$this->app->enqueueMessage(Text::_('E_NOLOGIN_BLOCKED'), 'warning');
			$this->app->redirect('index.php');
			return;
		}

		// Get an ACL object
//		$acl = JFactory::getACL();

		// Get the user group from the ACL
		if ($instance->get('tmp_user') == 1) {
			$grp = new stdClass();
			// This should be configurable at some point
			$grp->set('name', 'Registered');
		} else {
			//$grp = $acl->getAroGroup($instance->get('id'));
		}

		//Authorise the user based on the group information
		if(!isset($options['group'])) {
			$options['group'] = 'USERS';
		}

		// if(!$acl->is_group_child_of( $grp->name, $options['group'])) {
		// 	return JError::raiseWarning('SOME_ERROR_CODE', JText::_('E_NOLOGIN_ACCESS'));
		// }

		//Mark the user as logged in
		$instance->set( 'guest', 0);
		$instance->set('aid', 1);

		// Fudge Authors, Editors, Publishers and Super Administrators into the special access group
		// if ($acl->is_group_child_of($grp->name, 'Registered')      ||
		//     $acl->is_group_child_of($grp->name, 'Public Backend'))    {
		// 	$instance->set('aid', 2);
		// }

		//Set the usertype based on the ACL group name
		$instance->set('usertype', $grp->name);

		// Register the needed session variables
		$session = Factory::getSession();
		$session->set('user', $instance);

		// Get the session object
		$table = Table::getInstance('session');
		$table->load( $session->getId() );

		$table->guest 		= $instance->get('guest');
		$table->username 	= $instance->get('username');
		$table->userid 		= intval($instance->get('id'));
		$table->usertype 	= $instance->get('usertype');
		//$table->gid 		= intval($instance->get('gid'));

		$table->update();

		// Hit the user last visit field
		$instance->setLastVisit();
		$this->app->redirect('index.php', Text::_('SWITCHUSER_YOU_HAVE_LOGIN_SUCCESSFULLY'));
	}
}