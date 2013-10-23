<?php
/*
 * Base class for DataObjects that behave like pages
 * 
 */
class DataObjectAsPage extends DataObject {
	
	/**
	 * @var defind the listing page class name
	 */
	private static $listing_page_class = 'DataObjectAsPageHolder';
	
	private static $db = array (
		'URLSegment' => 'Varchar(100)',
		'Title' => 'Varchar(255)',
		'MetaTitle' => 'Varchar(255)',
		'MetaDescription' => 'Varchar(255)',
		'Content' => 'HTMLText'
	);
	
	private static $defaults = array(
		'Title'=>'New Item',
		'URLSegment' => 'new-item'
	);
	
	private static $summary_fields = array(
		'Title' => 'Title',
		'URLSegment' => 'URLSegment'
	);

	private static $indexes = array(
		"URLSegment" => array(
			'type' => 'unique',
			'value' => 'URLSegment'
		)
	);
	
	private static $default_sort = 'Created DESC';

	/**
	 * Provide compatability with Menu loops in templates
	 */
	public function MenuTitle()
	{
		return $this->Title;
	}

	/**
	 * Override getMetaTitle to keep DB cleaner
	 *
	 * @return string The Meta Title
	 */
	public function getMetaTitle()
	{
		if ($value = $this->getField('MetaTitle'))
		{
			return $value;
		}
		return $this->getField('Title');
	}

	/**
	 * Override getMetaTitle to keep DB cleaner
	 *
	 * @param string $value The value for the MetaTitle field
	 */
	public function setMetaTitle($value) {
		if ($value == $this->getField('Title'))
		{
			$this->setField('MetaTitle', null);
		}
		else
		{
			$this->setField('MetaTitle', $value);
		}
	}

	/**
	 * Check if the member can view
	 *
	 * @param Member $member The member to check against
	 * @return boolean Whether the member or current member can view
	 */
	public function canView($member = null)
	{
		//if no member was supplied assume current member
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) $member = Member::currentUser();

		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canView', $member);
		if($extended !==null) return $extended;

