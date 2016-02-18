<?php

/**
 * PublishWithMe
 * Publishes/Unpublishes versioned DataObjects that are attached to a Page or parent DataObject when the parent object is published.
 * DataObjects must have the "Versioned('Stage', 'Live')" extension.
 * Your DataObjects can also have optional versioned functions beginning with "do" that will be called instead of the normal versioning functions. e.g. doPublish
 * The parent must have a $publish_with_me config variable listing the relationships to be published/unpublished etc.
 *
 * @package publishobjectswithme
 * @license BSD License http://www.silverstripe.org/bsd-license
 * @author <chris@christopherbolt.com>
 **/

class PublishWithMe extends DataExtension {
	
	private static $publish_with_me = array();
	
	/**
	 * Builds an array of objects to manage
	 *
	 * @return array
	 */
	private function getItemsToPublish() {
		$this->owner->flushCache();// Ensure that results are not cached
		$objects = array(); // list of items to manage
		$item = $this->owner;
		$fields = $item->config()->get('publish_with_me');
		if ($fields && is_array($fields) && count($fields)) {
			$has_one = $item->config()->get('has_one');
			$has_many = $item->config()->get('has_many');
			$many_many = $item->config()->get('many_many');
			foreach ($fields as $f) {
				if (isset($has_one[$f])) {
					$object = $item->$f();
					if ($object && $object->exists()) $objects[] = $object;
				} else if (isset($has_many[$f]) || isset($many_many[$f])) {
					if ($item::has_extension('TranslatableUtility')) {
						$set = $item->Master()->$f();
					} else {
						$set = $item->$f();
					}
					if ($set) { 
						foreach ($set as $object) {
							if (!$object->exists()) continue;
							$objects[] = $object;
						}
					}
				}
			
			}
		}
		return $objects;
	}
	
	/**
	 * @see SiteTree::doPublish
	 * @param Page $original
	 *
	 * @return void
	 */
	public function onAfterPublish($original) {
		// store all stage items
		$stage_item_ids = array();
		$oldMode = Versioned::get_reading_mode();
		
		// Publish items to Live stage	
		Versioned::set_reading_mode('Stage.Stage');	
		foreach($this->getItemsToPublish() as $field) {
			$stage_item_ids[] = $field->ClassName.'_'.$field->ID;
			if ($field->hasMethod('doPublish')) {
				$field->doPublish('Stage', 'Live');
			} else {
				$field->publish('Stage', 'Live');
			}
			// if this object also has this extension then do cascade
			if ($field->hasMethod('onAfterPublish')) {
				$field->onAfterPublish($field);
			}
		}
		
		// Remove any data on the live table that was deleted from the draft
		Versioned::set_reading_mode('Stage.Live');
		foreach($this->getItemsToPublish() as $field) {
			if (!in_array($field->ClassName.'_'.$field->ID, $stage_item_ids)) {
				if ($field->hasMethod('doDeleteFromStage')) {
					$field->doDeleteFromStage('Live');
				} else {
					$field->deleteFromStage('Live');
				}
			}
		}
		
		// reset mode
		Versioned::set_reading_mode($oldMode);
	}

	/**
	 * @see SiteTree::doUnpublish
	 * @param Page $page
	 *
	 * @return void
	 */
	public function onAfterUnpublish($page) {
		foreach($page->getItemsToPublish() as $field) {
			if ($field->hasMethod('doDeleteFromStage')) {
				$field->doDeleteFromStage('Live');
			} else {
				$field->deleteFromStage('Live');
			}
		}
	}

