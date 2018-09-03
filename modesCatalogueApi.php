<?php

# Class to present the museum catalogue online
require_once ('frontControllerApplication.php');
class modesCatalogueApi extends frontControllerApplication
{
	# Function to assign defaults additional to the general application defaults
	public function defaults ()
	{
		# Specify available arguments as defaults or as NULL (to represent a required argument)
		$defaults = array (
			'applicationName' => 'MODES catalogue API',
			'div' => 'modescatalogueapi',
			'administrators' => true,
			
			# Importing
			'importFiles' => array ('records'),	// e.g. array ('museum');
			
			# Table
			'table' => 'records',
			
			# Images
			'mainImageSize' => 450,
			'listingThumbnailSize' => 100,
			'listingThumbnailType' => 'gif',
			'articleImageClass' => false,
			'imageFilenameLiberalMatching' => true,	// Allow case-insensitive matches of image names
			
			'administratorEmail' => NULL,
			'multiplesDelimiter' => '|',
			
			# Pagination
			#!# This should be different for search vs listings - 250 is too many for a search
			'paginationRecordsPerPage' => 150,
			
			# Image data source (non-slash terminated)
			'imageStoreRoot' => NULL,
			
			# API
			'apiUsername' => 'guest',
			
			# Imports
			'importsSectionsMode' => true,
			
			# Search
			'availableGroupings' => array ('museum', 'art', 'archive', 'picturelibrary'),	// Each present as fields (column headings) in the spreadsheet
		);
		
		# Return the defaults
		return $defaults;
	}
	
	
	# Function to assign supported actions
	public function actions ()
	{
		# Define available tasks
		$actions = array (
			'home' => array (
				'description' => false,
				'url' => '',
				'tab' => 'Home',
			),
			'globalsearch' => array (
				'description' => false,
				'url' => '',
			),
			'contacts' => array (
				'description' => false,
				'url' => '',
			),
			'import' => array (
				'description' => false,
				'url' => 'import/',
				'tab' => 'Import',
				'icon' => 'database_refresh',
				'administrator' => true,
			),
			'apidocumentation' => array (
				'description' => 'API (HTTP)',
				'url' => 'api/',
				'tab' => 'API',
				'icon' => 'feed',
				'administrator' => true,
			),
			'feedback' => array (
				'description' => 'Feedback/contact form',
				'url' => 'feedback.html',
				'tab' => 'Feedback',
			),
		);
		
		# Return the actions
		return $actions;
	}


