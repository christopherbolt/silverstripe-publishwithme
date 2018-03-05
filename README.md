# PublishWithMe #
Publishes/Un-publishes versioned DataObjects that are attached to a Page when the page is published.

Lets imagine that you have a staff profiles page where each staff member is a DataObject in a has_many relation. Without any versioning edits to a staff member would be published immediately breaking workflow approval processes and breaking the draft, publish and history functionality of Silverstripe. Or if they did have the Versioned extension they would need to be published independantly from the page which complicates workflow. This module allows those edits to be published when the page is published. Also supports un-publishing, revert to live and history rollback with the page. This allows objects to behave as if they are part of the page.

## Upgrading from SilverStripe 3 ##
Most of the functionality of this plugin is now built into SilverStripe 4 and can be enabled by using the $owns config that is baked into SS4. To upgrade please replace the $publish_with_me config with $owns.
In SS4 all this plugin does is enhance the Saved / Published button to show that the page needs publishing if any $owns objects have unpublished changes.

## Requirements ##
SS 4.x, see 1.0 branch for SS 3.x

## Usage ##
Add the PublishWithMe extension to your page (or parent DataObject):
```
private static $extensions = array(
    "PublishWithMe"
);
```
Still on your page (or parent DataObject) define which relations should be managed by this extension, this is a list of array keys from your has_one and/or has_many relations, this just now uses the $owns config that is built into SS4:
```
private static $owns = array(
    'Staff',
);
```	
The DataObjects to be published with the page must have the Versioned extension, in the example above you would add this to your StaffMember object:
```
private static $extensions = array(
    Versioned::class,
);
```
If your dataobjects themselves contain relations that should be published with the page then also add the PublishWithMe extension and the $owns config and ensure that the child data objects have the Versioned extension etc.

### Installation ###
```
composer require christopherbolt/silverstripe-publishwithme ^2
```

