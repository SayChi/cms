<?php
namespace Craft;

class TemplateCacheService extends BaseApplicationComponent
{
	private static $_templateCachesTable = 'templatecaches';
	private static $_templateCacheElementsTable = 'templatecacheelements';
	private static $_lastCleanupDateCacheDuration = 86400;

	private $_path;
	private $_cacheElementIds;
	private $_deletedExpiredCaches = false;

	/**
	 * Returns a cached template by its key.
	 *
	 * @param string $key
	 * @param bool   $global
	 * @return string|null
	 */
	public function getTemplateCache($key, $global)
	{
		// Take the opportunity to delete any expired caches
		$this->deleteExpiredCachesIfOverdue();

		$conditions = array('and', 'expiryDate > :now', 'cacheKey = :key', 'locale = :locale');

		$params = array(
			':now'    => DateTimeHelper::currentTimeForDb(),
			':key'    => $key,
			':locale' => craft()->language
		);

		if (!$global)
		{
			$conditions[] = 'path = :path';
			$params[':path'] = $this->_getPath();
		}

		return craft()->db->createCommand()
			->select('body')
			->from(static::$_templateCachesTable)
			->where($conditions, $params)
			->queryScalar();
	}

	/**
	 * Starts a new template cache.
	 *
	 * @param string $key
	 */
	public function startTemplateCache($key)
	{
		$this->_cacheElementIds[$key] = array();
	}

	/**
	 * Includes an element in any active caches.
	 *
	 * @param int $elementId
	 */
	public function includeElementInTemplateCaches($elementId)
	{
		if (!empty($this->_cacheElementIds))
		{
			foreach (array_keys($this->_cacheElementIds) as $key)
			{
				if (array_search($elementId, $this->_cacheElementIds[$key]) === false)
				{
					$this->_cacheElementIds[$key][] = $elementId;
				}
			}
		}
	}

	/**
	 * Ends a template cache.
	 *
	 * @param string      $key
	 * @param bool        $global
	 * @param string|null $duration
	 * @param mixed|null  $expiration
	 * @param string      $body
	 */
	public function endTemplateCache($key, $global, $duration, $expiration, $body)
	{
		// If there are any transform generation URLs in the body, don't cache it
		if (strpos($body, 'assets/generateTransform'))
		{
			return;
		}

		// Figure out the expiration date
		if ($duration)
		{
			$expiration = new DateTime($duration);
		}

		if (!$expiration)
		{
			$timestamp = time() + craft()->config->getCacheDuration();
			$expiration = new DateTime('@'.$timestamp);
		}

		// Save it
		$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
		try
		{
			craft()->db->createCommand()->insert(static::$_templateCachesTable, array(
				'cacheKey'   => $key,
				'locale'     => craft()->language,
				'path'       => ($global ? null : $this->_getPath()),
				'expiryDate' => DateTimeHelper::formatTimeForDb($expiration),
				'body'       => $body
			), false);

			$cacheId = craft()->db->getLastInsertID();

			if (isset($this->_cacheElementIds[$key]))
			{
				$values = array();

				foreach ($this->_cacheElementIds[$key] as $elementId)
				{
					$values[] = array($cacheId, $elementId);
				}

				craft()->db->createCommand()->insertAll(static::$_templateCacheElementsTable, array('cacheId', 'elementId'), $values, false);

				unset($this->_cacheElementIds[$key]);
			}

			if ($transaction !== null)
			{
				$transaction->commit();
			}
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}
	}

	/**
	 * Deletes caches that include an a given element ID(s).
	 *
	 * @param int|array $elementId
	 * @return bool
	 */
	public function deleteCachesByElementId($elementId)
	{
		if (!$elementId)
		{
			return false;
		}

		$query = craft()->db->createCommand()
			->selectDistinct('cacheId')
			->from(static::$_templateCacheElementsTable);

		if (is_array($elementId))
		{
			$query->where(array('in', 'elementId', $elementId));
		}
		else
		{
			$query->where('elementId = :elementId', array(':elementId' => $elementId));
		}

		$cacheIds = $query->queryColumn();

		if ($cacheIds)
		{
			$affectedRows = craft()->db->createCommand()->delete(static::$_templateCachesTable, array('in', 'id', $cacheIds));
			return (bool) $affectedRows;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Deletes caches that include elements that match a given criteria.
	 *
	 * @param ElementCriteriaModel $criteria
	 * @return bool
	 */
	public function deleteCachesByCriteria(ElementCriteriaModel $criteria)
	{
		$criteria->limit = null;
		$elementIds = $criteria->ids();
		return $this->deleteCachesByElementId($elementIds);
	}

	/**
	 * Deletes any expired caches.
	 *
	 * @return bool
	 */
	public function deleteExpiredCaches()
	{
		// Ignore if we've already done this once during the request
		if ($this->_deletedExpiredCaches)
		{
			return false;
		}

		$affectedRows = craft()->db->createCommand()->delete(static::$_templateCachesTable,
			array('expiryDate <= :now'),
			array('now' => DateTimeHelper::currentTimeForDb())
		);

		// Make like an elephant...
		craft()->cache->set('lastTemplateCacheCleanupDate', DateTimeHelper::currentTimeStamp(), static::$_lastCleanupDateCacheDuration);
		$this->_deletedExpiredCaches = true;

		return $affectedRows;
	}

	/**
	 * Deletes any expired caches if we haven't already done that within the past 24 hours.
	 *
	 * @return bool
	 */
	public function deleteExpiredCachesIfOverdue()
	{
		// Ignore if we've already done this once during the request
		if ($this->_deletedExpiredCaches)
		{
			return false;
		}

		$lastCleanupDate = craft()->cache->get('lastTemplateCacheCleanupDate');

		if ($lastCleanupDate === false || DateTimeHelper::currentTimeStamp() - $lastCleanupDate > static::$_lastCleanupDateCacheDuration)
		{
			return $this->deleteExpiredCaches();
		}
		else
		{
			$this->_deletedExpiredCaches = true;
			return false;
		}
	}

	/**
	 * Deletes all the template caches.
	 *
	 * @return bool
	 */
	public function deleteAllCaches()
	{
		$affectedRows = craft()->db->createCommand()->delete(static::$_templateCachesTable);
		return (bool) $affectedRows;
	}

	/**
	 * Returns the current request path, including a "site:" or "cp:" prefix.
	 *
	 * @access private
	 * @return string
	 */
	private function _getPath()
	{
		if (!isset($this->_path))
		{
			if (craft()->request->isCpRequest())
			{
				$this->_path = 'cp:';
			}
			else
			{
				$this->_path = 'site:';
			}

			$this->_path .= craft()->request->getPath();

			if (($pageNum = craft()->request->getPageNum()) != 1)
			{
				$this->_path .= '/'.craft()->config->get('pageTrigger').$pageNum;
			}

			if ($queryString = craft()->request->getQueryString())
			{
				// Strip the path param
				$queryString = trim(preg_replace('/'.craft()->urlManager->pathParam.'=[^&]*/', '', $queryString), '&');

				if ($queryString)
				{
					$this->_path .= '?'.$queryString;
				}
			}
		}

		return $this->_path;
	}
}