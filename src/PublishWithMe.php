<?php

namespace ChristopherBolt\PublishWithMe;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Core\Config\Config;

/**
 * PublishWithMe
 * Publishes/Unpublishes versioned DataObjects that are attached to a Page or parent DataObject when the parent object is published.
 * DataObjects must have the "Versioned" extension.
 * Your DataObjects can also have optional versioned functions beginning with "do" that will be called instead of the normal versioning functions. e.g. doPublish
 * The parent must have a $owns config variable listing the relationships to be published/unpublished etc.
 *
 * @package publishobjectswithme
 * @license BSD License http://www.silverstripe.org/bsd-license
 * @author <chris@christopherbolt.com>
 **/

class PublishWithMe extends DataExtension {
	
	private static $owns = array();
	
	/**
	 * Builds an array of objects to manage
	 *
	 * @return array
	 */
	private function getItemsToPublish() {
		
		//$this->owner->flushCache();// Ensure that results are not cached
		$objects = array(); // list of items to manage
		$item = $this->owner;
		$fields = $item->config()->get('owns');
		if ($fields && is_array($fields) && count($fields)) {
			
			$has_one = $item->config()->get('has_one');
			$has_many = $item->config()->get('has_many');
			$many_many = $item->config()->get('many_many');
			foreach ($fields as $f) {
				if (isset($has_one[$f])) {
					$object = $item->obj($f);
					if ($object && $object->exists()) $objects[] = $object;
				} else if (isset($has_many[$f]) || isset($many_many[$f])) {
					if ($item::has_extension('TranslatableUtility')) {
						if ($item->Master()->hasMethod($f)) $set = $item->Master()->$f();
					} else {
						if ($item->hasMethod($f)) $set = $item->$f();
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
		
		return (($stageVersion && $stageVersion != $liveVersion) || (($object->hasMethod('getIsModifiedOnStage')) && $object->getIsModifiedOnStage(false)));
	}
	
	/**
	 * @param FieldList
	 */
	public function updateCMSActions(FieldList $fields) {
		// Update state of publish button if there are unpublished changes in objects
		if ($this->getIsModifiedOnStage(false) && ($publish = $fields->fieldByName('MajorActions.action_publish'))) {
			$publish->addExtraClass('btn-primary font-icon-rocket');
			$publish->removeExtraClass('btn-outline-primary');
			$publish->setTitle(_t('SilverStripe\CMS\Model\SiteTree.BUTTONSAVEPUBLISH', 'Save & publish'));
		}
	}
}