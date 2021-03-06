<?php
/**
 * @package     Joomla.Libraries
 * @subpackage  Helper
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die;

/**
 * Tags helper class, provides methods to perform various tasks relevant
 * tagging of content.
 *
 * @package     Joomla.Libraries
 * @subpackage  Helper
 * @since       3.1
 */
class JHelperTags
{
	/**
	 * Method to add or update tags associated with an item. Generally used as a postSaveHook.
	 *
	 * @param   integer  $id       The id (primary key) of the item to be tagged.
	 * @param   string   $prefix   Dot separated string with the option and view for a url and type alias.
	 * @param   boolean  $isNew    Flag indicating this item is new.
	 * @param   integer  $item     Value of the primary key in the core_content table
	 * @param   array    $tags     Array of tags to be applied.
	 * @param   boolean  $replace  Flag indicating if all exising tags should be replaced
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function tagItem($id, $prefix, $isNew, $item, $tags = array(), $replace = true)
	{
		// Pre-process tags for adding new ones
		if (is_array($tags) && !empty($tags))
		{
			// If we want to keep old tags we need to make sure to add them to the array
			if (!$replace && !$isNew)
			{
				// Check for exising tags
				$existingTags = $this->getItemTags($prefix, $id);

				if (!empty($existingTags))
				{
					$existingTagList = '';

					foreach ($existingTags as $tag)
					{
						$tags[] = $tag->tag_id;
					}
					$tags = array_unique($tags, SORT_STRING);
				}
			}

			// We will use the tags table to store them
			JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_tags/tables');
			$tagTable = JTable::getInstance('Tag', 'TagsTable');

			foreach ($tags as $key => $tag)
			{
				// Currently a new tag is a non-numeric
				if (!is_numeric($tag))
				{
					// Unset the tag to avoid trying to insert a wrong value
					unset($tags[$key]);

					// Remove the #new# prefix that identifies new tags
					$tagText = str_replace('#new#', '', $tag);

					// Clear old data if exist
					$tagTable->reset();

					// Try to load the selected tag
					if ($tagTable->load(array('title' => $tagText)))
					{
						$tags[] = $tagTable->id;
					}
					else
					{
						// Prepare tag data
						$tagTable->id = 0;
						$tagTable->title = $tagText;
						$tagTable->published = 1;

						// $tagTable->language = property_exists ($item, 'language') ? $item->language : '*';
						$tagTable->language = '*';
						$tagTable->access = 1;

						// Make this item a child of the root tag
						$tagTable->setLocation($tagTable->getRootId(), 'last-child');

						// Try to store tag
						if ($tagTable->check())
						{
							// Assign the alias as path (autogenerated tags have always level 1)
							$tagTable->path = $tagTable->alias;

							if ($tagTable->store())
							{
								$tags[] = $tagTable->id;
							}
						}
					}
				}
			}

			// Commented: unset($tag);
		}

		// Check again that we have tags
		if (is_array($tags) && empty($tags))
		{
			return false;
		}

		$db = JFactory::getDbo();

		if ($isNew == 0)
		{
			// Delete the old tag maps.
			$query = $db->getQuery(true)
				->delete($db->quoteName('#__contentitem_tag_map'))
				->where($db->quoteName('type_alias') . ' = ' . $db->quote($prefix))
				->where($db->quoteName('content_item_id') . ' = ' . (int) $id);
			$db->setQuery($query);
			$db->execute();
		}

		$typeId = self::getTypeId($prefix);

		// Insert the new tag maps
		$query = $db->getQuery(true);
		$query->insert('#__contentitem_tag_map');
		$query->columns(array($db->quoteName('type_alias'), $db->quoteName('content_item_id'), $db->quoteName('tag_id'), $db->quoteName('tag_date'), $db->quoteName('core_content_id'), $db->quoteName('type_id')));

		foreach ($tags as $tag)
		{
			$query->values($db->quote($prefix) . ', ' . (int) $id . ', ' . $db->quote($tag) . ', ' . $query->currentTimestamp() . ', ' . (int) $item . ', ' . (int) $typeId);
		}

		$db->setQuery($query);
		$db->execute();

		return;
	}

	/**
	 * Method to remove all tags associated with a list of items. Generally used for batch processing.
	 *
	 * @param   integer  $id      The id (primary key) of the item to be untagged.
	 * @param   string   $prefix  Dot separated string with the option and view for a url.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function unTagItem($id, $prefix)
	{
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->delete('#__contentitem_tag_map')
			->where($db->quoteName('type_alias') . ' = ' . $db->quote($prefix))
			->where($db->quoteName('content_item_id') . ' = ' . (int) $id);
		$db->setQuery($query);
		$db->execute();

		return;
	}

	/**
	 * Method to get a list of tags for a given item.
	 * Normally used for displaying a list of tags within a layout
	 *
	 * @param   integer  $id      The id (primary key) of the item to be tagged.
	 * @param   string   $prefix  Dot separated string with the option and view to be used for a url.
	 *
	 * @return  string   Comma separated list of tag Ids.
	 *
	 * @since   3.1
	 */
	public function getTagIds($id, $prefix)
	{
		if (!empty($id))
		{
			if (is_array($id))
			{
				$id = implode(',', $id);
			}

			$db = JFactory::getDbo();
			$query = $db->getQuery(true);

			// Load the tags.
			$query->clear()
				->select($db->quoteName('t.id'))
				->from($db->quoteName('#__tags') . ' AS t ')
				->join(
					'INNER', $db->quoteName('#__contentitem_tag_map') . ' AS m'
					. ' ON ' . $db->quoteName('m.tag_id') . ' = ' . $db->quoteName('t.id')
					. ' AND ' . $db->quoteName('m.type_alias') . ' = ' . $db->quote($prefix)
					. ' AND ' . $db->quoteName('m.content_item_id') . ' IN ( ' . $id . ')'
				);

			$db->setQuery($query);

			// Add the tags to the content data.
			$tagsList = $db->loadColumn();
			$this->tags = implode(',', $tagsList);
		}
		else
		{
			$this->tags = null;
		}

		return $this->tags;
	}