		//If this is draft check for permissions to view draft content
		//getSearchResultItem is needed to ensure unpublished items don't show up in search results		
		if($this->isVersioned && Versioned::current_stage() == 'Stage' && $this->Status == 'Draft')
		{
			return Permission::checkMember($member,'VIEW_DRAFT_CONTENT');
		}		
		elseif(Controller::curr()->hasMethod("canView"))
		{
			//Otherwise return the parent listing pages view permission
			return Controller::curr()->canView($member);
		}
		return true;
	}

	/**
	 * Check if the member can publish
	 *
	 * @param Member $member The member to check against
	 * @return boolean Whether the member or current member can publish
	 */
	public function canPublish($member = null)
	{
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) $member = Member::currentUser();

		if($member && Permission::checkMember($member, "ADMIN")) return true;

		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canPublish', $member);
		if($extended !== null) return $extended;

		// Normal case - fail over to canEdit()
		return $this->canEdit($member);
	}

	/**
	 * Check if the member can delete live content
	 *
	 * @param Member $member The member to check against
	 * @return boolean Whether the member or current member can delete live content
	 */
	public function canDeleteFromLive($member = null)
	{
		//if no member was supplied assume current member
		if(!$member || !(is_a($member, 'Member')) || is_numeric($member)) $member = Member::currentUser();

		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canDeleteFromLive', $member);
		if($extended !==null) return $extended;

		return $this->canPublish($member);
	}

    /**
	 * Override the CMS acctions to create a "duplicate" button
	 *
	 * @return FieldList The updated list of actions
	 */
	public function getCMSActions()
	{
		$minorActions = CompositeField::create()->setTag('fieldset')->addExtraClass('ss-ui-buttonset');
		$actions = new FieldList($minorActions);

		if($this->ID)
		{
			if($this->isPublished() && $this->canPublish() && $this->canDeleteFromLive()) {
				// "unpublish"
				$minorActions->push(
					FormAction::create('unpublish', _t('SiteTree.BUTTONUNPUBLISH', 'Unpublish'), 'delete')
						->setDescription(_t('SiteTree.BUTTONUNPUBLISHDESC', 'Remove this page from the published site'))
						->addExtraClass('ss-ui-action-destructive')->setAttribute('data-icon', 'unpublish')
				);
			}		

			if($this->canEdit()) {
				
				if($this->canDelete()) {
					// "delete"
					$minorActions->push(
						FormAction::create('delete','Delete')->addExtraClass('delete ss-ui-action-destructive')
							->setAttribute('data-icon', 'decline')
					);
				}
		
				if($this->hasChangesOnStage()) {
					if($this->isPublished() && $this->canEdit())	{
						// "rollback"
						$minorActions->push(
							FormAction::create('rollback', _t('SiteTree.BUTTONCANCELDRAFT', 'Cancel draft changes'), 'delete')
								->setDescription(_t('SiteTree.BUTTONCANCELDRAFTDESC', 'Delete your draft and revert to the currently published page'))
						);
					}
				}
		
				if ($this->canCreate())
				{	
					//Create the Duplicate action
					$minorActions->push( FormAction::create('duplicate', 'Duplicate')
						->setDescription("Duplicate this item")
					);
				}			
				// "save"
				$minorActions->push(
					FormAction::create('doSave',_t('CMSMain.SAVEDRAFT','Save Draft'))->setAttribute('data-icon', 'addpage')
				);
			}
	
			if($this->canPublish()) {
				// "publish"
				$actions->push(
					FormAction::create('publish', _t('SiteTree.BUTTONSAVEPUBLISH', 'Save & Publish'))
						->addExtraClass('ss-ui-action-constructive')->setAttribute('data-icon', 'accept')
				);
			}
			
		}
		else 
		{
			//Change the Save label to 'Create'
			$actions->push(FormAction::create('doSave', _t('GridFieldDetailForm.Create', 'Create'))
				->setUseButtonTag(true)
				->addExtraClass('ss-ui-action-constructive')
				->setAttribute('data-icon', 'add'));
		}
		
		return $actions;
	} 

	/**
	 * Overload getCMSFields for our custom fields
	 *
	 * @return FieldList The list of CMS Fields
	 */
	public function getCMSFields() 
	{
		$fields = parent::getCMSFields();
		
		//Add the status/view link
		if($this->ID)
		{
			if($this->isVersioned)
			{
				$status = $this->Status;	
				
				$color = '#E88F31';
				$links = sprintf(
					"<a target=\"_blank\" class=\"ss-ui-button\" data-icon=\"preview\" href=\"%s\">%s</a>", $this->Link() . '?Stage=stage', 'Draft'
				);
			
				if($this->Status == 'Published')
				{
					$color = '#000';
					$links .= sprintf(
						"<a target=\"_blank\" class=\"ss-ui-button\" data-icon=\"preview\" href=\"%s\">%s</a>", $this->Link() . '?Stage=live', 'Published'
					);
					
					if($this->hasChangesOnStage())
					{
						$status .= ' (changed)';
						$color = '#428620';
					}
				}
				
				$statusPill = '<h3 class="doapTitle" style="background: '.$color.';">'. $status . '</h3>';
			}
			else
			{
				$links = sprintf(
					"<a target=\"_blank\" class=\"ss-ui-button\" data-icon=\"preview\" href=\"%s\">%s</a>", $this->Link() . '?Stage=stage', 'View'
				);	
				
				$statusPill = "";
			}

			$fields->addFieldToTab('Root.Main', new LiteralField('', 
				'<div class="doapToolbar">
					' . $statusPill . '
					<p class="doapViewLinks">
						' . $links . '
					</p>
				</div>'
			));
		}

		//Remove Scafolded fields
		$fields->removeFieldFromTab('Root.Main', 'URLSegment');
		$fields->removeFieldFromTab('Root.Main', 'Status');
		$fields->removeFieldFromTab('Root.Main', 'Version');
		$fields->removeFieldFromTab('Root.Main', 'MetaTitle');
		$fields->removeFieldFromTab('Root.Main', 'MetaDescription');
		$fields->removeByName('Versions');
		
		$fields->addFieldToTab('Root.Main', new TextField('Title'));	

		if($this->ID)
		{
			$urlsegment = new SiteTreeURLSegmentField("URLSegment", $this->fieldLabel('URLSegment'));
			$urlsegment->setURLPrefix(Director::absoluteBaseURL() . 'listing-page/show/');
			
			$helpText = _t('SiteTreeURLSegmentField.HelpChars', ' Special characters are automatically converted or removed.');
			$urlsegment->setHelpText($helpText);
			$fields->addFieldToTab('Root.Main', $urlsegment);
		}

		$fields->addFieldToTab('Root.Main', new HTMLEditorField('Content'));	

		$fields->addFieldToTab('Root.Main',new ToggleCompositeField('Metadata', 'Metadata',
			array(
				new TextField("MetaTitle", $this->fieldLabel('MetaTitle')),
				new TextareaField("MetaDescription", $this->fieldLabel('MetaDescription'))
			)
		));
		
		//$fields->push(new HiddenField('PreviewURL', 'Preview URL', $this->StageLink()));
		//$fields->push(new TextField('CMSEditURL', 'Preview URL', $this->CMSEditLink()));
		
		return $fields;
	}

	/**
	 * Utility function to enable versioning in a simple call
	 */
	public static function enable_versioning()
	{
	  	DataObject::add_extension('DataObjectAsPage','VersionedDataObjectAsPage');
		DataObject::add_extension('DataObjectAsPage',"Versioned('Stage', 'Live')");
	}
	
	/**
	 * Check if the DOAP is versioned
	 *
	 * @return boolean
	 */
	public function getisVersioned()
	{
		return $this->hasExtension('Versioned');
	}

	/**
	 * Produce the correct breadcrumb trail for use on the DataObject Item Page
	 */
	public function Breadcrumbs($maxDepth = 20, $unlinked = false, $stopAtPageType = false, $showHidden = false) 
	{
		$page = Controller::curr();
		$pages = array();
		
		$pages[] = $this;
		
		while(
			$page  
 			&& (!$maxDepth || count($pages) < $maxDepth) 
 			&& (!$stopAtPageType || $page->ClassName != $stopAtPageType)
 		) {
			if($showHidden || $page->ShowInMenus || ($page->ID == $this->ID)) { 
				$pages[] = $page;
			}
			
			$page = $page->Parent;
		}
		
		$template = new SSViewer('BreadcrumbsTemplate');
		
		return $template->process($this->customise(new ArrayData(array(
			'Pages' => new ArrayList(array_reverse($pages))
		))));
	}
		
	/**
	 * Generate custom metatags to display on the DataObject Item page
	 */ 
	public function MetaTags($includeTitle = true) 
	{
		$tags = "";
		if($includeTitle === true || $includeTitle == 'true') {
			$tags .= "<title>" . Convert::raw2xml(($this->MetaTitle)
				? $this->MetaTitle
				: $this->Title) . "</title>\n";
		}

		$tags .= "<meta name=\"generator\" content=\"SilverStripe - http://silverstripe.org\" />\n";

		$charset = ContentNegotiator::get_encoding();
		$tags .= "<meta http-equiv=\"Content-type\" content=\"text/html; charset=$charset\" />\n";

		if($this->MetaDescription) {
			$tags .= "<meta name=\"description\" content=\"" . Convert::raw2att($this->MetaDescription) . "\" />\n";
		}

		$this->extend('MetaTags', $tags);

		return $tags;
	}

	/**
	 * Check if this page has been published.
	 *
	 * @return boolean True if this page has been published.
	 */
	public function isPublished() 
	{
		return (DB::query("SELECT \"ID\" FROM \"DataObjectAsPage_Live\" WHERE \"ID\" = $this->ID")->value())
			? true
			: false;
	}

	/**
	 * Create a duplicate of this node. Doesn't affect joined data - create a
	 * custom overloading of this if you need such behaviour.
	 *
	 * @return SiteTree The duplicated object.
	 */
	 public function doDuplicate($doWrite = true) 
	 {
		$item = parent::duplicate(false);
		$this->extend('onBeforeDuplicate', $item);
 
        //Change the title so we know we are looking at the copy
        $item->Title = 'Copy of ' . $this->Title;
        $item->Status = 'Draft';
        		
		if($doWrite) {
			$item->write();
		}
		
		$this->extend('onAfterDuplicate', $page);
		
		return $item;
	}
	
	/**
	 * Publish this page.
	 * 
	 * @uses SiteTreeDecorator->onBeforePublish()
	 * @uses SiteTreeDecorator->onAfterPublish()
	 */
	public function doPublish() 
	{
		if (!$this->canPublish()) return false;
		
		$original = Versioned::get_one_by_stage("DataObjectAsPage", "Live", "\"DataObjectAsPage\".\"ID\" = $this->ID");
		if(!$original) $original = new DataObjectAsPage();

		// Handle activities undertaken by decorators
		$this->invokeWithExtensions('onBeforePublish', $original);
		
		$this->Status = "Published";
		//$this->PublishedByID = Member::currentUser()->ID;
		$this->write();
		$this->publish("Stage", "Live");

		// Handle activities undertaken by decorators
		$this->invokeWithExtensions('onAfterPublish', $original);
		
		return true;
	}

	/**
	 * Unpublish this DataObject - remove it from the live site
	 * 
	 */
	public function doUnpublish() 
	{
		if(!$this->ID) return false;
		if (!$this->canDeleteFromLive()) return false;
		
		$this->extend('onBeforeUnpublish');
		
		$origStage = Versioned::current_stage();
		Versioned::reading_stage('Live');

		// This way our ID won't be unset
		$clone = clone $this;
		$clone->delete();

		Versioned::reading_stage($origStage);

		// If we're on the draft site, then we can update the status.
		// Otherwise, these lines will resurrect an inappropriate record
		if(DB::query("SELECT \"ID\" FROM \"DataObjectAsPage\" WHERE \"ID\" = $this->ID")->value()
			&& Versioned::current_stage() != 'Live') {
			$this->Status = "Draft";
			$this->write();
		}

		$this->extend('onAfterUnpublish');

		return true;
	}

	/**
	 * Function to delete the DOAP including from versioned tables
	 */
	public function doDelete() 
	{
		$this->doUnpublish();
		
		$oldMode = Versioned::get_reading_mode();
		Versioned::reading_stage('Draft');

		//delete all versioned objects with this ID
		$result = DB::query("DELETE FROM DataObjectAsPage_versions WHERE RecordID = '$this->ID'");
		$result = $this->delete();
				
		Versioned::set_reading_mode($oldMode);

		return $result;
	}
	
	
	/**
	 * Revert the draft changes: replace the draft content with the content on live
	 */
	public function doRevertToLive() 
	{
		$this->publish("Live", "Stage", false);

		// Use a clone to get the updates made by $this->publish
		$clone = DataObject::get_by_id("DataObjectAsPage", $this->ID);
		$clone->writeWithoutVersion();
		
		$this->extend('onAfterRevertToLive');
		
		return $clone;
	}
	
	/**
	 * Check whether this DO has changes which are not published
	 */
	public function hasChangesOnStage()
	{
		$latestPublishedVersion = $this->get_versionnumber_by_stage('DataObjectAsPage', 'Live', $this->ID);
		$latestVersion = $this->get_versionnumber_by_stage('DataObjectAsPage', 'Stage', $this->ID);
		
		return ($latestPublishedVersion < $latestVersion);
	}
	
	/**
	 * Get the listing page to view this Event on (used in Link functions below)
	 */
	public function getListingPage(){
		
		$listingClass = $this->stat('listing_page_class');
		
		if(Controller::curr() instanceof $listingClass)
		{
			$listingPage = Controller::curr();
		}
		else
		{
			$listingPage = $listingClass::get()->First();
		}
		
		return $listingPage;		
	}
	
	/**
	 * Generate the link to this DataObject Item page
	 */
	public function Link($action = null)
	{
		//Hack for search results
		if($item = DataObjectAsPage::get()->byID($this->ID))
		{
			//Build link
			if($listingPage = $item->getListingPage())
			{
				return Controller::join_links($listingPage->Link(), 'show', $item->URLSegment, $action);
			}			
		}
	}
	
	/**
	 * Create an absolute link to the DOAP
	 *
	 * @param string $action Optional URL action to append
	 * @return string The absolute link
	 */
	public function AbsoluteLink($action = null)
	{
		if($listingPage = $this->getListingPage())
		{
			return Controller::join_links($listingPage->AbsoluteLink(), 'show', $this->URLSegment, $action);
		}
	}
	
	/**
	 * Return the correct linking mode, for use in menus
	 */
	public function LinkingMode()
    {
        //Check that we have a controller to work with and that it is a listing page
        if(($controller = Controller::Curr()) && (Controller::curr() instanceof $this->stat('listing_page_class')))
        {
            //check that the action is 'show' and that we have an item to work with
            if($controller->getAction() == 'show' && $item = $controller->getCurrentItem())
            {
                return ($item->ID == $this->ID) ? 'current' : 'link';
            }
        }
    }

	/**
	 * Set URLSegment to be unique on write
	 */
	public function onBeforeWrite()
	{
	    parent::onBeforeWrite();
		
		$defaults = $this->config()->defaults;

	    // If there is no URLSegment set, generate one from Title
	    if((!$this->URLSegment || $this->URLSegment == $defaults['URLSegment']) && $this->Title != $defaults['Title'])
	    {
	        $this->URLSegment = $this->generateURLSegment($this->Title);
	    } 
	    else if($this->isChanged('URLSegment')) 
	    {
	        // Make sure the URLSegment is valid for use in a URL
	        $segment = preg_replace('/[^A-Za-z0-9]+/','-',$this->URLSegment);
	        $segment = preg_replace('/-+/','-',$segment);

	        // If after sanitising there is no URLSegment, give it a reasonable default
	        if(!$segment) {
	            $segment = "item-$this->ID";
	        }
	        $this->URLSegment = $segment;
	    }

	    // Ensure that this object has a non-conflicting URLSegment value.
	    $count = 2;

		$URLSegment = $this->URLSegment;
		$ID = $this->ID;

	    while($this->LookForExistingURLSegment($URLSegment, $ID)) 
	    {     	
	        $URLSegment = preg_replace('/-[0-9]+$/', null, $URLSegment) . '-' . $count;
	        $count++;
	    }

		$this->URLSegment = $URLSegment;

	}

	/**
	 * Check if there is already a DOAP with this URLSegment
	 */
	public function LookForExistingURLSegment($URLSegment, $ID)
	{
	   	return DataObjectAsPage::get()->filter(array(
			'URLSegment' => $URLSegment,
			'ID' => $ID
		)->exists();
	}
	
	/**
	 * Generate a URL segment based on the title provided.
	 * 
	 * If {@link Extension}s wish to alter URL segment generation, they can do so by defining
	 * updateURLSegment(&$url, $title).  $url will be passed by reference and should be modified.
	 * $title will contain the title that was originally used as the source of this generated URL.
	 * This lets extensions either start from scratch, or incrementally modify the generated URL.
	 * 
	 * @param string $title Page title.
	 * @return string Generated url segment
	 */
	public function generateURLSegment($title)
	{
		$filter = URLSegmentFilter::create();
		$t = $filter->filter($title);
		
		// Fallback to generic page name if path is empty (= no valid, convertable characters)
		if(!$t || $t == '-' || $t == '-1') $t = "page-$this->ID";
		
		// Hook for extensions
		$this->extend('updateURLSegment', $t, $title);
		
		return $t;
	}
}