	/**
	 * @see SiteTree::getIsModifiedOnStage
	 * @param boolean $isModified
	 *
	 * @return boolean
	 */
	public function getIsModifiedOnStage($isModified) {
		if(!$isModified) {
			foreach($this->getItemsToPublish() as $field) {
				if(self::isObjectModifiedOnStage($field)) {
					$isModified = true;
					break;
				}
			}
			// repeat with Live data so we can catch data objects deleted from Stage
			$oldMode = Versioned::get_reading_mode();
			Versioned::set_reading_mode('Stage.Live');
			foreach($this->getItemsToPublish() as $field) {
				if(self::isObjectModifiedOnStage($field)) {
					$isModified = true;
					break;
				}
			}
			Versioned::set_reading_mode($oldMode);
		}
		return $isModified;
	}
	private static function isNew($object) {
		if(empty($object->ID)) return true;

		if(is_numeric($object->ID)) return false;

		return stripos($object->ID, 'new') === 0;
	}
	private static function isObjectModifiedOnStage($object) {
		// new unsaved fields could be never be published
		if(self::isNew($object)) return false;

		$stageVersion = Versioned::get_versionnumber_by_stage($object->ClassName, 'Stage', $object->ID);
		$liveVersion = Versioned::get_versionnumber_by_stage($object->ClassName, 'Live', $object->ID);
		
		return (($stageVersion && $stageVersion != $liveVersion) || !$stageVersion || (($object->hasMethod('getIsModifiedOnStage')) && $object->getIsModifiedOnStage(false)));
	}
	
	/**
	 * @see Versioned::doRollBackTo
	 * @param string $version Either the string 'Live' or a version number
	 *
	 * @return null
	 */
	public function onAfterRollback($version) {
		// get the owner data for this version	
		if(is_numeric($version)) {
			$page = Versioned::get_version($this->owner->ClassName, $this->owner->ID, $version);
		} else {
			$this->owner->flushCache();
			$baseTable = $this->owner->baseTable();	
			$page = Versioned::get_one_by_stage($this->owner->ClassName, $version, "\"{$baseTable}\".\"ID\"={$this->owner->ID}");
		}
				
		$date = $page->LastEdited;
		$oldMode = Versioned::get_reading_mode();
		
		// get the current items
		Versioned::set_reading_mode('Stage.Stage');
		$current_items = array();
		foreach($this->getItemsToPublish() as $field) {
			$current_items[] = $field;
		}
		
		// Get items in the version that we want by setting reading mode to date of version
		if(is_numeric($version)) {
			Versioned::set_reading_mode('Archive.' . $date);
		} else {
			Versioned::set_reading_mode('Stage.Live');
		}
		$version_items = array();
		$version_ids = array();
		foreach($this->getItemsToPublish() as $field) {
			$version_items[] = $field;
			$version_ids[] = $field->ClassName.'_'.$field->ID;
		}
		Versioned::set_reading_mode($oldMode);
		
		// rollback version items
		foreach ($version_items as $field) {
			$fieldVersion = is_numeric($version) ? $field->Version : 'Live';
			if ($field->hasMethod('doDoRollBackTo')) {
				$field->doDoRollBackTo($fieldVersion);
			} else {
				$field->doRollBackTo($fieldVersion);
			}
		}
		
		// delete items not in version
		foreach ($current_items as $field) {
			if (!in_array($field->ClassName.'_'.$field->ID, $version_ids)) {
				$clone = clone $field;
				if ($clone->hasMethod('doDeleteFromStage')) {
					$clone->doDeleteFromStage('Stage');
				} else {
					$clone->deleteFromStage('Stage');
				}
			}
		}
	}
	
	/**
	 * @param FieldList
	 */
	public function updateCMSActions(FieldList $fields) {
		// Update state of publish button if there are unpublished changes in objects
		if ($this->getIsModifiedOnStage(false) && ($publish = $fields->fieldByName('MajorActions.action_publish'))) {
			$publish->addExtraClass('ss-ui-alternate');
		}
	}

	/**
	 * @param FieldList
	 */
	public function updateCMSFields(FieldList $fields) {
		// Better buttons support, since items should be published with the page we remove BetterButtons versioning buttons?
		// This needs to be move to the object itself I think ??
		if (class_exists('BetterButtonAction')) {
			$create = Config::inst()->get("BetterButtonsActions", "create");
			$edit = Config::inst()->get("BetterButtonsActions", "create");
			  
			$create['BetterButton_SaveDraft'] = false;
			$create['BetterButton_Publish'] = false;
			$edit['BetterButton_SaveDraft'] = false;
			$edit['BetterButton_Publish'] = false;
			$edit['Group_Versioning'] = false;
			$edit['BetterButton_Delete'] = false;
			$edit['BetterButtonFrontendLinksAction'] = false;
			
			Config::inst()->update("BetterButtonsActions", "versioned_create", $create);
			Config::inst()->update("BetterButtonsActions", "versioned_edit", $edit);
		}
	}
}