	/**
	 * Method to get a list of tags for an item, optionally with the tag data.
	 *
	 * @param   integer  $contentType  Content type alias. Dot separated.
	 * @param   integer  $id           Id of the item to retrieve tags for.
	 * @param   boolean  $getTagData   If true, data from the tags table will be included, defaults to true.
	 *
	 * @return  array    Array of of tag objects
	 *
	 * @since   3.1
	 */
	public function getItemTags($contentType, $id, $getTagData = true)
	{
		if (is_array($id))
		{
			$id = implode($id);
		}

		// Initialize some variables.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('m.tag_id'))
			->from($db->quoteName('#__contentitem_tag_map') . ' AS m ')
			->where(
				array(
					$db->quoteName('m.type_alias') . ' = ' . $db->quote($contentType),
					$db->quoteName('m.content_item_id') . ' = ' . $db->quote($id),
					$db->quoteName('t.published') . ' = 1'
				)
			);

		$user = JFactory::getUser();
		$groups = implode(',', $user->getAuthorisedViewLevels());

		$query->where('t.access IN (' . $groups . ')');

		// Optionally filter on language
		if (empty($language))
		{
			$language = JComponentHelper::getParams('com_tags')->get('tag_list_language_filter', 'all');
		}

		if ($language != 'all')
		{
			if ($language == 'current_language')
			{
				$language = JHelperContent::getCurrentLanguage();
			}
			$query->where($db->quoteName('language') . ' IN (' . $db->quote($language) . ', ' . $db->quote('*') . ')');
		}

		if ($getTagData)
		{
			$query->select($db->quoteName('t') . '.*');
		}

		$query->join('INNER', $db->quoteName('#__tags') . ' AS t ' . ' ON ' . $db->quoteName('m.tag_id') . ' = ' . $db->quoteName('t.id'));

		$db->setQuery($query);
		$this->itemTags = $db->loadObjectList();

		return $this->itemTags;
	}

