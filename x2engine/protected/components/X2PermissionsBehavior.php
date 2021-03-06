<?php
/*****************************************************************************************
 * X2CRM Open Source Edition is a customer relationship management program developed by
 * X2Engine, Inc. Copyright (C) 2011-2013 X2Engine Inc.
 * 
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY X2ENGINE, X2ENGINE DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more
 * details.
 * 
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 * 
 * You can contact X2Engine, Inc. P.O. Box 66752, Scotts Valley,
 * California 95067, USA. or at email address contact@x2engine.com.
 * 
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 * 
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * X2Engine" logo. If the display of the logo is not reasonably feasible for
 * technical reasons, the Appropriate Legal Notices must display the words
 * "Powered by X2Engine".
 *****************************************************************************************/

/**
 * CModelBehavior class for permissions lookups on classes.
 *
 * X2PermissionsBehavior is a CModelBehavior which allows consistent lookup of
 * access levels and whether or not a user is allowed to view or edit a record.
 *
 * @package X2CRM.components
 */
class X2PermissionsBehavior extends CActiveRecordBehavior {

    /**
     * Returns a CDbCriteria containing record-level access conditions.
     * @return CDbCriteria
     */
    public function getAccessCriteria(){
        $criteria = new CDbCriteria;

        $accessLevel = $this->getAccessLevel();

        if($this->owner->hasAttribute('visibility')){
            $visFlag = true;
        }else{
            $visFlag = false;
        }

        $criteria->addCondition(self::getAccessConditions($accessLevel, $visFlag), 'AND');

        return $criteria;
    }

    /**
     * Returns a number from 0 to 3 representing the current user's access level using the Yii auth manager
     * Assumes authItem naming scheme like "ContactsViewPrivate", etc.
     * This method probably ought to overridden, as there is no reliable way to determine the module a model "belongs" to.
     * @return integer The access level. 0=no access, 1=own records, 2=public records, 3=full access
     */
    public function getAccessLevel(){
        $module = ucfirst($this->owner->module);

        if(Yii::app()->isInSession){ // Web request
            $uid = Yii::app()->user->id;
        }else{ // User session not available; doing an operation through API or console
            $uid = Yii::app()->getSuID();
        }
        $accessLevel = 0;
        if(Yii::app()->authManager->checkAccess($module.'Admin', $uid)){
            if($accessLevel < 3)
                $accessLevel = 3;
        }elseif(Yii::app()->authManager->checkAccess($module.'View', $uid)){
            if($accessLevel < 2)
                $accessLevel = 2;
        }elseif(Yii::app()->authManager->checkAccess($module.'PrivateReadOnlyAccess', $uid)){
            if($accessLevel < 1)
                $accessLevel = 1;
        }
        $roles = X2Model::model('RoleToUser')->findAllByAttributes(array('userId' => $uid));
        foreach($roles as $role){
            if(Yii::app()->authManager->checkAccess($module.'Admin', $role->roleId)){
                if($accessLevel < 3)
                    $accessLevel = 3;
            }elseif(Yii::app()->authManager->checkAccess($module.'View', $role->roleId)){
                if($accessLevel < 2)
                    $accessLevel = 2;
            }elseif(Yii::app()->authManager->checkAccess($module.'PrivateReadOnlyAccess', $role->roleId)){
                if($accessLevel < 1)
                    $accessLevel = 1;
            }
        }
        return $accessLevel;
    }

    /**
     * Generates SQL condition to filter out records the user doesn't have permission to see.
     * This method is used by the 'accessControl' filter.
     * @param Integer $accessLevel The user's access level. 0=no access, 1=own records, 2=public records, 3=full access
     * @param Boolean $useVisibility Whether to consider the model's visibility setting
     * @param String $user The username to use in these checks (defaults to current user)
     * @return String The SQL conditions
     */
    public static function getAccessConditions($accessLevel, $useVisibility = true, $user = null){
        if($user === null){
            if(Yii::app()->isInSession)
                $user = Yii::app()->user->getName();
            else
                $user = Yii::app()->getSuModel()->username;
        }

        if($accessLevel === 2 && $useVisibility === false) // level 2 access only works if we consider visibility,
            $accessLevel = 3;  // so upgrade to full access

        switch($accessLevel){
            case 3:  // user can view everything
                return 'TRUE';
            case 1:  // user can view records they (or one of their groups) own
                return 't.assignedTo="'.$user.'"
					OR t.assignedTo IN (SELECT groupId FROM x2_group_to_user WHERE username="'.$user.'")';
            case 2:  // user can view any public (shared) record
                return 't.visibility=1
					OR t.assignedTo="'.$user.'"
					OR t.assignedTo IN (SELECT groupId FROM x2_group_to_user WHERE username="'.$user.'")
					OR (t.visibility=2 AND t.assignedTo IN (SELECT DISTINCT b.username FROM x2_group_to_user a INNER JOIN x2_group_to_user b ON a.groupId=b.groupId WHERE a.username="'.$user.'"))';
            default:
            case 0:  // can't view anything
                return 'FALSE';
        }
    }

}

?>