	# Function to get collection counts
	private function getCollectionCounts ()
	{
		# Get the number of pre-compiled distinct values, which may include multiple collection lines, e.g. '|KAM||BPA|'
		$query = "SELECT
				Collection,
				COUNT(*) AS total
			FROM {$this->settings['database']}.{$this->settings['table']}
			WHERE
				    Collection IS NOT NULL
				AND (Status IS NULL OR Status != 'R')
			GROUP BY Collection
			ORDER BY Collection
		;";
		$totals = $this->databaseConnection->getPairs ($query);
		
		# Tokenise the result, adding up the counts for each component
		$counts = array ();
		foreach ($totals as $string => $total) {
			$matches = preg_split ('/(\|+)/', $string, -1, PREG_SPLIT_NO_EMPTY);
			foreach ($matches as $match) {
				$counts[$match] = (isSet ($counts[$match]) ? $counts[$match] : 0) + $total;
			}
		}
		
		# Return the counts
		return $counts;
	}
	
	
	# Function to get a list of collections
	public function getCollectionsData ($baseUrl, $grouping = false)
	{
		# Determine prepared statement values
		$preparedStatementValues = array ();
		
		# Deal with the grouping value, e.g. 'museum' or a list like 'museum,art'
		$groupings = array ();
		if ($grouping) {
			if (preg_match ('/^([,a-z]+)$/D', $grouping)) {
				$groupingsRaw = explode (',', $grouping);
				foreach ($groupingsRaw as $index => $grouping) {
					$groupings[":grouping{$index}"] = $grouping;	// e.g. :grouping0 => 'museum', :grouping1 => 'art'
				}
				$preparedStatementValues += $groupings;
			}
		}
		
		# Determine whether to include suppressed items
		$includeSuppressed = (isSet ($_GET['includesuppressed']) && $_GET['includesuppressed'] == '1');
		
		# Get the collections
		#!# Get rid of url/baseUrl distinction
		$query = "SELECT
			*,
			CONCAT ('{$baseUrl}/', id, '/') AS url,
			CONCAT ('{$baseUrl}/', id) AS baseUrl
		FROM {$this->settings['database']}.collections
		WHERE
			1=1
			" . ($includeSuppressed ? '' : ' AND (suppressed != 1 OR suppressed IS NULL)') . "
			" . ($groupings ? ' AND grouping IN(' . implode (', ', array_keys ($groupings)) . ')' : '') . "
		ORDER BY collection
		;";
		$collections = $this->databaseConnection->getData ($query, "{$this->settings['database']}.collections", true, $preparedStatementValues);
		
		# Get counts
		$counts = $this->getCollectionCounts ();
		
		# Remove empty collections
		foreach ($collections as $key => $collection) {
			if (!isSet ($counts[$collection['collection']])) {
				unset ($collections[$key]);
			}
		}
		
		# Add counts in to the data
		foreach ($collections as $key => $collection) {
			$collections[$key]['count'] = $counts[$collection['collection']];
		}
		
		# Truncate introduction text if required
		#!# DATA CLEANUP: Truncate long introductory text
		$truncateToCharacters = 550;
		foreach ($collections as $key => $collection) {
			if (strlen ($collection['introductoryTextBrief']) > $truncateToCharacters) {
				$truncationExtension = ' ...';
				$truncateToCharacters = $truncateToCharacters - strlen ($truncationExtension);
				$collections[$key]['introductoryTextBrief'] = substr ($collection['introductoryTextBrief'], 0, $truncateToCharacters) . $truncationExtension;
			}
		}
		
		# Create a collection cover image
		foreach ($collections as $key => $collection) {
			$collections[$key]['collectionCoverImage'] = $this->collectionCoverImage ($collection['baseUrl'], $collection['title']);
		}
		
		# Default the about page tab text
		foreach ($collections as $key => $collection) {
			$collections[$key]['aboutPageTabText'] = ($collection['aboutPageTabText'] ? $collection['aboutPageTabText'] : 'About');
		}
		
		# Get the data update date
		$tableComment = $this->databaseConnection->getTableComment ($this->settings['database'], $this->settings['table']);
		preg_match ('|([0-9]{6})|', $tableComment, $matches);
		$timestamp = strtotime (date_format (date_create_from_format ('ymd', $matches[1]), 'Y-m-d') . ' 12:00:00');
		foreach ($collections as $key => $collection) {
			$collections[$key]['dataTimestamp'] = $timestamp;
			$collections[$key]['dataDateHumanReadable'] = date ('l, jS F Y', $timestamp);
		}
		
		# Return the data
		return $collections;
	}
	
	
	# Function to provide as the main image for a collection
	private function collectionCoverImage ($galleryBaseUrl, $title, $desiredBaseWidth = 100)
	{
		# Define the available sizes
		$sizes = array (
			100 => 'cover.jpg',
			225 => 'cover-large.jpg',
		);
		
		# Define the default file
		$defaultSize = key ($sizes);
		$galleryImage = $galleryBaseUrl . '/' . $sizes[$defaultSize];
		$baseWidth = $defaultSize;
		
		# Determine the filename for the desired size
		if (isSet ($sizes[$desiredBaseWidth])) {
			$desiredGalleryImage = $galleryBaseUrl . '/' . $sizes[$desiredBaseWidth];
			if (is_readable ($_SERVER['DOCUMENT_ROOT'] . $desiredGalleryImage)) {
				$galleryImage = $desiredGalleryImage;
				$baseWidth = $desiredBaseWidth;
			}
		}
		
		# Construct the HTML
		$html = '';
		if (is_readable ($_SERVER['DOCUMENT_ROOT'] . $galleryImage)) {
			list ($width, $height, $type, $attr) = getimagesize ($_SERVER['DOCUMENT_ROOT'] . $galleryImage);
			if ($width > $baseWidth || $height > $baseWidth) {
				$height = ceil (($width / $height) * $baseWidth);
				$width = $baseWidth;
			}
			
			# Compile the HTML
			$html = '<img src="' . $galleryImage . '" alt="Cover image" title="' . htmlspecialchars ($title) . '" width="' . $width . '" height="' . $height . '" />';
			
		} /* else {
			#!# Replace with link instead
			$galleryImage = "{$this->baseUrl}/images/spacer.gif";
			$width = $baseWidth;
			$height = $baseWidth;
		} */
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to create the front page
	public function home ()
	{
		# Welcome
		$html  = "\n<p>This section provides the API powering the collections catalogue.</p>";
		if (!$this->user) {
			$html .= "\n<p>Please login if you have rights.</p>";
		}
		
		# Show the HTML
		echo $html;
	}
	
	
	# Function to import the file, clearing any existing import
	public function import ()
	{
		# Define the import types
		$importTypes = array (
			'full' => 'FULL import (c. 2 mins)',
		);
		
		# Define the introduction HTML
		$fileCreationInstructionsHtml = '<p>Use the export facility in MODES, and save the ' . (count ($this->settings['importFiles']) == 1 ? 'file' : 'files') . ' somewhere on your computer. Note that this can take a while to create.</p>';
		
		# Run the import UI
		$this->importUi (array_keys ($this->settings['importFiles']), $importTypes, $fileCreationInstructionsHtml);
	}
	
	
	# Function to deal with record importing
	# Needs privileges: SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX
	public function doImport ($modesXmlExportFiles, $type_ignored, &$html)
	{
		# Start the HTML
		$html = '';
		
		# Determine the grouping and the filename
		reset ($modesXmlExportFiles);
		$grouping = key ($modesXmlExportFiles);		// i.e. first key
		$modesXmlExportFile = $modesXmlExportFiles[$grouping];	// i.e. first value
		
		# Get current tables
		$tables = $this->databaseConnection->getTables ($this->settings['database']);
		
		# Determine the table to use; if a grouping-specific table is present, use that, otherwise use the generic records table
		$table = (in_array ($grouping, $tables) ? $grouping : $this->settings['table']);
		
		# Archive off the previous data (if not already done on the current day)
		$this->archiveTable ($table, $tables);
		
		# Obtain the XPath definitions
		$xPaths = $this->csvToAssociativeArray ($this->settings['importFiles'][$grouping]);
		
		# xPath configuration; those which are pulled out (as listed below) are either used in indexing lists or checked when searching
		# https://msdn.microsoft.com/en-us/library/ms256086.aspx is a useful resource
		# http://www.xpathtester.com/ is a useful resource for testing xpaths
		# Good XPath tutorial and tester at www.zvon.org/xxl/XPathTutorial/General/examples.html
		// Status,Administration/Progress/Keyword was the original but <Progress><Keyword><Keyword> is being found
		// Status,(Administration/Progress//Keyword)[last()]
		
		#!# DATA CLEANUP: Truncate long introductory text
		// Title should be as follows when collection level records fixed
		// Title,Identification/Title
		// Title,(Identification//Title)[last()]
		
		# Delete records of this grouping from the table first
		$this->databaseConnection->delete ($this->settings['database'], $table, array ('grouping' => $grouping));
		
		#!# Migrate functions to using PHP 5.3 callback system: https://stackoverflow.com/a/3409450
		
		# Parse the XML records
		require_once ('xml.php');
		$result = xml::databaseChunking (
			$modesXmlExportFile,
			$credentials = $this->settings,	// Uses hostname, username, password
			$this->settings['database'],
			$table,
			$xpathRecordsRoot = '/Interchange/Object',
			$recordIdPath = 'ObjectIdentity/Number',
			$xPaths,
			$this->settings['multiplesDelimiter'],
			true,	/* Default */
			true,	/* documentToDataOrientatedXml - Default */
			300,	/* Default */
			$filter = (isSet ($this->settings['filter'][$grouping]) ? $this->settings['filter'][$grouping] : false)
		);
		
		# Add the grouping
		#!# Ideally this would work using an XPath "string('{$grouping}')" but that seems not to work
		$query = "UPDATE {$this->settings['database']}.{$table} SET grouping = '{$grouping}' WHERE grouping = '';";
		$this->databaseConnection->query ($query);
		
		# Add to the counter
		$recordsDone = $result;
		
		# Update the table comment to store the data date
		$tableComment = "Records (snapshot date: ?)";
		if (preg_match ('/20([0-9]{6})/', $modesXmlExportFile, $matches)) {		// Takes the export file in use; there may be a mix of data in the records
			$date = $matches[1];
			$tableComment = "Records (snapshot date: {$date})";
		}
		$result = $this->databaseConnection->setTableComment ($this->settings['database'], $table, $tableComment);
		
		# Perform fixups for the main record grouping
		#!# Needs generalising
		if (in_array ($grouping, array ('biographies', 'expeditions'))) {
			$this->importBiographiesExpeditionsFixups ($grouping, $table);
		} else {
			$this->importMainRecordsFixups ($grouping, $table);
		}
		
		# Confirm the result
		if ($recordsDone) {
			$html .= "\n<p><img src=\"/images/icons/tick.png\" class=\"icon\" alt=\"\" /> <strong>Success: " . number_format ($recordsDone) . ' records refreshed / imported into the database.</strong></p>';
			$html .= "\n<p>Max memory used: " . round (memory_get_peak_usage (true) / 1048576, 2) . ' megabytes.</p>';
			$html .= "\n<p><a href=\"{$this->baseUrl}/import/\">Reset this page.</a></p>";
		}
		
		# Signal success
		return true;
	}
	
	
	# Function to archive a previous data table (if not already done on the current day)
	private function archiveTable ($table, $tables)
	{
		# Determine the proposed archive table name, as <table>_<dateYmd>, e.g. records_180801
		$archiveTable = $table . '_' . date ('Ymd');
		
		# Do not archive if the table already exists
		if (in_array ($archiveTable, $tables)) {return;}
		
		# Archive the data, by creating the table and copying the data in
		$sql = "CREATE TABLE {$archiveTable} LIKE {$table};";
		$this->databaseConnection->execute ($sql);
		$sql = "INSERT INTO {$archiveTable} SELECT * FROM {$table};";
		$this->databaseConnection->execute ($sql);
	}
	
	
	# Function to perform fixups for the biographies / expeditions records
	private function importBiographiesExpeditionsFixups ($grouping, $table)
	{
		# Set the ID to the bracketed part of the name, lower-cased; see: https://stackoverflow.com/questions/8072402/looking-to-extract-data-between-parentheses-in-a-string-via-mysql
		$query = "UPDATE {$this->settings['database']}.{$table} SET id = LOWER( SUBSTR(name,INSTR(name,'(') + 1, INSTR(name,')') - INSTR(name,'(') - 1) );";
		$this->databaseConnection->query ($query);
		
		/*
		# Set the collection value
		#!# This needs to come from the database
		$query = "UPDATE {$this->settings['database']}.{$table} SET collection = 'vsii';";
		$this->databaseConnection->query ($query);
		*/
	}
	
	
	# Function to perform fixups for the main record grouping
	private function importMainRecordsFixups ($grouping, $table)
	{
		# Normalise the Collection column such that all collections are surrounded by |...| even if only one
		$query = "UPDATE {$this->settings['database']}.{$table} SET Collection = CONCAT('{$this->settings['multiplesDelimiter']}', Collection, '{$this->settings['multiplesDelimiter']}') WHERE Collection IS NOT NULL;";
		$this->databaseConnection->query ($query);
		
		# Create a title for museum records which have no actual title (i.e. things that aren't artistic), using the ObjectType as the nearest equivalent
		$query = "UPDATE {$this->settings['database']}.{$table} SET Title = ObjectType WHERE Title IS NULL AND grouping = 'museum';";
		$this->databaseConnection->query ($query);
		
		# Create two indexes for sortability reasons; see https://lists.mysql.com/mysql/213354
		$query = "UPDATE
			{$this->settings['database']}.{$table}
			SET
				/* VARCHAR field */							id_prefix = SUBSTRING_INDEX(id, ' ', 1),
				/* INT field, so a-b will be discarded */	id_suffix = REPLACE(SUBSTRING(SUBSTRING_INDEX(id, ' ', 2), LENGTH(SUBSTRING_INDEX(id, ' ', 2 -1)) + 1), ' ', '')	/* See: https://blog.fedecarg.com/2009/02/22/mysql-split-string-function/ */
			;";
		$this->databaseConnection->query ($query);
		
		# Add collections-level entries into the collections table, first clearing out any present entries from a previous import
		$constraints = array ('source' => 'modes', 'grouping' => $grouping);
		$this->databaseConnection->delete ($this->settings['database'], 'collections', $constraints);
		#!# Status clause needs to be documented
		$query = "INSERT INTO {$this->settings['database']}.collections
			(
				SELECT
					REPLACE (LOWER (id), ' ', '') AS id,
					id AS collection,
					'modes' AS source,
					grouping,
					NULL AS suppressed,
					Title AS title,
					id AS abbreviation,
					BriefDescription AS introductoryTextBrief,
					Description AS introductoryText,
					NULL AS aboutPageHtml,
					NULL AS aboutPageTabText,
					NULL AS contactsPageHtml,
					NULL AS contactsPageEmail,
					NULL AS sponsorNotice,
					NULL AS categoriesTable,
					1 AS disableCategories,
					1 AS disableMaterials,
					1 AS disableArtists,
						/* Convert the imagesSubfolder reference from Windows to UNIX: prepend the path, convert to unix, chop off the Windows equivalent of the path, and add the thumbnails directory */
					REPLACE (REPLACE (CONCAT (PhotographFilename, '/'), '\\\\', '/'), 'A:/spripictures/', '/thumbnails/') AS imagesSubfolder
				FROM {$this->settings['database']}.{$table}
				WHERE
					    Type = 'collection'
					AND (`Status` != 'R' OR `Status` IS NULL)
				ORDER BY title
			)
		;";
		if (!$this->databaseConnection->query ($query)) {
			application::dumpData ($this->databaseConnection->error ());
		}
		
		# Delete collections-level entries for this grouping, whatever their status, from the main records table
		$constraints = array ('Type' => 'collection', 'grouping' => $grouping);
		$this->databaseConnection->delete ($this->settings['database'], $table, $constraints);
	}
	
	
	# Function to convert a CSV block to an associative array
	private function csvToAssociativeArray ($string)
	{
		# Determine the Xpaths
		$array = explode ("\n", trim ($string));
		$list = array ();
		foreach ($array as $line) {
			list ($key, $value) = explode (',', trim ($line), 2);
			if ($value == 'NULL') {continue;}	// Skip fields marked as 'NULL', i.e. do not apply to this kind of record
			$list[$key] = $value;
		}
		
		# Return the list
		return $list;
	}
	
	
	# Static version of the effect of the materialsSplitter; ideally this would not be required, but materialsSplitter() contains $this-> items
	# This is the CALLBACK version, i.e. used by xml::databaseChunking and is used for the SQL field materials
	public static function materialsSplitterStatic ($materialsObjectsArray)
	{
		# Turn each material group into either "a > b" or a
		$materials = array ();
		foreach ($materialsObjectsArray as $index => $materialsObject) {
			// This next line doesn't work: for some reason a load of extra suddenly appears
			//$result = $materialsObject->xpath ('//Keyword[not(Keyword)]');	// i.e. Lowest level 'Keyword' instances
			// if ($result = $materialsObject->xpath ('//Note')) {continue;}	// Refuse to process if there is a note field (i.e. unclean records)
			
			# Cast object as array
			$materialsArray = (array) $materialsObject;
			
			# Skip if no materials
			if (!$materialsArray || !isSet ($materialsArray['Keyword']) || empty ($materialsArray['Keyword'])) {continue;}
			
			# Add to the list
			#!# DATA CLEANUP: Once the data doesn't have any cases of Note within Keyword, replace this block with $materials[] = (is_array ($materialsArray['Keyword']) ? implode (' > ', $materialsArray['Keyword']) : $materialsArray['Keyword']);
			if (is_string ($materialsArray['Keyword'])) {
				$materials[] = $materialsArray['Keyword'];
			} else {
				$items = array ();
				foreach ($materialsArray['Keyword'] as $material) {
					if (is_string ($material)) {	// This should ensure that nested objects (due to Keyword/Note data problem) are ignored
						$items[] = $material;
					}
				}
				if ($items) {
					$materials[] = implode (' > ', $items);
				}
			}
		}
		
		# End if no items
		if (!$materials) {
			return '';
		}
		
		# Combine each material group with ||
		$string = '|' . implode ('||', $materials) . '|';
		
		# Return the assembled string
		return $string;
	}
	
	
	# Function to split records with a delimiter in a field
	private function delimiterSplitting ($data, $fieldname, $multiplesDelimiter, $removeTrailingBracketedComponent = false)
	{
		# Perform splitting
		$finalised = array ();
		foreach ($data as $index => $attributes) {
			
			# Remove the | and | from the start
			if ((substr ($attributes[$fieldname], 0, 1) == '|') && (substr ($attributes[$fieldname], -1, 1) == '|')) {
				$attributes[$fieldname] = substr ($attributes[$fieldname], 1, -1);
			}
			
			# Get the items
			$items = explode ($multiplesDelimiter, $attributes[$fieldname]);
			
			# Compile the master list of materials
			foreach ($items as $item) {
				
				# Skip first/last (and empty) items
				if (empty ($item)) {continue;}
				
				# Convert characters
				$item = str_replace (' & ', ($fieldname == 'category' ? ', ' : ' > '), $item);
				
				# Drop any trailing bracketed section if required
				if ($removeTrailingBracketedComponent) {
					$item = $this->materialNameMainPart ($item);
				}
				
				# Remove things ending with pattern (a) or (a, b)
				#!# TODO
				
				# Add (or create) the count for each item in the master list
				$finalised[$item][$fieldname] = $item;
				$finalised[$item]['count'] = $attributes['count'] + (isSet ($finalised[$item]['count']) ? $finalised[$item]['count'] : 0);
			}
		}
		
		# Sort
		ksort ($finalised);
		
		# Return the result
		return $finalised;
	}
	
	
	# Function to get the main part of a material name (i.e. exclude a bracketed suffix)
	# The model data structure is: array (Keyword => value1, Keyword2 => value2, Note => notevalue) becoming: "value1 > value2 (notevalue)"
	private function materialNameMainPart ($data, $asLink = false)
	{
		# Get the 'keyword' part out
		if (is_array ($data) && isSet ($data['Keyword'])) {
			$material = $data['Keyword'];
		} else {
			$material = $data;
		}
		
		# Convert hierarchical records
		$material = $this->convertHierarchical ($material);
		
		# Split the name
		$material = explode (' (', $material, 2);
		
		# If as a link, link the first part only
		if ($asLink) {
			$linkStart = "<a href=\"{$this->gallery['baseUrl']}/materials/" . str_replace ('?', '%3F', urlencode ($material[0])) . '">';
			$html  = $linkStart . ucfirst (htmlspecialchars ($material[0])) . '</a>';
			if (count ($material) > 1) {
				array_shift ($material);
				$html .= ' (' . implode (' (', $material);
			}
			
		} else {
			
			# If not as a link, return the text straight away
			$html  = trim ($material[0]);
		}
		
		# Get the 'parts' part out and add that on
		$parts = ((is_array ($data) && isSet ($data['Note'])) ? trim (htmlspecialchars ($data['Note'])) : false);
		if ($parts) {
			$html .= " ({$parts})";
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to convert a value arranged as array(a,b,c) into (str) a > b > c
	private function convertHierarchical ($value, $ucFirst = false)
	{
		# Define the separator
		$separator = ' > ';
		
		# Convert to an array
		$items = array ();
		if (is_array ($value)) {
			foreach ($value as $item) {
				
				# Deal with items are are still an array, converting two or more items to "first (second)" or "first (second; third)";
				if (is_array ($item)) {
					$item = array_values ($item);
					$itemMain = $item[0];
					array_shift ($item);
					$itemAttributes = implode ('; ', $item);
					$item = "{$itemMain} ({$itemAttributes})";
				}
				
				# Add to the list
				$items[] = ($ucFirst ? ucfirst ($item) : $item);
			}
			
			# Implode with > separator
			$value = implode ($separator, $items);
		}
		
		# Return the value, possibly unchanged
		return $value;
	}
	
	
	# Function to get biography data
	private function getBiographyData ($baseUrl, $collection, $id = false, $fields = array (), $random = false, $forceId = false)
	{
		# Determine which database function to use
		$databaseFunction = ($id ? 'selectOne' : 'select');
		
		# Add limitations
		$conditions = array ();
		if ($id) {
			$conditions['id'] = $id;
		}
		if ($collection) {
			$conditions['collection'] = $collection;
		}
		
		# Randomise, if required
		$orderBy = 'name';
		$limit = false;
		if ($random) {
			$orderBy = 'RAND()';
			$limit = $random;
		}
		
		# Add support for forcing a specific ID to be at the start
		if ($forceId) {
			#!# Doesn't cope yet with values in quotes
			$orderBy = "FIELD(id, '{$forceId}') DESC, " . $orderBy;	// See: https://stackoverflow.com/questions/14104055/ordering-by-specific-field-value-first
		}
		
		# Get the data or end
		#!# Should be application-wide in main FCA settings
		$this->databaseConnection->setStrictWhere (true);
		if (!$data = $this->databaseConnection->{$databaseFunction} ($this->settings['database'], 'biographies', $conditions, $fields, $associative = true, $orderBy, $limit)) {
			return array ();
		}
		
		# Decorate each entry
		$expeditionsRaw = $this->getExpeditionData (false, false, false, array ('id', 'name'));
		if ($id) {
			$data = $this->decorateBiography ($data, $baseUrl, $expeditionsRaw);
		} else {
			foreach ($data as $key => $record) {
				$data[$key] = $this->decorateBiography ($record, $baseUrl, $expeditionsRaw);
			}
		}
		
		# Return the data
		return $data;
	}
	
	
	# Function to decorate biography data
	private function decorateBiography ($data, $baseUrl, $expeditionsRaw)
	{
		# Add image reference
		//$data['image'] = NULL;
		
		# Explode multiple value types
		if (isSet ($data['nationality'])) {
			$data['nationality'] = $this->unpipeList ($data['nationality']);
		}
		if (isSet ($data['awards'])) {
			$data['awards'] = $this->unpipeList ($data['awards']);
		}
		
		if (isSet ($data['expeditions'])) {
			
			# Unpack the record for metadata extraction
			$json = json_encode (simplexml_load_string ($data['data']));
			$metadata = json_decode ($json, true);
			
			# Get expedition URLs
			$expeditions = array ();
			foreach ($expeditionsRaw as $id => $expedition) {
				$name = $expedition['name'];
				$expeditions[$name] = $baseUrlExpeditions . $expedition['url'];
			}
			
			# Extract expeditions
			$data['expeditions'] = array ();
			foreach ($metadata['Association'] as $event) {
				#!# Check for $event['Event'][EventType] == 'Antarctic expedition' ?
				if (!isSet ($event['Event'])) {continue;}
				$data['expeditions'][] = array (
					'title' => $event['Event']['EventName'],
					'date' => $event['Event']['Date']['DateBegin'] . '-' . $event['Event']['Date']['DateEnd'],
					'link' => $expeditions[$event['Event']['EventName']],
					'image' => NULL,
				);
			}
		}
		
		# Create a URL
		$data['link'] = $this->urlFromId ($data['id'], $baseUrl);
		
		# Return the data
		return $data;
		
	}
	
	
	# Function to create a URL from an ID
	private function urlFromId ($id, $baseUrl)
	{
		return $baseUrl . '/' . str_replace (' ', '_', $id) . '/';
	}
	
	
	# Function to get expedition data
	private function getExpeditionData ($baseUrl, $collection, $id = false, $fields = array ())
	{
		# Determine which database function to use
		$databaseFunction = ($id ? 'selectOne' : 'select');
		
		# Add limitations
		$conditions = array ();
		if ($id) {
			$conditions['id'] = $id;
		}
		if ($collection) {
			$conditions['collection'] = $collection;
		}
		
		# Get the data or end
		#!# Should be application-wide in main FCA settings
		$this->databaseConnection->setStrictWhere (true);
		if (!$data = $this->databaseConnection->{$databaseFunction} ($this->settings['database'], 'expeditions', $conditions, $fields, $associative = true, $orderBy = 'name')) {
			return array ();
		}
		
		# Create a URL for each entry
		if ($id) {
			$data['url'] = $this->urlFromId ($data['id'], $baseUrl);
		} else {
			foreach ($data as $key => $record) {
				$data[$key]['url'] = $this->urlFromId ($record['id'], $baseUrl);
			}
		}
		
		# Return the data
		return $data;
	}
	
	
	# Function to get record categories
	private function getCategoriesData ($collection, $includeUnclassified = true)
	{
		# Assemble the query
		$query = "
			SELECT
				Category as category,
				COUNT(id) as count
			FROM {$this->settings['database']}.{$this->settings['table']}
			WHERE (Status != 'R' OR Status IS NULL)
			AND Collection LIKE :collectionId
			GROUP BY `Category`;";
		$preparedStatementValues = array ('collectionId' => "%|{$collection['collection']}|%");
		
		#!# Migrate to getPairs, but delimiterSplitting will need to be reworked to the new data structure
		
		# Get the data or end
		if (!$data = $this->databaseConnection->getData ($query, false, true, $preparedStatementValues)) {return array ();}
		
		# Deal with multiple attribute delimiter splitting
		$data = $this->delimiterSplitting ($data, 'category', $this->settings['multiplesDelimiter']);
		
		# Get the AAT lookups and add the 'Unidentified object' type
		$categories = $this->databaseConnection->select ($this->settings['database'], $collection['categoriesTable']);
		if ($includeUnclassified) {$categories['Unidentified object']['classification'] = 'No classification available';}
		
		# Merge in the category data; note this can't be done using LEFT OUTER JOIN as the delimiter splitting makes that impossible
		foreach ($data as $index => $item) {
			$data[$index]['classification'] = (isSet ($categories[$item['category']]) ? $categories[$item['category']]['classification'] : false);
		}
		
		# Remove the unidentified object, or move it to the end if necessary
		if (isSet ($data['Unidentified object'])) {
			$unidentified = $data['Unidentified object'];
			unset ($data['Unidentified object']);
			if ($includeUnclassified) {
				$data['Unidentified object'] = $unidentified;
			}
		}
		
		# Return the categories
		return $data;
	}
	
	
	# Function to get record categories
	private function getGroup ($collectionId, $field)
	{
		# Assemble the query
		$query = "
			SELECT
				{$field},
				COUNT(id) as count
			FROM {$this->settings['database']}.{$this->settings['table']}
			WHERE
				    Collection LIKE :collectionId
				AND (Status != 'R' OR Status IS NULL)
				AND {$field} != ''
			GROUP BY {$field}
			ORDER BY id;";
		$preparedStatementValues = array ('collectionId' => "%|{$collectionId}|%");
		
		#!# Migrate to getPairs, but delimiterSplitting will need to be reworked to the new data structure
		
		# Get the data
		if (!$data = $this->databaseConnection->getData ($query, false, true, $preparedStatementValues)) {return array ();}
		
		# Deal with multiple attribute delimiter splitting, removing any trailing bracketed component
		$data = $this->delimiterSplitting ($data, $field, $this->settings['multiplesDelimiter'], $removeTrailingBracketedComponent = true);
		
		# Sort by name
		ksort ($data);
		
		# Rearrange the items
		foreach ($data as $key => $attributes) {
			$data[$key] = $attributes['count'];
		}
		
		# Return the categories
		return $data;
	}
	
	
	
	/* API calls */
	
	
	# API documentation page
	public function apidocumentationIntroduction ()
	{
		# Create and return the HTML
		return $html = '
			<p>When prompted, the username is <strong>guest</strong> and there is no password. (The links below have the guest@ username embedded in them, which will work if the link is opened in an incognito window.)</p>
		';
	}
	
	
	# API call to get a list of collections
	public function apiCall_collections ()
	{
		# Start an array of data to be returned
		$data = array ();
		
		# Determine the baseUrl
		$baseUrl = (isSet ($_GET['baseUrl']) ? $_GET['baseUrl'] : false);
		
		# Optionally allow a grouping (or groupings, separated by comma) to be specified, e.g. 'museum' or a list like 'museum,art'
		$grouping = (isSet ($_GET['grouping']) ? $_GET['grouping'] : false);
		
		# Get the collections
		$collections = $this->getCollectionsData ($baseUrl, $grouping);
		
		# Compute total number of records
		$totalRecords = 0;
		foreach ($collections as $key => $collection) {
			$totalRecords += $collection['count'];
		}
		
		# Limit to fields if required
		$fields = (isSet ($_GET['fields']) && strlen ($_GET['fields']) ? explode (',', $_GET['fields']) : array ());
		if ($fields) {
			foreach ($collections as $id => $collection) {
				$collections[$id] = application::arrayFields ($collection, $fields);
			}
		}
		
		# Compile stats
		$data['summary'] = array (
			'totalCollections' => count ($collections),
			'totalRecords' => $totalRecords,
		);
		
		# Register the collections data
		$data['collections'] = $collections;
		
		# Return the data, which will be JSON-encoded
		return $data;
	}
	
	
	# API documentation for collections
	public function apiCallDocumentation_collections ()
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>This API call fetches the list of collections:</p>";
		
		# Example
		$html .= "\n" . '<h3 id="example">Example</h3>';
		$exampleUrl = $_SERVER['_SITE_URL'] . $this->baseUrl . '/api/collections?grouping=picturelibrary';
		$html .= "\n" . '<pre class="code">' . '<a href="' . htmlspecialchars (str_replace ('://', '://guest@', $exampleUrl)) . '">' . htmlspecialchars ($exampleUrl) . '</a>' . '</pre>';
		$html .= "\n" . '<p>Result:</p>';
		$html .= "\n" . '<pre class="code">' . htmlspecialchars ('
{
    "summary": {
        "totalCollections": 22,
        "totalRecords": 19706
    },
    "collections": {
        "modern": {
            "id": "modern",
            "grouping": "picturelibrary",
            "suppressed": null,
            "collection": "Modern Photograph Collection",
            "title": "Modern Photograph Collection",
            "abbreviation": "",
            "introductoryTextBrief": "These are modern photographs taken during field research in the Arctic and Antarctic.",
            "introductoryText": "<p>These are modern photographs taken during field research in the Arctic and Antarctic.</p>",
            "aboutPageHtml": null,
            "aboutPageTabText": "About",
            "contactsPageHtml": null,
            "contactsPageEmail": null,
            "sponsorNotice": "",
            "categoriesTable": "",
            "disableArtists": null,
            "disableCategories": null,
            "disableMaterials": null,
            "imagesSubfolder": "/Picture Library Collections/",
            "source": "database",
            "url": "/picturelibrary/catalogue/modern/",
            "count": "121",
            "baseUrl": "/picturelibrary/catalogue/modern",
            "collectionCoverImage": "<img src=\"/picturelibrary/catalogue/modern/cover.jpg\" alt=\"Cover image\" title=\"Modern Photograph Collection\" width=\"100\" height=\"100\" class=\"diagram\" />"
        },
        "are1902-04": {
            "id": "are1902-04",
            "title": "Antarctic Relief Expeditions 1902-04",
            "introductoryTextBrief": "Photographs chronicling the second relief expedition, 1903-04, of the sailing ships \'Morning\' and \'Terra Nova\'.",
            "abbreviation": "ARE 1902-04",
            "source": "modes",
            "grouping": "picturelibrary",
            "collection": "ARE 1902-04",
            "sponsorNotice": "",
            "aboutPageHtml": "",
            "categoriesTable": "",
            "disableArtists": "",
            "disableCategories": "",
            "disableMaterials": "",
            "aboutPageTabText": "About",
            "introductoryText": "The Antarctic Relief Expeditions consisted of two voyages undertaken to aid the British National Antarctic Expedition.",
            "imagesSubfolder": "/thumbnails/Picture Library Collections/FreezeFrame/Antarctic_Relief_Expeditions_1902-04/",
            "url": "/picturelibrary/catalogue/are1902-04/",
            "count": "93",
            "baseUrl": "/picturelibrary/catalogue/are1902-04",
            "collectionCoverImage": "<img src=\"/picturelibrary/catalogue/are1902-04/cover.jpg\" alt=\"Cover image\" title=\"Antarctic Relief Expeditions 1902-04\" width=\"100\" height=\"75\" class=\"diagram\" />"
        },
        
        ...
    }
}') . '</pre>';
		
		# Request parameters
		$html .= "\n" . '<h3 id="parametersrequired">Request parameters - required</h3>';
		$html .= "\n" . '<p>None.</p>';
		$html .= "\n" . '<h3 id="parametersoptional">Request parameters - optional</h3>';
		$html .= "\n" . '<dl class="code">
			<dt><strong>grouping</strong> <em>string, comma-separated a-z values</em></dt>
				<dd>Filter to the specified grouping or groupings, e.g. \'museum\' or \'museum,art\'.<br />Currently-supported grouping values are: <tt>museum</tt>, <tt>art</tt>, <tt>picturelibrary</tt>.</dd>
		</dl>';
		
		# Response
		$html .= "\n" . '<h3 id="response">Response</h3>';
		$html .= "\n" . '<p>JSON object as above.</p>';
		
		# Error response
		$html .= "\n" . '<h3 id="error">Error response</h3>';
		$html .= "\n" . '<p>JSON object containing an error key and a text string.</p>';
		$html .= "\n" . '<p>Example error (text string will vary):</p>';
		$html .= "\n" . '<pre class="code">
{
    "error": "<span class=\"warning\">Unspecified error.</span>"
}</pre>';
		
		# Return the HTML
		return $html;
	}
	
	
	# API call to get details of a single collection
	public function apiCall_collection ()
	{
		# Determine the baseUrl
		$baseUrl = (isSet ($_GET['baseUrl']) ? $_GET['baseUrl'] : false);
		
		# Get the collections
		$collections = $this->getCollectionsData ($baseUrl);
		
		# Require a collection ID to be specified
		$id = (isSet ($_GET['id']) ? $_GET['id'] : false);
		if (!$id || !isSet ($collections[$id])) {
			return array ('error' => 'Invalid collection ID.');
		}
		
		# Obtain the data
		$data = $collections[$id];
		
		# Return the data, which will be JSON-encoded
		return $data;
	}
	
	
	# API documentation for collection
	public function apiCallDocumentation_collection ()
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>This API call fetches details of a single collection:</p>";
		
		# Example
		$html .= "\n" . '<h3 id="example">Example</h3>';
		$exampleUrl = $_SERVER['_SITE_URL'] . $this->baseUrl . '/api/collection?id=modern';
		$html .= "\n" . '<pre class="code">' . '<a href="' . htmlspecialchars (str_replace ('://', '://guest@', $exampleUrl)) . '">' . htmlspecialchars ($exampleUrl) . '</a>' . '</pre>';
		$html .= "\n" . '<p>Result:</p>';
		$html .= "\n" . '<pre class="code">' . htmlspecialchars ('
{
    "id": "modern",
    "grouping": "picturelibrary",
    "suppressed": null,
    "collection": "Modern Photograph Collection",
    "title": "Modern Photograph Collection",
    "abbreviation": "",
    "introductoryTextBrief": "These are modern photographs taken during field research in the Arctic and Antarctic.",
    "introductoryText": "<p>These are modern photographs taken during field research in the Arctic and Antarctic.</p>",
    "aboutPageHtml": null,
    "aboutPageTabText": "About",
    "contactsPageHtml": null,
    "contactsPageEmail": null,
    "sponsorNotice": "",
    "categoriesTable": "",
    "disableArtists": null,
    "disableCategories": null,
    "disableMaterials": null,
    "imagesSubfolder": "/Picture Library Collections/",
    "source": "database",
    "url": "/picturelibrary/catalogue/modern/",
    "count": "121",
    "baseUrl": "/picturelibrary/catalogue/modern",
    "collectionCoverImage": "<img src=\"/picturelibrary/catalogue/modern/cover.jpg\" alt=\"Cover image\" title=\"Modern Photograph Collection\" width=\"100\" height=\"100\" />"
}') . '</pre>';
		
		# Request parameters
		$html .= "\n" . '<h3 id="parametersrequired">Request parameters - required</h3>';
		$html .= "\n" . '<dl class="code">
			<dt><strong>id</strong> <em>string</em></dt>
				<dd>The collection identifier</dd>
		</dl>';
		$html .= "\n" . '<h3 id="parametersoptional">Request parameters - optional</h3>';
		$html .= "\n" . '<p>None.</p>';
		
		# Response
		$html .= "\n" . '<h3 id="response">Response</h3>';
		$html .= "\n" . '<p>JSON object as above.</p>';
		
		# Error response
		$html .= "\n" . '<h3 id="error">Error response</h3>';
		$html .= "\n" . '<p>JSON object containing an error key and a text string.</p>';
		$html .= "\n" . '<p>Example error (text string will vary):</p>';
		$html .= "\n" . '<pre class="code">
{
    "error": "<span class=\"warning\">Invalid collection ID.</span>"
}</pre>';
		
		# Return the HTML
		return $html;
	}
	
	
	# API function to obtain a set of articles
	public function apiCall_articles ()
	{
		# Determine the baseUrl
		$baseUrl = (isSet ($_GET['baseUrl']) ? $_GET['baseUrl'] : false);
		
		# Determine the articles prefix
		$baseUrlArticles = (isSet ($_GET['baseUrlArticles']) ? $_GET['baseUrlArticles'] : false);
		
		# Filter to a specified collection if required
		$collection = (isSet ($_GET['collection']) && strlen ($_GET['collection']) ? $_GET['collection'] : false);
		
		# Specify a search phrase if required
		$search = false;
		if (isSet ($_GET['search']) && strlen ($_GET['search'])) {
			if (strlen ($_GET['search']) < 3) {
				return array ('error' => 'The search phrase must be at least 3 characters.');
			}
			$search = $_GET['search'];
		};
		
		# Ensure either a collection or a search`	 has been specified
		if (!$collection && !$search) {
			return array ('error' => 'At least a collection or a search phrase must be specified.');
		}
		
		# Filter by category if specified
		$category = (isSet ($_GET['category']) && strlen ($_GET['category']) ? $_GET['category'] : false);
		
		# Filter by material if specified
		$material = (isSet ($_GET['material']) && strlen ($_GET['material']) ? $_GET['material'] : false);
		
		# Filter by artist if specified
		$artist = (isSet ($_GET['artist']) && strlen ($_GET['artist']) ? $_GET['artist'] : false);
		
		# Specify whether each article is required to have an image
		$requireImages = (isSet ($_GET['requireimages']) && ($_GET['requireimages'] == '1'));
		
		# Obtain a specified number of articles selected at random
		$random = (isSet ($_GET['random']) && ctype_digit ($_GET['random']) ? $_GET['random'] : false);
		
		# Get the current page
		$page = ((isSet ($_GET['page']) && ctype_digit ($_GET['page'])) ? $_GET['page'] : 1);
		
		# Get the data
		ini_set ('display_errors', false);	// #!# Ensure any errors do not disrupt API output
		require_once ('articleModel.php');
		$articleModel = new articleModel ($this, $this->settings, $this->databaseConnection);
		$data = $articleModel->getArticlesData ($baseUrl, $collection, $search, $category, $material, $artist, $requireImages, $random, $page);
		
		# Construct URLs
		foreach ($data['articles'] as $id => $article) {
			$data['articles'][$id]['link'] = $this->urlFromId ($id, $baseUrlArticles);
		}
		
		# Return the data, which will be JSON-encoded
		return $data;
	}
	
	
	# API documentation for article
	public function apiCallDocumentation_articles ()
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>This API call fetches details of multiple articles:</p>";
		
		# Example
		$html .= "\n" . '<h3 id="example">Example</h3>';
		$exampleUrl = $_SERVER['_SITE_URL'] . $this->baseUrl . '/api/articles?collection=armc&category=dolls';
		$html .= "\n" . '<pre class="code">' . '<a href="' . htmlspecialchars (str_replace ('://', '://guest@', $exampleUrl)) . '">' . htmlspecialchars ($exampleUrl) . '</a>' . '</pre>';
		$html .= "\n" . '<p>Result:</p>';
		$html .= "\n" . '<pre class="code">' . htmlspecialchars ('
{
    "pagination": {
        "count": 150,
        "total": 571,
        "page": 1,
        "totalPages": 4
    },
    "relatedTerms": [],
    "articles": {
        "N: 1": {
            "id": "N: 1",
            "status": null,
            "collections": [
                "armc"
            ],
            "title": "Harness",
            "briefDescription": "Harness, dog. Labrador Inuit. Labrador, Newfoundland, Canada, before April, 1938.",
            "images": [
                "N1.TIF"
            ],
            "link": "/N:_1/"
        },
        ...
    }
}') . '</pre>';
		
		# Request parameters
		$html .= "\n" . '<h3 id="parametersrequired">Request parameters - required</h3>';
		$html .= "\n" . '<dl class="code">
			<p>At least one of <strong>collection</strong> or <strong>search</strong> (documented below) must be specified.</p>
		</dl>';
		$html .= "\n" . '<h3 id="parametersoptional">Request parameters - optional</h3>';

		$html .= "\n" . '<dl class="code">
			<dt><strong>collection</strong> <em>string</em></dt>
				<dd>The collection identifier</dd>
			<dt><strong>search</strong> <em>string</em></dt>
				<dd>A search string, which will be checked as a free text search against various fields</dd>
			<dt><strong>category</strong> <em>string</em></dt>
				<dd>The category to filter on</dd>
			<dt><strong>material</strong> <em>string</em></dt>
				<dd>The material to filter on</dd>
			<dt><strong>artist</strong> <em>string</em></dt>
				<dd>The artist to filter on</dd>
			<dt><strong>requireimages</strong> <em>integer 1|0</em> default <em>0</em></dt>
				<dd>Whether only articles with images should be included</dd>
			<dt><strong>random</strong> <em>integer</em></dt>
				<dd>Return only the specified number of images, ordered randomly</dd>
			<dt><strong>page</strong> <em>integer</em></dt>
				<dd>Pagination page; a maximum of ' . $this->settings['paginationRecordsPerPage'] . ' records are returned per page. If an invalid page is specified, an error is returned. The pagination field at the head of the record (on a valid page, e.g. page 1) shows the number of pages available.</dd>
		</dl>';
		
		# Response
		$html .= "\n" . '<h3 id="response">Response</h3>';
		$html .= "\n" . '<p>JSON object as above.</p>';
		$html .= "\n" . '<p>The format of each field in each article is the same as the <a href="#article">article API</a>, but each article is cut down to a smaller set of fields.</p>';
		$html .= "\n" . '<p>The related terms section will only be filled when using a search term and there are related terms for that search term.</p>';
		
		# Error response
		$html .= "\n" . '<h3 id="error">Error response</h3>';
		$html .= "\n" . '<p>JSON object containing an error key and a text string.</p>';
		$html .= "\n" . '<p>Example error (text string will vary):</p>';
		$html .= "\n" . '<pre class="code">
{
    "error": "<span class=\"warning\">There is no such collection.</span>"
}</pre>
<pre class="code">
{
    "error": "<span class=\"warning\">The search phrase must be at least 3 characters.</span>"
}</pre>
<pre class="code">
{
    "error": "<span class=\"warning\">At least a collection or a search phrase must be specified.</span>"
}</pre>';
		
		# Return the HTML
		return $html;
	}
	
	
	# API function to obtain an article
	public function apiCall_article ()
	{
		# Require an article ID to be specified
		if (!isSet ($_GET['id']) || !strlen ($_GET['id'])) {
			return array ('error' => 'No article ID was supplied.');
		}
		
		# Determine whether to add an explicit collection context; otherwise the first is used
		$collectionId = (isSet ($_GET['collection']) && strlen ($_GET['collection']) ? $_GET['collection'] : false);
		
		# Determine whether to include the XML
		$includeXml = (isSet ($_GET['includeXml']) && ($_GET['includeXml'] == '1'));
		
		# Determine the expeditions baseUrl
		$baseUrlExpeditions = (isSet ($_GET['baseUrlExpeditions']) ? $_GET['baseUrlExpeditions'] : false);
		
		# Determine the biographies prefix
		$baseUrlPeople = (isSet ($_GET['baseUrlPeople']) ? $_GET['baseUrlPeople'] : false);
		
		# Parse the record data
		ini_set ('display_errors', false);	// #!# Ensure any errors do not disrupt API output
		require_once ('articleModel.php');
		$articleModel = new articleModel ($this, $this->settings, $this->databaseConnection);
		$data = $articleModel->getOne ($_GET['id'], $collectionId, $includeXml);
		
		# Get expedition URLs and images
		#!# Consider whether this block should logically be within articleModel
		if (isSet ($data['associatedExpedition'])) {
			if ($data['associatedExpedition']) {
				
				# Get the raw data
				$expeditionsRaw = $this->getExpeditionData (false, false, false, array ('id', 'name'));
				$expeditions = array ();
				foreach ($expeditionsRaw as $id => $expedition) {
					$name = $expedition['name'];
					$expeditions[$name] = $baseUrlExpeditions . $expedition['url'];
				}
				
				# Attach the URL if present
				foreach ($data['associatedExpedition'] as $index => $exhibition) {
					$name = $exhibition['name'];
					$data['associatedExpedition'][$index]['url'] = (isSet ($expeditions[$name]) ? $expeditions[$name] : NULL);
				}
				
				# Attach the image
				#!# Not yet implemented
				foreach ($data['associatedExpedition'] as $index => $exhibition) {
					$data['associatedExpedition'][$index]['image'] = NULL;
				}
			}
		}
		
		# Get people URLs and images
		#!# Consider whether this block should logically be within articleModel
		if (isSet ($data['associatedPerson'])) {
			if ($data['associatedPerson']) {
				
				# Attach the URL if present
				foreach ($data['associatedPerson'] as $index => $person) {
					$data['associatedPerson'][$index]['link'] = $this->urlFromId ($person['name'], $baseUrlPeople);
				}
				
				# Attach the image
				#!# Not yet implemented
				foreach ($data['associatedPerson'] as $index => $person) {
					$data['associatedPerson'][$index]['image'] = NULL;
				}
			}
		}
		
		# Return the data, which will be JSON-encoded
		return $data;
	}
	
	
	# API documentation for article
	public function apiCallDocumentation_article ()
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>This API call fetches details of an article:</p>";
		
		# Example
		$html .= "\n" . '<h3 id="example">Example</h3>';
		$exampleUrl = $_SERVER['_SITE_URL'] . $this->baseUrl . '/api/article?id=N:+76a-b&collection=?';
		$html .= "\n" . '<pre class="code">' . '<a href="' . htmlspecialchars (str_replace ('://', '://guest@', $exampleUrl)) . '">' . htmlspecialchars ($exampleUrl) . '</a>' . '</pre>';
		$html .= "\n" . '<p>Result:</p>';
		$html .= "\n" . '<pre class="code">' . htmlspecialchars ('
{
    "id": "N: 76a-b",
    "type": "object",
    "status": null,
    "collections": [
        "armc"
    ],
    "context": "ARMC",
    "title": "Sledge, model; doll",
    "briefDescription": "Sledge, model, wood (a); doll (b). Tunumiit (East Greenlanders). Tasiilaq (Ammassalik), Kalaallit Nunaata Tunua (East Greenland).",
    "objectType": "Sledge, model; doll",
    "medium": null,
    "category": "|Sleds||Models||Dolls|",
    "artist": null,
    "classifiedNames": {
        "Current cultural affiliation": "Tunumiit",
        "Former cultural affiliation": "East Greenlanders",
        "Cultural subgroup": "ammassalimmiut",
        "Current place name": "Kalaallit Nunaata Tunu > Tasiilaq",
        "Former place name": "East Greenland > Ammassalik (Angmassalik)",
        "Keyword(s) (AAT)": "Dolls"
    },
    "fieldCollection": [],
    "materials": [],
    "numberOfItems": 2,
    "note": "Thalbitzer 1912: 367",
    "fullDescription": "a) Sledge (a) has wood runners which are vertical at the rear and curve upwards and taper towards straight front.",
    "relatedRecords": [],
    "dimensions": {
        "a": {
            "width": "97mm",
            "height": "107mm",
            "length": "242mm"
        },
        "b": {
            "width": "76mm",
            "height": "177mm"
        }
    },
    "placeName": null,
    "images": [
        "N_76_a_b.TIF"
    ],
    "imageBy": null,
    "imageColour": null,
    "navigationIds": {
        "current": "N: 76a-b",
        "previous": "N: 55",
        "next": "N: 77a",
        "start": "N: 1",
        "end": "Z: 313a-c"
    },
    "navigationIdsAdditional": {
        "categories": {
            "Dolls": {
                "current": "N: 76a-b",
                "previous": null,
                "next": "N: 244a",
                "start": "N: 76a-b",
                "end": "Z: 254b"
            },
            "Models": {
                "current": "N: 76a-b",
                "previous": "N: 55",
                "next": "N: 77a",
                "start": "N: 55",
                "end": "Y: 2014/8a-i"
            },
            "Sleds": {
                "current": "N: 76a-b",
                "previous": "N: 55",
                "next": "N: 77a",
                "start": "N: 55",
                "end": "Y: 2005/1/13"
            }
        }
    }
}') . '</pre>';
		
		# Request parameters
		$html .= "\n" . '<h3 id="parametersrequired">Request parameters - required</h3>';
		$html .= "\n" . '<dl class="code">
			<dt><strong>id</strong> <em>string</em></dt>
				<dd>The article identifier</dd>
		</dl>';
		$html .= "\n" . '<h3 id="parametersoptional">Request parameters - optional</h3>';
		$html .= "\n" . '<dl class="code">
			<dt><strong>collection</strong> <em>string</em></dt>
				<dd>The collection identifier, which adds contextual information (e.g. IDs of next/previous items in same collection); the special value "?" (signifying auto) can also be used to generate context automatically from the first collection listed in the record if present</dd>
			<dt><strong>baseUrlExpeditions</strong> <em>string</em></dt>
				<dd>A string which will be prefixed to the URL value for each expedition</dd>
			<dt><strong>baseUrlPeople</strong> <em>string</em></dt>
				<dd>A string which will be prefixed to the URL value for each person</dd>
		</dl>';
		
		# Response
		$html .= "\n" . '<h3 id="response">Response</h3>';
		$html .= "\n" . '<p>JSON object as above.</p>';
		
		# Error response
		$html .= "\n" . '<h3 id="error">Error response</h3>';
		$html .= "\n" . '<p>JSON object containing an error key and a text string.</p>';
		$html .= "\n" . '<p>Example error (text string will vary):</p>';
		$html .= "\n" . '<pre class="code">
{
    "error": "<span class=\"warning\">There is no such record ID.</span>"
}</pre>';
		
		# Return the HTML
		return $html;
	}
	
	
	# API function to obtain a set of biographies
	public function apiCall_biographies ()
	{
		# Determine the baseUrl
		$baseUrl = (isSet ($_GET['baseUrl']) ? $_GET['baseUrl'] : false);
		
		# Filter to a specified collection if required
		$collection = (isSet ($_GET['collection']) && strlen ($_GET['collection']) ? $_GET['collection'] : false);
		
		# Obtain a specified number of articles selected at random
		$random = (isSet ($_GET['random']) && ctype_digit ($_GET['random']) ? $_GET['random'] : false);
		
		# Obtain a specified number of articles selected at random
		$forceId = (isSet ($_GET['forceid']) ? $_GET['forceid'] : false);
		
		# Get the data
		$fields = array ('id', 'name');
		$data = $this->getBiographyData ($baseUrl, $collection, false, $fields, $random, $forceId);
		
		# Return the data, which will be JSON-encoded
		return $data;
	}
	
	
	# API documentation for biographies
	public function apiCallDocumentation_biographies ()
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>This API call fetches details of multiple biographies:</p>";
		
		# Example
		$html .= "\n" . '<h3 id="example">Example</h3>';
		$exampleUrl = $_SERVER['_SITE_URL'] . $this->baseUrl . '/api/biographies?collection=VSII&baseUrl=/museum/people';
		$html .= "\n" . '<pre class="code">' . '<a href="' . htmlspecialchars (str_replace ('://', '://guest@', $exampleUrl)) . '">' . htmlspecialchars ($exampleUrl) . '</a>' . '</pre>';
		$html .= "\n" . '<p>Result:</p>';
		$html .= "\n" . '<pre class="code">' . htmlspecialchars ('
{
    "Abrahams, Frederick G.": {
        "id": "Abrahams, Frederick G.",
        "name": "Abrahams, Frederick G.",
        "date": "1885-unknown",
        "alias": null,
        "rank": null,
        "nationality": [
            "British"
        ],
        "awards": [],
        "about": "Frederick G. Abrahams was born [...]",
        "expeditions": [],
        "url": "/museum/people/Abrahams,_Frederick_G./"
    },
    "Adams, Jameson Boyd": {
        "id": "Adams, Jameson Boyd",
        "name": "Adams, Jameson Boyd",
        "date": "1880-1962",
        "alias": null,
        "rank": "Commander (Royal Naval Reserve)",
        "nationality": [
            "British"
        ],
        "awards": [
            "Distinguish Service Order",
            "Croix de Guerre",
            "Knight of the British Empire (1948)"
        ],
        "about": "Jameson Boyd Adams was born [...]",
        "expeditions": [],
        "url": "/museum/people/Adams,_Jameson_Boyd/"
    },
    ...
}') . '</pre>';
		
		# Request parameters
		$html .= "\n" . '<h3 id="parametersrequired">Request parameters - required</h3>';
		$html .= "\n" . '<p>None.</p>';
		
		$html .= "\n" . '<h3 id="parametersoptional">Request parameters - optional</h3>';
		$html .= "\n<p>Parameter values which perform a filtering operation are case-sensitive.</p>";
		$html .= "\n" . '<dl class="code">
			<dt><strong>collection</strong> <em>string</em></dt>
				<dd>The collection identifier</dd>
			<dt><strong>baseUrl</strong> <em>string</em></dt>
				<dd>A string which will be prefixed to the URL value</dd>
			<dt><strong>random</strong> <em>integer</em></dt>
				<dd>Return only the specified number of images, ordered randomly</dd>
			<dt><strong>forceid</strong> <em>string</em></dt>
				<dd>Require this specific ID to be the first item</dd>
		</dl>';
		
		# Response
		$html .= "\n" . '<h3 id="response">Response</h3>';
		$html .= "\n" . '<p>JSON object as above.</p>';
		$html .= "\n" . '<p>The format of each field in each article is the same as the <a href="#biography">biography API</a>, but each biography is cut down to a smaller set of fields.</p>';
		
		# Error response
		$html .= "\n" . '<h3 id="error">Error response</h3>';
		$html .= "\n" . '<p>JSON object containing an error key and a text string.</p>';
		$html .= "\n" . '<p>Example error (text string will vary):</p>';
		$html .= "\n" . '<pre class="code">
{
    "error": "<span class=\"warning\">Unidentified error.</span>"
}</pre>';
		
		# Return the HTML
		return $html;
	}
	
	
	# API function to obtain a biography
	public function apiCall_biography ()
	{
		# Require an article ID to be specified
		if (!isSet ($_GET['id']) || !strlen ($_GET['id'])) {
			return array ('error' => 'No article ID was supplied.');
		}
		
		# Determine the baseUrl
		$baseUrl = (isSet ($_GET['baseUrl']) ? $_GET['baseUrl'] : false);
		
		# Determine the expeditions baseUrl
		$baseUrlExpeditions = (isSet ($_GET['baseUrlExpeditions']) ? $_GET['baseUrlExpeditions'] : false);
		
		# Determine the 'Unknown person' image URL
		$nullPersonUrl = (isSet ($_GET['nullPersonUrl']) ? $_GET['nullPersonUrl'] : false);
		
		# Get the record data
		$fields = array ('id', 'name', 'date', 'alias', 'rank', 'nationality', 'awards', 'about', 'data', 'collection', 'image');
		if (!$data = $this->getBiographyData ($baseUrl, $collection, $_GET['id'], $fields)) {
			return array ('error' => 'There is no such record ID.');
		}
		
		# Return the data, which will be JSON-encoded
		return $data;
	}
	
	
	# Helper function to explode a pipe/double-pipe -separated list
	private function unpipeList ($string)
	{
		if (!$string) {return array ();}
		$list = explode ('|', $string);
		foreach ($list as $index => $item) {
			if (!strlen ($item)) {unset ($list[$index]);}
		}
		return array_values ($list);
	}
	
	
	# API documentation for biography
	public function apiCallDocumentation_biography ()
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>This API call fetches details of a biography:</p>";
		
		# Example
		$html .= "\n" . '<h3 id="example">Example</h3>';
		$exampleUrl = $_SERVER['_SITE_URL'] . $this->baseUrl . '/api/biography?id=' . urlencode ('Abrahams, Frederick G.') . '&baseUrl=/museum/people&baseUrlExpeditions=/museum/expeditions';
		$html .= "\n" . '<pre class="code">' . '<a href="' . htmlspecialchars (str_replace ('://', '://guest@', $exampleUrl)) . '">' . htmlspecialchars ($exampleUrl) . '</a>' . '</pre>';
		$html .= "\n" . '<p>Result:</p>';
		$html .= "\n" . '<pre class="code">' . htmlspecialchars ('
{
    "id": "Shackleton, Ernest Henry",
    "name": "Shackleton, Ernest Henry",
    "date": "1874-1922",
    "alias": null,
    "rank": null,
    "nationality": [
        "British"
    ],
    "awards": [
        "Royal Geographical Society Special Gold Medal (1909)",
        "Knighthood",
        [...]
    ],
    "about": "Ernest Henry Shackleton was born on [...]",
    "data": "<Object><ObjectIdentity><Number>Shackleton, Ernest Henry</Number></ObjectIdentity> [...]",
    "collection": "VSII",
    "url": "/museum/people/Shackleton,_Ernest_Henry/",
    "expeditions": [
        {
            "title": "Shackleton-Rowett Antarctic Expedition 1921-22 (Quest)",
            "date": "1921-1922",
            "link": "/museum/expeditions/quest/",
            "image": null
        },
        {
            "title": "Imperial Trans-Antarctic Expedition 1914-16 (Endurance)",
            "date": "1914-1916",
            "link": "/museum/expeditions/endurance/",
            "image": null
        },
        [...]
    ]
}') . '</pre>';
		
		# Request parameters
		$html .= "\n" . '<h3 id="parametersrequired">Request parameters - required</h3>';
		$html .= "\n<p>Parameter values which perform a filtering operation are case-sensitive.</p>";
		$html .= "\n" . '<dl class="code">
			<dt><strong>id</strong> <em>string</em></dt>
				<dd>The article identifier</dd>
		</dl>';
		$html .= "\n" . '<h3 id="parametersoptional">Request parameters - optional</h3>';
		$html .= "\n" . '<dl class="code">
			<dt><strong>baseUrl</strong> <em>string</em></dt>
				<dd>A string which will be prefixed to the URL value</dd>
			<dt><strong>baseUrlExpeditions</strong> <em>string</em></dt>
				<dd>A string which will be prefixed to the URL value for each expedition</dd>
		</dl>';
		
		# Response
		$html .= "\n" . '<h3 id="response">Response</h3>';
		$html .= "\n" . '<p>JSON object as above.</p>';
		
		# Error response
		$html .= "\n" . '<h3 id="error">Error response</h3>';
		$html .= "\n" . '<p>JSON object containing an error key and a text string.</p>';
		$html .= "\n" . '<p>Example error (text string will vary):</p>';
		$html .= "\n" . '<pre class="code">
{
    "error": "<span class=\"warning\">There is no such record ID.</span>"
}</pre>';
		
		# Return the HTML
		return $html;
	}
	
	
	# API function to obtain a set of expeditions
	public function apiCall_expeditions ()
	{
		# Determine the baseUrl
		$baseUrl = (isSet ($_GET['baseUrl']) ? $_GET['baseUrl'] : false);
		
		# Filter to a specified collection if required
		$collection = (isSet ($_GET['collection']) && strlen ($_GET['collection']) ? $_GET['collection'] : false);
		
		# Get the data
		$fields = array ('id', 'name', 'date', 'leader', 'about');
		$data = $this->getExpeditionData ($baseUrl, $collection, false, $fields);
		
		# Return the data, which will be JSON-encoded
		return $data;
	}
	
	
	# API documentation for expeditions
	public function apiCallDocumentation_expeditions ()
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>This API call fetches details of multiple expeditions:</p>";
		
		# Example
		$html .= "\n" . '<h3 id="example">Example</h3>';
		$exampleUrl = $_SERVER['_SITE_URL'] . $this->baseUrl . '/api/expeditions?collection=VSII&baseUrl=/museum';
		$html .= "\n" . '<pre class="code">' . '<a href="' . htmlspecialchars (str_replace ('://', '://guest@', $exampleUrl)) . '">' . htmlspecialchars ($exampleUrl) . '</a>' . '</pre>';
		$html .= "\n" . '<p>Result:</p>';
		$html .= "\n" . '<pre class="code">' . htmlspecialchars ('
{
    "nimrod": {
        "id": "nimrod",
        "name": "British Antarctic Expedition 1907-09 (Nimrod)",
        "date": "1907",
        "leader": "",
        "about": "Shackleton\'s first expedition to the Antarctic as leader had [...]",
        "url": "/museum/nimrod/"
    },
    "endurance": {
        "id": "endurance",
        "name": "Imperial Trans-Antarctic Expedition 1914-16 (Endurance)",
        "date": "1914",
        "leader": "Shackleton, Ernest Henry",
        "about": "After Roald Amundsen successfully reached the South Pole [...]",
        "url": "/museum/endurance/"
    },
    
    ...
}') . '</pre>';
		
		# Request parameters
		$html .= "\n" . '<h3 id="parametersrequired">Request parameters - required</h3>';
		$html .= "\n" . '<p>None.</p>';
		
		$html .= "\n" . '<h3 id="parametersoptional">Request parameters - optional</h3>';
		$html .= "\n<p>Parameter values which perform a filtering operation are case-sensitive.</p>";
		$html .= "\n" . '<dl class="code">
			<dt><strong>collection</strong> <em>string</em></dt>
				<dd>The collection identifier</dd>
			<dt><strong>baseUrl</strong> <em>string</em></dt>
				<dd>A string which will be prefixed to the URL value</dd>
		</dl>';
		
		# Response
		$html .= "\n" . '<h3 id="response">Response</h3>';
		$html .= "\n" . '<p>JSON object as above.</p>';
		
		# Error response
		$html .= "\n" . '<h3 id="error">Error response</h3>';
		$html .= "\n" . '<p>JSON object containing an error key and a text string.</p>';
		$html .= "\n" . '<p>Example error (text string will vary):</p>';
		$html .= "\n" . '<pre class="code">
{
    "error": "<span class=\"warning\">Unidentified error.</span>"
}</pre>';
		
		# Return the HTML
		return $html;
	}
	
	
	# API function to obtain a expedition
	public function apiCall_expedition ()
	{
		# Require an article ID to be specified
		if (!isSet ($_GET['id']) || !strlen ($_GET['id'])) {
			return array ('error' => 'No article ID was supplied.');
		}
		
		# Determine the baseUrl
		$baseUrl = (isSet ($_GET['baseUrl']) ? $_GET['baseUrl'] : false);
		
		# Determine the biographies prefix
		$baseUrlPeople = (isSet ($_GET['baseUrlPeople']) ? $_GET['baseUrlPeople'] : false);
		
		# Get the record data
		$fields = array ('id', 'name', 'date', 'leader', 'about', 'data', 'collection');
		if (!$data = $this->getExpeditionData ($baseUrl, $collection, $_GET['id'], $fields)) {
			return array ('error' => 'There is no such record ID.');
		}
		
		# Unpack the record for metadata extraction
		$json = json_encode (simplexml_load_string ($data['data']));
		$metadata = json_decode ($json, true);
		
		# Extract people
		$data['people'] = array ();
		foreach ($metadata['Association']['Person'] as $person) {
			$data['people'][] = array (
				'name' => $person['PersonName'],
				'role' => $person['Role'],
				'link' => $this->urlFromId ($person['PersonName'], $baseUrlPeople),
				'image' => NULL,
			);
		}
		
		# Return the data, which will be JSON-encoded
		return $data;
	}
	
	
	# API documentation for expedition
	public function apiCallDocumentation_expedition ()
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>This API call fetches details of an expedition, including main details of any associated people:</p>";
		
		# Example
		$html .= "\n" . '<h3 id="example">Example</h3>';
		$exampleUrl = $_SERVER['_SITE_URL'] . $this->baseUrl . '/api/expedition?id=nimrod&baseUrl=/museum/expeditions&baseUrlPeople=/museum/biographies';
		$html .= "\n" . '<pre class="code">' . '<a href="' . htmlspecialchars (str_replace ('://', '://guest@', $exampleUrl)) . '">' . htmlspecialchars ($exampleUrl) . '</a>' . '</pre>';
		$html .= "\n" . '<p>Result:</p>';
		$html .= "\n" . '<pre class="code">' . htmlspecialchars ('
{
    "id": "nimrod",
    "name": "British Antarctic Expedition 1907-09 (Nimrod)",
    "date": "1907",
    "leader": "",
    "about": "Shackleton\'s first expedition to the Antarctic as leader had [...]",
    "data": "<Object><ObjectIdentity><Number>British Antarctic Expedition 1907-09 (Nimrod)</Number></ObjectIdentity>...</Object>",
    "collection": "VSII",
    "url": "/museum/expeditions/nimrod/",
    "people": [
        {
            "name": "Shackleton, Ernest Henry",
            "role": "Expedition leader",
            "link": "/museum/biographies/Shackleton,_Ernest_Henry/",
            "image": null
        },
        {
            "name": "Abrahams, Frederick G.",
            "role": "Ship party: able seaman",
            "link": "/museum/biographies/Abrahams,_Frederick_G./",
            "image": null
        },
        ...
    ]
}') . '</pre>';
		
		# Request parameters
		$html .= "\n" . '<h3 id="parametersrequired">Request parameters - required</h3>';
		$html .= "\n<p>Parameter values which perform a filtering operation are case-sensitive.</p>";
		$html .= "\n" . '<dl class="code">
			<dt><strong>id</strong> <em>string</em></dt>
				<dd>The article identifier</dd>
		</dl>';
		$html .= "\n" . '<h3 id="parametersoptional">Request parameters - optional</h3>';
		$html .= "\n" . '<dl class="code">
			<dt><strong>baseUrl</strong> <em>string</em></dt>
				<dd>A string which will be prefixed to the URL value</dd>
			<dt><strong>baseUrlPeople</strong> <em>string</em></dt>
				<dd>A string which will be prefixed to the URL value for each person</dd>
		</dl>';
		
		# Response
		$html .= "\n" . '<h3 id="response">Response</h3>';
		$html .= "\n" . '<p>JSON object as above.</p>';
		
		# Error response
		$html .= "\n" . '<h3 id="error">Error response</h3>';
		$html .= "\n" . '<p>JSON object containing an error key and a text string.</p>';
		$html .= "\n" . '<p>Example error (text string will vary):</p>';
		$html .= "\n" . '<pre class="code">
{
    "error": "<span class=\"warning\">There is no such record ID.</span>"
}</pre>';
		
		# Return the HTML
		return $html;
	}
	
	
	# API function to obtain the list of categories featured in a collection
	public function apiCall_categories ()
	{
		# Determine the baseUrl
		$baseUrl = (isSet ($_GET['baseUrl']) ? $_GET['baseUrl'] : false);
		
		# Require an article ID to be specified
		if (!isSet ($_GET['collection']) || !strlen ($_GET['collection'])) {
			return array ('error' => 'No collection ID was supplied.');
		}
		$collectionId = $_GET['collection'];
		
		# Get the collections
		$collections = $this->getCollectionsData ($baseUrl);
		
		# Validate the collection ID
		if (!isSet ($collections[$collectionId])) {
			return array ('error' => 'There is no such collection.');
		}
		
		# End if disabled
		if ($collections[$collectionId]['disableCategories']) {return array ();}
		
		# Determine whether to include the unclassified value
		$includeUnclassified = true;
		if (isSet ($_GET['includeUnclassified']) && ($_GET['includeUnclassified'] == '0')) {
			$includeUnclassified = false;
		}
		
		# Get the data
		$data = $this->getCategoriesData ($collections[$collectionId], $includeUnclassified);
		
		# Return the data, which will be JSON-encoded
		return $data;
	}
	
	
	# API documentation for categories
	public function apiCallDocumentation_categories ()
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>This API call fetches the list of categories featured within a specified collection, and shows the number of record instances for each category.</p>";
		
		# Example
		$html .= "\n" . '<h3 id="example">Example</h3>';
		$exampleUrl = $_SERVER['_SITE_URL'] . $this->baseUrl . '/api/categories?collection=armc';
		$html .= "\n" . '<pre class="code">' . '<a href="' . htmlspecialchars (str_replace ('://', '://guest@', $exampleUrl)) . '">' . htmlspecialchars ($exampleUrl) . '</a>' . '</pre>';
		$html .= "\n" . '<p>Result:</p>';
		$html .= "\n" . '<pre class="code">' . htmlspecialchars ('
{
    "Animal equipment": {
        "category": "Animal equipment",
        "count": 1,
        "classification": "Objects facet & Furnishings and Equipment & Tools and Equipment & Equipment & Animal equipment "
    },
    "Arrows": {
        "category": "Arrows",
        "count": 20,
        "classification": "Objects facet & Furnishings and Equipment & Weapons and Ammunition & weapons & <projectile weapons> & <projectile weapons with nonexplosive propellant> & <projectiles with nonexplosive propellant> & arrows"
    },
    "Bags": {
        "category": "Bags",
        "count": 13,
        "classification": "Objects facet & Furnishings and Equipment & Containers & containers & <containers by form> & bags"
    },
    ...
}') . '</pre>';
		
		# Request parameters
		$html .= "\n" . '<h3 id="parametersrequired">Request parameters - required</h3>';
		$html .= "\n" . '<dl class="code">
			<dt><strong>collection</strong> <em>string</em></dt>
				<dd>The collection identifier</dd>
		</dl>';
		$html .= "\n" . '<h3 id="parametersoptional">Request parameters - optional</h3>';
		$html .= "\n" . '<dl class="code">
			<dt><strong>includeUnclassified</strong> <em>integer 1|0</em> default <em>1</em></dt>
				<dd>Whether to include any entry enumerating unclassified items</dd>
		</dl>';
		
		# Response
		$html .= "\n" . '<h3 id="response">Response</h3>';
		$html .= "\n" . '<p>JSON object as above.</p>';
		$html .= "\n" . '<p>If no categories, an empty array object will be returned.</p>';
		
		# Error response
		$html .= "\n" . '<h3 id="error">Error response</h3>';
		$html .= "\n" . '<p>JSON object containing an error key and a text string.</p>';
		$html .= "\n" . '<p>Example error (text string will vary):</p>';
		$html .= "\n" . '<pre class="code">
{
    "error": "<span class=\"warning\">There is no such collection.</span>"
}</pre>';
		
		# Return the HTML
		return $html;
	}
	
	
	# API function to obtain the list of materials featured in a collection
	public function apiCall_materials ()
	{
		# Determine the baseUrl
		$baseUrl = (isSet ($_GET['baseUrl']) ? $_GET['baseUrl'] : false);
		
		# Require an article ID to be specified
		if (!isSet ($_GET['collection']) || !strlen ($_GET['collection'])) {
			return array ('error' => 'No collection ID was supplied.');
		}
		$collectionId = $_GET['collection'];
		
		# Get the collections
		$collections = $this->getCollectionsData ($baseUrl);
		
		# Validate the collection ID
		if (!isSet ($collections[$collectionId])) {
			return array ('error' => 'There is no such collection.');
		}
		
		# End if disabled
		if ($collections[$collectionId]['disableMaterials']) {return array ();}
		
		# Get the data
		$data = $this->getGroup ($collections[$collectionId]['collection'], 'Material');
		
		# Return the data, which will be JSON-encoded
		return $data;
	}
	
	
	# API documentation for materials
	public function apiCallDocumentation_materials ()
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>This API call fetches the list of materials featured within a specified collection, and shows the number of record instances for each material.</p>";
		
		# Example
		$html .= "\n" . '<h3 id="example">Example</h3>';
		$exampleUrl = $_SERVER['_SITE_URL'] . $this->baseUrl . '/api/materials?collection=armc';
		$html .= "\n" . '<pre class="code">' . '<a href="' . htmlspecialchars (str_replace ('://', '://guest@', $exampleUrl)) . '">' . htmlspecialchars ($exampleUrl) . '</a>' . '</pre>';
		$html .= "\n" . '<p>Result:</p>';
		$html .= "\n" . '<pre class="code">' . htmlspecialchars ('
{
    "antler": 3,
    "antler > caribou": 1,
    "antler ?": 6,
    "baleen > whale": 5,
    ...
}') . '</pre>';
		
		# Request parameters
		$html .= "\n" . '<h3 id="parametersrequired">Request parameters - required</h3>';
		$html .= "\n" . '<dl class="code">
			<dt><strong>collection</strong> <em>string</em></dt>
				<dd>The collection identifier</dd>
		</dl>';
		$html .= "\n" . '<h3 id="parametersoptional">Request parameters - optional</h3>';
		$html .= "\n" . '<p>None.</p>';
		
		# Response
		$html .= "\n" . '<h3 id="response">Response</h3>';
		$html .= "\n" . '<p>JSON object as above.</p>';
		$html .= "\n" . '<p>If no materials, an empty array object will be returned.</p>';
		
		# Error response
		$html .= "\n" . '<h3 id="error">Error response</h3>';
		$html .= "\n" . '<p>JSON object containing an error key and a text string.</p>';
		$html .= "\n" . '<p>Example error (text string will vary):</p>';
		$html .= "\n" . '<pre class="code">
{
    "error": "<span class=\"warning\">There is no such collection.</span>"
}</pre>';
		
		# Return the HTML
		return $html;
	}
	
	
	# API function to obtain the list of artists featured in a collection
	public function apiCall_artists ()
	{
		# Determine the baseUrl
		$baseUrl = (isSet ($_GET['baseUrl']) ? $_GET['baseUrl'] : false);
		
		# Require an article ID to be specified
		if (!isSet ($_GET['collection']) || !strlen ($_GET['collection'])) {
			return array ('error' => 'No collection ID was supplied.');
		}
		$collectionId = $_GET['collection'];
		
		# Get the collections
		$collections = $this->getCollectionsData ($baseUrl);
		
		# Validate the collection ID
		if (!isSet ($collections[$collectionId])) {
			return array ('error' => 'There is no such collection.');
		}
		
		# End if disabled
		if ($collections[$collectionId]['disableArtists']) {return array ();}
		
		# Get the data
		$data = $this->getGroup ($collections[$collectionId]['collection'], 'Artist');
		
		# Return the data, which will be JSON-encoded
		return $data;
	}
	
	
	# API documentation for artists
	public function apiCallDocumentation_artists ()
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>This API call fetches the list of artists featured within a specified collection, and shows the number of record instances for each artist.</p>";
		
		# Example
		$html .= "\n" . '<h3 id="example">Example</h3>';
		$exampleUrl = $_SERVER['_SITE_URL'] . $this->baseUrl . '/api/artists?collection=polarart';
		$html .= "\n" . '<pre class="code">' . '<a href="' . htmlspecialchars (str_replace ('://', '://guest@', $exampleUrl)) . '">' . htmlspecialchars ($exampleUrl) . '</a>' . '</pre>';
		$html .= "\n" . '<p>Result:</p>';
		$html .= "\n" . '<pre class="code">' . htmlspecialchars ('
{
    "Adams, Edward": 104,
    "Back, George": 54,
    "Baguley, Raymond M.": 1,
    "Baston, Thomas": 1,
    ...
}') . '</pre>';
		
		# Request parameters
		$html .= "\n" . '<h3 id="parametersrequired">Request parameters - required</h3>';
		$html .= "\n" . '<dl class="code">
			<dt><strong>collection</strong> <em>string</em></dt>
				<dd>The collection identifier</dd>
		</dl>';
		$html .= "\n" . '<h3 id="parametersoptional">Request parameters - optional</h3>';
		$html .= "\n" . '<p>None.</p>';
		
		# Response
		$html .= "\n" . '<h3 id="response">Response</h3>';
		$html .= "\n" . '<p>JSON object as above.</p>';
		$html .= "\n" . '<p>If no artists, an empty array object will be returned.</p>';
		
		# Error response
		$html .= "\n" . '<h3 id="error">Error response</h3>';
		$html .= "\n" . '<p>JSON object containing an error key and a text string.</p>';
		$html .= "\n" . '<p>Example error (text string will vary):</p>';
		$html .= "\n" . '<pre class="code">
{
    "error": "<span class=\"warning\">There is no such collection.</span>"
}</pre>';
		
		# Return the HTML
		return $html;
	}
}

?>