	/**
	 * Method to get a query to retrieve a detailed list of items for a tag.
	 *
	 * @param   mixed    $tagId            Tag or array of tags to be matched
	 * @param   mixed    $typesr           Null, type or array of type aliases for content types to be included in the results
	 * @param   boolean  $includeChildren  True to include the results from child tags
	 * @param   string   $orderByOption    Column to order the results by
	 * @param   string   $orderDir         Direction to sort the results in
	 * @param   boolean  $anyOrAll         True to include items matching at least one tag, false to include
	 *                                     items all tags in the array.
	 * @param   string   $languageFilter   Optional filter on language. Options are 'all', 'current' or any string.
	 * @param   string   $stateFilter      Optional filtering on publication state, defaults to published or unpublished.
	 *
	 * @return  JDatabaseQuery  Query to retrieve a list of tags
	 *
	 * @since   3.1
	 */
	public function getTagItemsQuery($tagId, $typesr = null, $includeChildren = false, $orderByOption = 'c.core_title', $orderDir = 'ASC',
		$anyOrAll = true, $languageFilter = 'all', $stateFilter = '0,1')
	{
		// Create a new query object.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$user = JFactory::getUser();
		$nullDate = $db->quote($db->getNullDate());

		$ntagsr = substr_count($tagId, ',') + 1;

		// If we want to include children we have to adjust the list of tags.
		// We do not search child tags when the match all option is selected.
		if ($includeChildren)
		{
			if (!is_array($tagId))
			{
				$tagIdArray = explode(',', $tagId);
			}
			else
			{
				$tagIdArray = $tagId;
			}

			$tagTreeList = '';

			foreach ($tagIdArray as $tag)
			{
				if ($this->getTagTreeArray($tag, $tagTreeArray))
				{
					$tagTreeList .= implode(',', $this->getTagTreeArray($tag, $tagTreeArray)) . ',';
				}
			}
			if ($tagTreeList)
			{
				$tagId = trim($tagTreeList, ',');
			}
		}
		if (is_array($tagId))
		{
			$tagId = implode(',', $tagId);
		}
		// M is the mapping table. C is the core_content table. Ct is the content_types table.
		$query->select('m.type_alias, m.content_item_id, m.core_content_id, count(m.tag_id) AS match_count,  MAX(m.tag_date) as tag_date, MAX(c.core_title) AS core_title')
			->select('MAX(c.core_alias) AS core_alias, MAX(c.core_body) AS core_body, MAX(c.core_state) AS core_state, MAX(c.core_access) AS core_access')
			->select('MAX(c.core_metadata) AS core_metadata, MAX(c.core_created_user_id) AS core_created_user_id, MAX(c.core_created_by_alias) AS core_created_by_alias')
			->select('MAX(c.core_created_time) as core_created_time, MAX(c.core_images) as core_images')
			->select('CASE WHEN c.core_modified_time = ' . $nullDate . ' THEN c.core_created_time ELSE c.core_modified_time END as core_modified_time')
			->select('MAX(c.core_language) AS core_language, MAX(c.core_catid) AS core_catid')
			->select('MAX(c.core_publish_up) AS core_publish_up, MAX(c.core_publish_down) as core_publish_down')
			->select('MAX(ct.type_title) AS content_type_title, MAX(ct.router) AS router')

			->from('#__contentitem_tag_map AS m')
			->join('INNER', '#__ucm_content AS c ON m.type_alias = c.core_type_alias AND m.core_content_id = c.core_content_id')
			->join('INNER', '#__content_types AS ct ON ct.type_alias = m.type_alias')

			// Join over the users for the author and email
			->select("CASE WHEN c.core_created_by_alias > ' ' THEN c.core_created_by_alias ELSE ua.name END AS author")
			->select("ua.email AS author_email")

			->join('LEFT', '#__users AS ua ON ua.id = c.core_created_user_id')

			->where('m.tag_id IN (' . $tagId . ')')
			->where('c.core_state IN (' . $stateFilter . ')');

		// Optionally filter on language
		if (empty($language))
		{
			$language = $languageFilter;
		}

		if ($language != 'all')
		{
			if ($language == 'current_language')
			{
				$language = JHelperContent::getCurrentLanguage();
			}

			$query->where($db->quoteName('c.core_language') . ' IN (' . $db->quote($language) . ', ' . $db->quote('*') . ')');
		}

		$contentTypes = new JHelperTags;

		// Get the type data, limited to types in the request if there are any specified.
		$typesarray = $contentTypes->getTypes('assocList', $typesr, false);

		$typeAliases = '';

		foreach ($typesarray as $type)
		{
			$typeAliases .= "'" . $type['type_alias'] . "'" . ',';
		}

		$typeAliases = rtrim($typeAliases, ',');
		$query->where('m.type_alias IN (' . $typeAliases . ')');

		$groups = implode(',', $user->getAuthorisedViewLevels());
		$query->where('c.core_access IN (' . $groups . ')')
			->group('m.type_alias, m.content_item_id, m.core_content_id');

		// Use HAVING if matching all tags and we are matching more than one tag.
		if ($ntagsr > 1 && $anyOrAll != 1 && $includeChildren != 1)
		{
			// The number of results should equal the number of tags requested.
			$query->having("COUNT('m.tag_id') = " . $ntagsr);
		}

		// Set up the order by using the option chosen
		if ($orderByOption == 'match_count')
		{
			$orderBy = 'COUNT(m.tag_id)';
		}
		else
		{
			$orderBy = 'MAX(' . $orderByOption . ')';
		}

		$query->order($orderBy . ' ' . $orderDir);

		return $query;
	}

