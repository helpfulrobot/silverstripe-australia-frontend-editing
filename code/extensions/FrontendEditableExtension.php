<?php
/*

Copyright (c) 2009, SilverStripe Australia PTY LTD - www.silverstripe.com.au
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * Neither the name of SilverStripe nor the names of its contributors may be used to endorse or promote products derived from this software
      without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY
OF SUCH DAMAGE.
*/

/**
 * An extension that allows theme authors to mark certain regions as editable
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class FrontendEditableExtension extends DataObjectDecorator
{
	public function extraStatics()
	{
		return array(
			'has_one' => array(
				'Creator' => 'Member',
			)
		);
	}

	/**
	 * Make sure to set a creator!
	 */
	public function onBeforeWrite()
	{
		if (!$this->owner->CreatorID) {
			$this->CreatorID = Member::currentUserID();
		}
	}

	/**
	 * Each page that is editable should have an "Owner" 
	 *
	 * @param FieldSet $fields
	 */
	public function updateCMSFields($fields)
	{
		$members = DataObject::get('Member');
		$ids = $members->column('ID');
		$unames = $members->column('getTitle');
		$users = array_combine($ids, $unames);

		if (!$this->owner->CreatorID) {
			$this->owner->CreatorID = Member::currentUserID();
		}

		$fields->addFieldToTab('Root.Content.Main', new DropdownField('CreatorID', 'Owner', $users), 'Content');
	}

	/**
	 * Are we viewing this page on the live site?
	 *
	 * @return boolean
	 */
	public function LiveSite()
	{
		return Versioned::current_stage() == 'Live';
	}

	/**
	 * Indicates whether the current user can edit the current fields on the frontend
	 *
	 * @param String $checkStage
	 *			If set, the stage will be checked to ensure that we're on that stage - this
	 *			allows us to check if the current user has got access to edit (regardless of whether they're on the
	 *			right stage), and to check including the right stage
	 *
	 * @return boolean
	 */
	public function FrontendEditAllowed($checkStage=true)
	{
		if (!Member::currentUserID()) {
			return false;
		}
		$isCreator = Member::currentUserID() == $this->owner->CreatorID;
		$canEdit = $this->owner->canEdit();
		$frontendPerm = Permission::check(FrontendEditing_Controller::PERM_FRONTEND_EDIT);

		if ($checkStage === true) {
			$stage = Versioned::current_stage() == 'Stage';
		} else {
			$stage = true;
		}

		if (!($isCreator || $canEdit || $frontendPerm) || !$stage) {
			return false;
		}
		return true;
	}

	/**
	 * Return an html fragment that can be used for editing a given field on the frontend of the website
	 *
	 * @TODO: Refactor this so that the field creation etc is actually done based on the type of the
	 * field - eg if it's an HTML field use niceditor, if it's a text field use textfield, etc etc
	 *
	 * Needs some adjustment to the frontend so that fields other than the native nicedit work nicely.
	 *
	 * @param String $fieldName
	 * @param String $tagType
	 * @return String
	 */
	public function EditableField($fieldName, $tagType='div')
	{
		Requirements::javascript('sapphire/thirdparty/jquery/jquery-packed.js');
		Requirements::javascript('frontend-editing/javascript/edit-controls.js');
		Requirements::css('frontend-editing/css/edit-controls.css');

		// output only if the user can edit, otherwise we just output the field
		if ($this->FrontendEditAllowed()) {
			// try and take the lock
			$lock = $this->owner->getEditingLocks(true);
			Requirements::css('frontend-editing/css/page-editor.css');
			// we can't edit if there's a lock and that locking user is NOT us
			if ($lock != null && $lock['user'] != Member::currentUser()->Email) {
				return '<div class="__editable_locked">'.$this->owner->XML_val($fieldName).'<p class="lockInfo">'.sprintf(_t('FrontendEdit.LOCKED_BY', 'Locked by %s until %s'), $lock['user'], $lock['expires']).'</p></div>';
			} else {
				Requirements::css('frontend-editing/javascript/jstree/themes/default/style.css');

				Requirements::css('frontend-editing/javascript/jquery.jgrowl.css');
				Requirements::javascript('frontend-editing/javascript/jquery.jgrowl_minimized.js');

				Requirements::javascript('frontend-editing/javascript/jstree-0.9.9a2/jquery.tree.js');
				Requirements::javascript('frontend-editing/javascript/jquery.json.js');
				Requirements::javascript('frontend-editing/javascript/nicEditDev.js');
				
				Requirements::javascript('frontend-editing/javascript/page-editor.js');

				Requirements::javascript('frontend-editing/javascript/nicedit-table.js');
				Requirements::javascript('frontend-editing/javascript/nicedit-image-selector.js');
				Requirements::javascript('frontend-editing/javascript/nicedit-class-selector.js');
				Requirements::javascript('frontend-editing/javascript/nicedit-url-selector.js');

				$lockUpdate = $this->owner->getLockUpdater();
				Requirements::customScript($lockUpdate, 'lock_updater_for_'.$this->owner->ID);
				Requirements::customScript("var SS_SECURITY_ID='".Session::get('SecurityID')."'");

				$ID = $this->owner->ID;
				$typeInfo = $this->owner->ClassName.'-'.$ID;
				// now add the wrapped field
				return '<'.$tagType.' class="__wysiwyg-editable" id="'.$typeInfo.'|'.$ID.'|'.$fieldName.'">'.$this->owner->XML_val($fieldName).'</'.$tagType.'>';
			}
		} else {
			return $this->owner->XML_val($fieldName);
		}
	}
}
?>