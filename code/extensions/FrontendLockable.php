<?php

/**
 * Apply this extension to a page type to allow it to be 'locked'. 
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class FrontendLockable extends DataExtension
{

    /**
     * lock pages for 1 minute at a time by default
     * This value is in seconds
     *
     * @var int
     */
    public static $lock_time = 120;
    public static $db = array(
        'LockExpiry' => 'SS_Datetime',
        'LastEditor' => 'Varchar(64)', // different from the 'modifiedby'? 
    );

    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldToTab('Root.Locking', new TextField('LastEditor', _t('EditablePage.LOCKEDBY', 'Last locked by'), '', 20));
        $fields->addFieldToTab('Root.Locking', new TextField('LockExpiry', _t('EditablePage.LOCK_EXPIRY', 'Lock will expire by'), '', 20));
    }
    
    public function getLockTime()
    {
        return (self::$lock_time - 10);
    }

    /**
     * Return HTML needed to persist the lock from the frontend of the website
     *
     * Returns raw javascript that must first be wrapped in <script> tags before being usable!
     *
     */
    public function getLockUpdater()
    {
        $updateUrl = Director::baseURL() . LOCKABLE_PREFIX . '/updatelock/' . $this->owner->ID;
        $timeout = (self::$lock_time - 10);
        $script = <<<JSCRIPT
(function ($) {
	var lockUpdateUrl = '$updateUrl';
	var timeout = $timeout * 1000;
	setInterval(function () {
		$.post(lockUpdateUrl);
	}, timeout);
})(jQuery);
JSCRIPT;

        return $script;
    }

    /**
     * Before saving, make sure to set a default lock time
     */
    public function onBeforeWrite()
    {
        // set a lock expiry in the past if there's not one already set
        if (!$this->owner->LockExpiry) {
            $this->owner->LockExpiry = date('Y-m-d H:i:s');
        }

        // Make sure to set the last editor to the current user
        if (Member::currentUser()) {
            $this->owner->LastEditor = Member::currentUser()->Email;
        }
    }

    /**
     * Lock the page for the current user
     *
     * @param Member $member
     * 			The user to lock the page for
     */
    public function lock($member = null)
    {
        if (!$member) {
            $member = Member::currentUser();
        }

        if (!$member) {
            return;
        }

        // set the updated lock expiry based on now + lock timeout
        $this->owner->LastEditor = $member->Email;
        $this->owner->LockExpiry = date('Y-m-d H:i:s', time() + self::$lock_time);

        // save it with us as the editor
        $this->owner->write();
    }

    /**
     * Retrieve the details about the locked state of the current page.
     *
     * An array containing the locking user's name and the date that the lock will
     * expire.
     *
     * If the 'doLock' parameter is set, the page will be locked for the current user
     *
     * @param SiteTree $page
     * 			The page being edited
     * @param boolean $doLock
     * 			Whether to actually lock the page for ourselves
     * @return array
     * 			The names of any existing editors
     */
    public function getEditingLocks($doLock = false)
    {
        $currentStage = Versioned::current_stage();

        Versioned::reading_stage('Stage');

        $filter = array(
            'ID' => $this->owner->ID,
            'LockExpiry:GreaterThan' => date('Y-m-d H:i:s'),
        );

        $user = Member::currentUser();
        $currentLock = Page::get()->filter($filter)->first();

        $lock = null;

        if ($currentLock && $currentLock->ID) {
            // if there's a current lock in place, lets return that value
            $lock = array(
                'user' => $currentLock->LastEditor,
                'expires' => $currentLock->LockExpiry,
            );
        }

        // If we're trying to take the lock, make sure that a) there's no existing
        // lock or b) we currently hold the lock
        if ($doLock && ($currentLock == null || !$currentLock->ID || $currentLock->LastEditor == $user->Email)) {
            $this->lock();
        }

        Versioned::reading_stage($currentStage);

        return $lock;
    }

    /**
     * Indicates whether the member has the lock on the current object
     *
     * If the second parameter is true, then it means we're being explicit in that the user must
     * have previously taken the locks before this method was called, and will not try to take
     * locks automatically. 
     *
     * @param Member $member
     * 			The member to check lock holding for
     * @param boolean $explicit
     * 			If set, then the logic will return true ONLY if the user has previously taken the locks
     * 			and will not attempt to take the locks now
     */
    public function userHasLocks($member = null, $explicit = true)
    {
        if (!$member) {
            $member = Member::currentUser();
        }

        $lock = $this->getEditingLocks(!$explicit);
        if (!isset($lock['LastEditor']) || $lock['LastEditor'] == $member->Email) {
            return true;
        }

        return false;
    }
}