	/**
	 * Returns content name from a tag map record as an array
	 *
	 * @param   string  $typeAlias  The tag item name to explode.
	 *
	 * @return  array   The exploded type alias. If name doe not exist an empty array is returned.
	 *
	 * @since   3.1
	 */
	public function explodeTypeAlias($typeAlias)
	{
		return explode('.', $typeAlias);
	}

	/**
	 * Returns the component for a tag map record
	 *
	 * @param   string  $typeAlias          The tag item name.
	 * @param   array   $explodedTypeAlias  Exploded alias if it exists
	 *
	 * @return  string  The content type title for the item.
	 *
	 * @since   3.1
	 */
	public function getTypeName($typeAlias, $explodedTypeAlias = null)
	{
		if (!isset($explodedTypeAlias))
		{
			$this->explodedTypeAlias = $this->explodeTypeAlias($typeAlias);
		}

		return $this->explodedTypeAlias[0];
	}

	/**
	 * Returns the url segment for a tag map record.
	 *
	 * @param   string   $typeAlias          The tag item name.
	 * @param   integer  $id                 Id of the item
	 * @param   array    $explodedTypeAlias  Exploded alias if it exists
	 *
	 * @return  string  The url string e.g. index.php?option=com_content&vew=article&id=3.
	 *
	 * @since   3.1
	 */
	public function getContentItemUrl($typeAlias, $id, $explodedTypeAlias = null)
	{
		if (!isset($explodedTypeAlias))
		{
			$explodedTypeAlias = $this->explodeTypeAlias($typeAlias);
		}

		$this->url = 'index.php?option=' . $explodedTypeAlias[0] . '&view=' . $explodedTypeAlias[1] . '&id=' . $id;

		return $this->url;
	}

	/**
	 * Returns the url segment for a tag map record.
	 *
	 * @param   integer  $id  The item ID
	 *
	 * @return  string  The url string e.g. index.php?option=com_content&vew=article&id=3.
	 *
	 * @since   3.1
	 */
	public function getTagUrl($id)
	{
		$this->url = 'index.php&option=com_tags&view=tag&id=' . $id;

		return $this->url;
	}

	/**
	 * Method to get the table name for a type alias.
	 *
	 * @param   string  $tagItemAlias  A type alias.
	 *
	 * @return  string  Name of the table for a type
	 *
	 * @since   3.1
	 */
	public function getTableName($tagItemAlias)
	{
		// Initialize some variables.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('table'))
			->from($db->quoteName('#__content_types'))
			->where($db->quoteName('type_alias') . ' = ' . $db->quote($tagItemAlias));
		$db->setQuery($query);
		$this->table = $db->loadResult();

		return $this->table;
	}

	/**
	 * Method to get the type id for a type alias.
	 *
	 * @param   string  $typeAlias  A type alias.
	 *
	 * @return  string  Name of the table for a type
	 *
	 * @since   3.1
	 */
	public function getTypeId($typeAlias)
	{
		// Initialize some variables.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('type_id'))
			->from($db->quoteName('#__content_types'))
			->where($db->quoteName('type_alias') . ' = ' . $db->quote($typeAlias));
		$db->setQuery($query);
		$this->type_id = $db->loadResult();

		return $this->type_id;
	}

	/**
	 * Method to get a list of types with associated data.
	 *
	 * @param   string   $arrayType    Optionally specify that the returned list consist of objects, associative arrays, or arrays.
	 *                                 Options are: rowList, assocList, and objectList
	 * @param   array    $selectTypes  Optional array of type ids to limit the results to. Often from a request.
	 * @param   boolean  $useAlias     If true, the alias is used to match, if false the type_id is used.
	 *
	 * @return  array   Array of of types
	 *
	 * @since   3.1
	 */
	public static function getTypes($arrayType = 'objectList', $selectTypes = null, $useAlias = true)
	{
		// Initialize some variables.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('*');

		if (!empty($selectTypes))
		{
			if (is_array($selectTypes))
			{
				$selectTypes = implode(',', $selectTypes);
			}
			if ($useAlias)
			{
				$query->where($db->quoteName('type_alias') . ' IN (' . $db->quote($selectTypes) . ')');
			}
			else
			{
				$query->where($db->quoteName('type_id') . ' IN (' . $selectTypes . ')');
			}
		}

		$query->from($db->quoteName('#__content_types'));

		$db->setQuery($query);

		switch ($arrayType)
		{
			case 'assocList':
				$types = $db->loadAssocList();
			break;

			case 'rowList':
				$types = $db->loadRowList();
			break;

			case 'objectList':
			default:
				$types = $db->loadObjectList();
			break;
		}

		return $types;
	}

	/**
	 * Method to delete all instances of a tag from the mapping table. Generally used when a tag is deleted.
	 *
	 * @param   integer  $tag_id  The tag_id (primary key) for the deleted tag.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function tagDeleteInstances($tag_id)
	{
		// Delete the old tag maps.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->delete($db->quoteName('#__contentitem_tag_map'))
			->where($db->quoteName('tag_id') . ' = ' . (int) $tag_id);
		$db->setQuery($query);
		$db->execute();
	}

	/**
	 * Function to search tags
	 *
	 * @param   array  $filters  Filter to apply to the search
	 *
	 * @return  array
	 *
	 * @since   3.1
	 */
	public static function searchTags($filters = array())
	{
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select('a.id AS value')
			->select('a.path AS text')
			->select('a.path')
			->from('#__tags AS a')
			->join('LEFT', $db->quoteName('#__tags', 'b') . ' ON a.lft > b.lft AND a.rgt < b.rgt');

		// Filter language
		if (!empty($filters['flanguage']))
		{
			$query->where('a.language IN (' . $db->quote($filters['flanguage']) . ',' . $db->quote('*') . ') ');
		}

		// Do not return root
		$query->where($db->quoteName('a.alias') . ' <> ' . $db->quote('root'));

		// Search in title or path
		if (!empty($filters['like']))
		{
			$query->where(
				'(' . $db->quoteName('a.title') . ' LIKE ' . $db->quote('%' . $filters['like'] . '%')
					. ' OR ' . $db->quoteName('a.path') . ' LIKE ' . $db->quote('%' . $filters['like'] . '%') . ')'
			);
		}

		// Filter title
		if (!empty($filters['title']))
		{
			$query->where($db->quoteName('a.title') . ' = ' . $db->quote($filters['title']));
		}

		// Filter on the published state
		if (is_numeric($filters['published']))
		{
			$query->where('a.published = ' . (int) $filters['published']);
		}

		// Filter by parent_id
		if (!empty($filters['parent_id']))
		{
			JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_tags/tables');
			$tagTable = JTable::getInstance('Tag', 'TagsTable');

			if ($children = $tagTable->getTree($filters['parent_id']))
			{
				foreach ($children as $child)
				{
					$childrenIds[] = $child->id;
				}

				$query->where('a.id IN (' . implode(',', $childrenIds) . ')');
			}
		}

		$query->group('a.id, a.title, a.level, a.lft, a.rgt, a.parent_id, a.published, a.path')
			->order('a.lft ASC');

		// Get the options.
		$db->setQuery($query);

		try
		{
			$results = $db->loadObjectList();
		}
		catch (RuntimeException $e)
		{
			return false;
		}

		// We will replace path aliases with tag names
		$results = self::convertPathsToNames($results);

		return $results;
	}

	/**
	 * Method to delete the tag mappings and #__ucm_content record for for an item
	 *
	 * @param   array   $contentItemIds  Array of values of the primary key from the table for the type
	 * @param   string  $typeAlias       The type alias for the type
	 *
	 * @return  boolean
	 *
	 * @since   3.1
	 */
	public function deleteTagData($contentItemIds, $typeAlias)
	{
		foreach ($contentItemIds as $contentItemId)
		{
			self::unTagItem($contentItemId, $typeAlias);
		}

		$ucmContent = new JUcmContent(JTable::getInstance('Corecontent'), $typeAlias);
		$ucmContent->delete($contentItemIds);

		return;
	}

	/**
	 * Method to get an array of tag ids for the current tag and its children
	 *
	 * @param   integer  $id             An optional ID
	 * @param   array    &$tagTreeArray  Array containing the tag tree
	 *
	 * @return  mixed
	 *
	 * @since   3.1
	 */
	public function getTagTreeArray($id, &$tagTreeArray = array())
	{
		// Get a level row instance.
		$table = JTable::getInstance('Tag', 'TagsTable');

		if ($table->isLeaf($id))
		{
			$tagTreeArray[] .= $id;
			return $tagTreeArray;
		}
		$tagTree = $table->getTree($id);

		// Attempt to load the tree
		if ($tagTree)
		{
			foreach ($tagTree as $tag)
			{
				$tagTreeArray[] = $tag->id;
			}
			return $tagTreeArray;
		}
	}

	/**
	 * Function that converts tags paths into paths of names
	 *
	 * @param   array  $tags  Array of tags
	 *
	 * @return  array
	 *
	 * @since   3.1
	 */
	public static function convertPathsToNames($tags)
	{
		// We will replace path aliases with tag names
		if ($tags)
		{
			// Create an array with all the aliases of the results
			$aliases = array();

			foreach ($tags as $tag)
			{
				if (!empty($tag->path))
				{
					if ($pathParts = explode('/', $tag->path))
					{
						$aliases = array_merge($aliases, $pathParts);
					}
				}
			}

			// Get the aliases titles in one single query and map the results
			if ($aliases)
			{
				// Remove duplicates
				$aliases = array_unique($aliases);

				$db = JFactory::getDbo();

				$query = $db->getQuery(true)
					->select('alias, title')
					->from('#__tags')
					->where('alias IN (' . implode(',', array_map(array($db, 'quote'), $aliases)) . ')');
				$db->setQuery($query);

				try
				{
					$aliasesMapper = $db->loadAssocList('alias');
				}
				catch (RuntimeException $e)
				{
					return false;
				}

				// Rebuild the items path
				if ($aliasesMapper)
				{
					foreach ($tags as $tag)
					{
						$namesPath = array();

						if (!empty($tag->path))
						{
							if ($pathParts = explode('/', $tag->path))
							{
								foreach ($pathParts as $alias)
								{
									if (isset($aliasesMapper[$alias]))
									{
										$namesPath[] = $aliasesMapper[$alias]['title'];
									}
									else
									{
										$namesPath[] = $alias;
									}
								}

								$tag->text = implode('/', $namesPath);
							}
						}
					}
				}
			}
		}

		return $tags;
	}

	/**
	 * Function that converts tag ids to their tag names
	 *
	 * @param   string  &$metadata  A JSON encoded metadata string
	 *
	 * @return  array  An array of names only
	 *
	 * @since   3.1
	 */
	public function getMetaTagNames(&$metadata)
	{
		$metadata = json_decode($metadata);
		$tags = explode(',', $metadata->tags);

		$tagIds = array();
		$tagNames = array();

		if (!empty($tags))
		{
			foreach ($tags as $tag)
			{
				if (is_numeric($tag))
				{
					$tagIds[] = $tag;
				}
				else
				{
					$tagNames[] = $tag;
				}
			}

			if (!empty($tagIds))
			{
				$tagIds = implode(',', $tagIds);

				$db = JFactory::getDbo();
				$query = $db->getQuery(true);
				$query->select('title')
					->from('#__tags')
					->where($db->quoteName('id') . ' IN (' . $tagIds . ')');

				$db->setQuery($query);
				$newTagNames = $db->loadColumn();
				$tagNames = array_merge($tagNames, $newTagNames);
			}
		}

		$metadata->tags = implode(',', $tagNames);
		$metadata = json_encode($metadata);

		return $tagNames;
	}

	/**
	 * Function that converts tags stored as metadata to tags and back, including cleaning
	 *
	 * @param   string  &$metadata  JSON encoded metadata
	 *
	 * @return  array
	 *
	 * @since   3.1
	 */
	public function convertTagsMetadata(&$metadata)
	{
		$metadata = json_decode($metadata);
		$tags = (array) $metadata->tags;

		// Store the tag data if the article data was saved and run related methods.
		if (empty($tags) == false)
		{
			// Fix the need to do this
			foreach ($tags as &$tagText)
			{
				// Remove the #new# prefix that identifies new tags
				$newTags[] = str_replace('#new#', '', $tagText);
			}

			$metadata->tags = implode(',', $newTags);
			$metadata = json_encode($metadata);
		}

		if (count($tags) == 1 && $tags[0] == '')
		{
			$tags = array();
		}

		return $tags;
	}
}
