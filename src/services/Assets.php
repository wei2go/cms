<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\services;

use Craft;
use craft\app\dates\DateTime;
use craft\app\db\Query;
use craft\app\elements\Asset;
use craft\app\elements\db\AssetQuery;
use craft\app\errors\ActionCancelledException;
use craft\app\errors\AssetConflictException;
use craft\app\errors\AssetLogicException;
use craft\app\errors\AssetMissingException;
use craft\app\errors\VolumeException;
use craft\app\errors\VolumeFileExistsException;
use craft\app\errors\VolumeFolderExistsException;
use craft\app\errors\ElementSaveException;
use craft\app\errors\Exception;
use craft\app\errors\FileException;
use craft\app\errors\ModelValidationException;
use craft\app\events\AssetEvent;
use craft\app\helpers\AssetsHelper;
use craft\app\helpers\DbHelper;
use craft\app\helpers\ImageHelper;
use craft\app\helpers\IOHelper;
use craft\app\helpers\UrlHelper;
use craft\app\models\AssetFolder as AssetFolderModel;
use craft\app\models\AssetFolder;
use craft\app\models\FolderCriteria;
use craft\app\records\Asset as AssetRecord;
use craft\app\records\AssetFolder as AssetFolderRecord;
use yii\base\Component;
/**
 * Class Assets service.
 *
 * An instance of the Assets service is globally accessible in Craft via [[Application::assets `Craft::$app->assets`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Assets extends Component
{
	// Constants
	// =========================================================================

	/**
	 * @event AssetEvent The event that is triggered before an asset is uploaded.
	 *
	 * You may set [[AssetEvent::performAction]] to `false` to prevent the asset from getting saved.
	 */
	const EVENT_BEFORE_UPLOAD_ASSET = 'beforeUploadAsset';

	/**
	 * @event AssetEvent The event that is triggered before an asset is saved.
	 */
	const EVENT_BEFORE_SAVE_ASSET = 'beforeSaveAsset';

	/**
	 * @event AssetEvent The event that is triggered after an asset is saved.
	 */
	const EVENT_AFTER_SAVE_ASSET = 'afterSaveAsset';

	/**
	 * @event AssetEvent The event that is triggered after an asset is replaced.
	 */
	const EVENT_ON_REPLACE_FILE = 'replaceFile';

	/**
	 * @event AssetEvent The event that is triggered before an asset is deleted.
	 */
	const EVENT_BEFORE_DELETE_ASSET = 'beforeDeleteAsset';

	/**
	 * @event AssetEvent The event that is triggered after an asset is deleted.
	 */
	const EVENT_AFTER_DELETE_ASSET = 'afterDeleteAsset';

	// Properties
	// =========================================================================

	/**
	 * @var
	 */
	private $_foldersById;

	// Public Methods
	// =========================================================================

	/**
	 * Returns all top-level files in a volume.
	 *
	 * @param int         $volumeId
	 * @param string|null $indexBy
	 *
	 * @return array
	 */
	public function getFilesByVolumeId($volumeId, $indexBy = null)
	{
		return Asset::find()
			->volumeId($volumeId)
			->indexBy($indexBy)
			->all();
	}

	/**
	 * Returns a file by its ID.
	 *
	 * @param             $fileId
	 * @param string|null $localeId
	 *
	 * @return Asset|null
	 */
	public function getFileById($fileId, $localeId = null)
	{
		return Craft::$app->elements->getElementById($fileId, Asset::className(), $localeId);
	}

	/**
	 * Finds the first file that matches the given criteria.
	 *
	 * @param mixed $criteria
	 *
	 * @return Asset|null
	 */
	public function findFile($criteria = null)
	{
		if ($criteria instanceof AssetQuery)
		{
			$query = $criteria;
		}
		else
		{
			$query = Asset::find()->configure($criteria);
		}

		if (is_string($query->filename))
		{
			// Backslash-escape any commas in a given string.
			$query->filename = DbHelper::escapeParam($query->filename);
		}

		return $query->one();
	}

	/**
	 * Gets the total number of files that match a given criteria.
	 *
	 * @param mixed $criteria
	 *
	 * @return int
	 */
	public function getTotalFiles($criteria = null)
	{
		if ($criteria instanceof AssetQuery)
		{
			$query = $criteria;
		}
		else
		{
			$query = Asset::find()->configure($criteria);
		}

		return $query->count();
	}

	/**
	 * Save an Asset.
	 *
	 * Saves an Asset. If the 'newFilePath' property is set, will replace the existing file.
	 * For new files, this property MUST bet set.
	 *
	 * @param Asset $asset
	 *
	 * @throws FileException                  If there was a problem with the actual file.
	 * @throws AssetConflictException         If a file with such name already exists.
	 * @throws AssetLogicException            If something violates Asset's logic (e.g. Asset outside of a folder).
	 * @throws VolumeFileExistsException If the file actually exists on the volume, but on in the index.
	 *
	 * @return void
	 */
	public function saveAsset(Asset $asset)
	{
		$isNew = empty($asset->id);

		if ($isNew && empty($asset->newFilePath) && empty($asset->indexInProgress))
		{
			throw new AssetLogicException(Craft::t('app', 'A new Asset cannot be created without a file.'));
		}

		if (empty($asset->folderId))
		{
			throw new AssetLogicException(Craft::t('app', 'All Assets must have folder ID set.'));
		}

		$asset->filename = AssetsHelper::prepareAssetName($asset->filename);

		$existingAsset = $this->findFile(array('filename' => $asset->filename, 'folderId' => $asset->folderId));

		if ($existingAsset && $existingAsset->id != $asset->id)
		{
			throw new AssetConflictException(Craft::t('app', 'A file with the name “{filename}” already exists in the folder.', array('filename' => $asset->filename)));
		}

		$volume = $asset->getVolume();

		if (!$volume)
		{
			throw new AssetLogicException(Craft::t('app', 'Volume does not exist with the id of {id}.', array('id' => $asset->volumeId)));
		}

		if (!empty($asset->newFilePath))
		{
			$stream = fopen($asset->newFilePath, 'r');

			if (!$stream)
			{
				throw new FileException(Craft::t('app', 'Could not open file for streaming at {path}', array('path' => $asset->newFilePath)));
			}

			$uriPath = $asset->getUri();

			$event = new AssetEvent(['asset' => $asset]);

			$this->trigger(static::EVENT_BEFORE_UPLOAD_ASSET, $event);

			// Explicitly re-throw VolumeFileExistsException
			try
			{
				$volume->createFile($uriPath, $stream);
			}
			catch (VolumeFileExistsException $exception)
			{
				throw $exception;
			}

			// Don't leave it hangin'
			if (is_resource($stream))
			{
				fclose($stream);
			}

			$asset->dateModified = new DateTime();
			$asset->size = IOHelper::getFileSize($asset->newFilePath);
			$asset->kind = IOHelper::getFileKind($asset->getExtension());

			if ($asset->kind == 'image' && !empty($asset->newFilePath))
			{
				list ($asset->width, $asset->height) = getimagesize($asset->newFilePath);
			}
		}

		$this->_storeAssetRecord($asset);

		// Now that we have an ID, store the source
		if (!$volume->isLocal() && $asset->kind == 'image' && !empty($asset->newFilePath))
		{
			// Store the local source for now and set it up for deleting, if needed
			Craft::$app->assetTransforms->storeLocalSource($asset->newFilePath, $asset->getImageTransformSourcePath());
			Craft::$app->assetTransforms->queueSourceForDeletingIfNecessary($asset->getImageTransformSourcePath());
		}
	}

	/**
	 * Delete a list of files by an array of ids (or a single id).
	 *
	 * @param array|int $fileIds
	 * @param bool      $deleteFile Should the file be deleted along the record. Defaults to true.
	 *
	 * @return null
	 */
	public function deleteFilesByIds($fileIds, $deleteFile = true)
	{
		if (!is_array($fileIds))
		{
			$fileIds = array($fileIds);
		}

		foreach ($fileIds as $fileId)
		{
			$file = $this->getFileById($fileId);

			if ($file)
			{
				$volume = $file->getVolume();

				// Fire an 'onBeforeDeleteAsset' event
				$event = new AssetEvent($this, array(
					'asset' => $file
				));
				$this->trigger(static::EVENT_BEFORE_DELETE_ASSET, $event);

				if ($deleteFile)
				{
					$volume->deleteFile($file->getUri());
				}

				Craft::$app->elements->deleteElementById($fileId);

				$this->trigger(static::EVENT_AFTER_DELETE_ASSET, $event);

			}
		}
	}

	/**
	 * Rename an Asset.
	 *
	 * @param Asset  $asset
	 * @param string $newFilename
	 *
	 * @throws AssetConflictException If a file with such a name already exists/
	 * @throws AssetLogicException    If something violates Asset's logic (e.g. Asset outside of a folder).
	 * @return null
	 */
	public function renameFile(Asset $asset, $newFilename)
	{
		$newFilename = AssetsHelper::prepareAssetName($newFilename);

		$existingAsset = $this->findFile(array('filename' => $newFilename, 'folderId' => $asset->folderId));

		if ($existingAsset && $existingAsset->id != $asset->id)
		{
			throw new AssetConflictException(Craft::t('app', 'A file with the name “{filename}” already exists in the folder.', array('filename' => $newFilename)));
		}

		$volume = $asset->getVolume();

		if (!$volume)
		{
			throw new AssetLogicException(Craft::t('app', 'Volume does not exist with the id of {id}.', array('id' => $asset->volumeId)));
		}

		if ($volume->renameFile($asset->getUri(), $asset->getUri($newFilename)))
		{
			$asset->filename = $newFilename;
			$this->_storeAssetRecord($asset);
		}
	}

	/**
	 * Save an Asset folder.
	 *
	 * @param AssetFolderModel $folder
	 *
	 * @throws AssetConflictException           If a folder already exists with such a name.
	 * @throws AssetLogicException              If something violates Asset's logic (e.g. Asset outside of a folder).
	 * @throws VolumeFolderExistsException If the file actually exists on the volume, but on in the index.
	 * @return void
	 */
	public function createFolder(AssetFolderModel $folder)
	{
		$parent = $folder->getParent();

		if (!$parent)
		{
			throw new AssetLogicException(Craft::t('app', 'No folder exists with the ID “{id}”', array('id' => $folder->parentId)));
		}

		$existingFolder = $this->findFolder(array('parentId' => $folder->parentId, 'name' => $folder->name));

		if ($existingFolder && (empty($folder->id) || $folder->id != $existingFolder))
		{
			throw new AssetConflictException(Craft::t('app', 'A folder with the name “{folderName}” already exists in the folder.', array('folderName' => $folder->name)));
		}

		$volume = $parent->getVolume();

		// Explicitly re-throw VolumeFolderExistsException
		try
		{
			$volume->createDir(rtrim($folder->path, '/'));
		}
		catch (VolumeFolderExistsException $exception)
		{
			throw $exception;
		}
		$this->storeFolderRecord($folder);
	}

	/**
	 * Deletes a folder by its ID.
	 *
	 * @param array|int $folderIds
 	 * @param bool      $deleteFolder Should the file be deleted along the record. Defaults to true.
	 *
	 * @throws VolumeException If deleting a single folder and it cannot be deleted.
	 * @return null
	 */
	public function deleteFoldersByIds($folderIds, $deleteFolder = true)
	{
		if (!is_array($folderIds))
		{
			$folderIds = array($folderIds);
		}

		foreach ($folderIds as $folderId)
		{
			$folder = $this->getFolderById($folderId);

			if ($folder)
			{
				if ($deleteFolder)
				{
					$volume = $folder->getVolume();

					// If this is a batch operation, don't stop the show
					if (!$volume->deleteDir($folder->path) && count($folderIds) == 1)
					{
						throw new VolumeException(Craft::t('app', 'Folder “{folder}” cannot be deleted!', array('folder' => $folder->path)));
					}
				}

				AssetFolderRecord::deleteAll(['id' => $folderId]);
			}
		}
	}

	/**
	 * Get the folder tree for Assets by volume ids
	 *
	 * @param $allowedVolumeIds
	 *
	 * @return array
	 */
	public function getFolderTreeByVolumeIds($allowedVolumeIds)
	{
		$folders = $this->findFolders(array('volumeId' => $allowedVolumeIds, 'order' => 'path'));
		$tree = $this->_getFolderTreeByFolders($folders);

		$sort = array();

		foreach ($tree as $topFolder)
		{
			/**
			 * @var AssetFolder $topFolder;
			 */
			$sort[] = $topFolder->getVolume()->sortOrder;
		}

		array_multisort($sort, $tree);

		return $tree;
	}

	/**
	 * Get the folder tree for Assets by a folder id.
	 *
	 * @param $folderId
	 *
	 * @return array
	 */
	public function getFolderTreeByFolderId($folderId)
	{
		$folder = $this->getFolderById($folderId);

		if (is_null($folder))
		{
			return array();
		}

		return $this->_getFolderTreeByFolders(array($folder));
	}

	/**
	 * Returns a folder by its ID.
	 *
	 * @param int $folderId
	 *
	 * @return AssetFolderModel|null
	 */
	public function getFolderById($folderId)
	{
		if (!isset($this->_foldersById) || !array_key_exists($folderId, $this->_foldersById))
		{
			$result = $this->_createFolderQuery()
				->where('id = :id', array(':id' => $folderId))
				->one();

			if ($result)
			{
				$folder = new AssetFolderModel($result);
			}
			else
			{
				$folder = null;
			}

			$this->_foldersById[$folderId] = $folder;
		}

		return $this->_foldersById[$folderId];
	}

	/**
	 * Finds folders that match a given criteria.
	 *
	 * @param mixed $criteria
	 *
	 * @return array
	 */
	public function findFolders($criteria = null)
	{
		if (!($criteria instanceof FolderCriteria))
		{
			$criteria = new FolderCriteria($criteria);
		}

		$query = (new Query())
			->select('f.*')
			->from('{{%assetfolders}} AS f');

		$this->_applyFolderConditions($query, $criteria);

		if ($criteria->order)
		{
			$query->orderBy($criteria->order);
		}

		if ($criteria->offset)
		{
			$query->offset($criteria->offset);
		}

		if ($criteria->limit)
		{
			$query->limit($criteria->limit);
		}

		$results = $query->all();
		$folders = array();

		foreach ($results as $result)
		{
			$folder = AssetFolderModel::create($result);
			$this->_foldersById[$folder->id] = $folder;
			$folders[] = $folder;
		}

		return $folders;
	}

	/**
	 * Returns all of the folders that are descendants of a given folder.
	 *
	 * @param AssetFolderModel $parentFolder
	 *
	 * @return array
	 */
	public function getAllDescendantFolders(AssetFolderModel $parentFolder)
	{
		$query = (new Query())
			->select('f.*')
			->from('{{%assetfolders}} AS f')
			->where(['like', 'path', $parentFolder->path.'%', false])
			->andWhere('volumeId = :volumeId', array(':volumeId' => $parentFolder->volumeId));

		$results = $query->all();
		$descendantFolders = array();

		foreach ($results as $result)
		{
			$folder = AssetFolderModel::create($result);
			$this->_foldersById[$folder->id] = $folder;
			$descendantFolders[$folder->id] = $folder;
		}

		return $descendantFolders;
	}

	/**
	 * Finds the first folder that matches a given criteria.
	 *
	 * @param mixed $criteria
	 *
	 * @return AssetFolderModel|null
	 */
	public function findFolder($criteria = null)
	{
		if (!($criteria instanceof FolderCriteria))
		{
			$criteria = new FolderCriteria($criteria);
		}

		$criteria->limit = 1;
		$folder = $this->findFolders($criteria);

		if (is_array($folder) && !empty($folder))
		{
			return array_pop($folder);
		}

		return null;
	}

	/**
	 * Gets the total number of folders that match a given criteria.
	 *
	 * @param mixed $criteria
	 *
	 * @return int
	 */
	public function getTotalFolders($criteria)
	{
		if (!($criteria instanceof FolderCriteria))
		{
			$criteria = new FolderCriteria($criteria);
		}

		$query = (new Query())
			->select('count(id)')
			->from('{{%assetfolders}} AS f');

		$this->_applyFolderConditions($query, $criteria);

		return (int) $query->scalar();
	}

	// File and folder managing
	// -------------------------------------------------------------------------


	/**
	 * Get URL for a file.
	 *
	 * @param Asset  $file
	 * @param string $transform
	 *
	 * @return string
	 */
	public function getUrlForFile(Asset $file, $transform = null)
	{
		//TODO
		if (!$transform || !ImageHelper::isImageManipulatable(IOHelper::getExtension($file->filename)))
		{
			$volume = $file->getVolume();

			return AssetsHelper::generateUrl($volume, $file);
		}

		// Get the transform index model
		$index = Craft::$app->assetTransforms->getTransformIndex($file, $transform);

		// Does the file actually exist?
		if ($index->fileExists)
		{
			return Craft::$app->assetTransforms->getUrlForTransformByTransformIndex($index);
		}
		else
		{
			if (Craft::$app->config->get('generateTransformsBeforePageLoad'))
			{
				// Mark the transform as in progress
				$index->inProgress = true;
				Craft::$app->assetTransforms->storeTransformIndexData($index);

				// Generate the transform
				Craft::$app->assetTransforms->generateTransform($index);

				// Update the index
				$index->fileExists = true;
				Craft::$app->assetTransforms->storeTransformIndexData($index);

				// Return the transform URL
				return Craft::$app->assetTransforms->getUrlForTransformByTransformIndex($index);
			}
			else
			{
				// Queue up a new Generate Pending Transforms task, if there isn't one already
				if (!Craft::$app->tasks->areTasksPending('GeneratePendingTransforms'))
				{
					Craft::$app->tasks->createTask('GeneratePendingTransforms');
				}

				// Return the temporary transform URL
				return UrlHelper::getResourceUrl('transforms/'.$index->id);
			}
		}
	}

	/**
	 * Return true if user has permission to perform the action on the folder.
	 *
	 * @param $folderId
	 * @param $action
	 *
	 * @return bool
	 */
	public function canUserPerformAction($folderId, $action)
	{
		try
		{
			$this->checkPermissionByFolderIds($folderId, $action);
			return true;
		}
		catch (Exception $exception)
		{
			return false;
		}
	}

	/**
	 * Check for a permission on a volumeId by a folder id or an array of folder ids.
	 *
	 * @param $folderIds
	 * @param $permission
	 *
	 * @throws Exception
	 * @return null
	 */
	public function checkPermissionByFolderIds($folderIds, $permission)
	{
		if (!is_array($folderIds))
		{
			$folderIds = array($folderIds);
		}

		foreach ($folderIds as $folderId)
		{
			$folderModel = $this->getFolderById($folderId);

			if (!$folderModel)
			{
				throw new Exception(Craft::t('app', 'That folder does not seem to exist anymore. Re-index the Assets volume and try again.'));
			}

			if (!Craft::$app->user->checkPermission($permission.':'.$folderModel->volumeId))
			{
				throw new Exception(Craft::t('app', 'You don’t have the required permissions for this operation.'));
			}
		}
	}

	/**
	 * Check for a permission on a volume by a file id or an array of file ids.
	 *
	 * @param $fileIds
	 * @param $permission
	 *
	 * @throws Exception
	 * @return null
	 */
	public function checkPermissionByFileIds($fileIds, $permission)
	{
		if (!is_array($fileIds))
		{
			$fileIds = array($fileIds);
		}

		foreach ($fileIds as $fileId)
		{
			$file = $this->getFileById($fileId);

			if (!$file)
			{
				throw new Exception(Craft::t('app', 'That file does not seem to exist anymore. Re-index the Assets volume and try again.'));
			}

			if (!Craft::$app->user->checkPermission($permission.':'.$file->volumeId))
			{
				throw new Exception(Craft::t('app', 'You don’t have the required permissions for this operation.'));
			}
		}
	}

	/**
	 * Ensure a folder entry exists in the DB for the full path and return it's id.
	 *
	 * @param string $fullPath The path to ensure the folder exists at.
	 *
	 * @return int
	 */
	public function ensureFolderByFullPathAndVolumeId($fullPath, $volumeId)
	{
		$parameters = new FolderCriteria(array(
			'path' => $fullPath,
			'volumeId' => $volumeId
		));

		$folderModel = $this->findFolder($parameters);

		// If we don't have a folder matching these, create a new one
		if (is_null($folderModel))
		{
			$parts = explode('/', rtrim($fullPath, '/'));
			$folderName = array_pop($parts);

			if (empty($parts))
			{
				// Looking for a top level folder, apparently.
				$parameters->path = '';
				$parameters->parentId = ':empty:';
			}
			else
			{
				$parameters->path = join('/', $parts).'/';
			}

			// Look up the parent folder
			$parentFolder = $this->findFolder($parameters);

			if (is_null($parentFolder))
			{
				$parentId = ':empty:';
			}
			else
			{
				$parentId = $parentFolder->id;
			}

			$folderModel = new AssetFolderModel();
			$folderModel->volumeId = $volumeId;
			$folderModel->parentId = $parentId;
			$folderModel->name = $folderName;
			$folderModel->path = $fullPath;

			$this->storeFolderRecord($folderModel);
		}

		return $folderModel->id;
	}

	/**
	 * Store a folder by model
	 *
	 * @param AssetFolderModel $folder
	 *
	 * @return bool
	 */
	public function storeFolderRecord(AssetFolderModel $folder)
	{
		if (empty($folder->id))
		{
			$record = new AssetFolderRecord();
		}
		else
		{
			$record = AssetFolderRecord::findOne(['id' => $folder->id]);
		}

		$record->parentId = $folder->parentId;
		$record->volumeId = $folder->volumeId;
		$record->name = $folder->name;
		$record->path = $folder->path;
		$record->save();

		$folder->id = $record->id;
	}

	// Private Methods
	// =========================================================================

	/**
	 * Returns a DbCommand object prepped for retrieving assets.
	 *
	 * @return Query
	 */
	private function _createFolderQuery()
	{
		return (new Query())
			->select('id, parentId, volumeId, name, path')
			->from('{{%assetfolders}}');
	}

	/**
	 * Return the folder tree form a list of folders.
	 *
	 * @param $folders
	 *
	 * @return array
	 */
	private function _getFolderTreeByFolders($folders)
	{
		$tree = array();
		$referenceStore = array();

		foreach ($folders as $folder)
		{
			/**
			 * @var AssetFolder $folder
			 */
			if ($folder->parentId && isset($referenceStore[$folder->parentId]))
			{
				$referenceStore[$folder->parentId]->addChild($folder);
			}
			else
			{
				$tree[] = $folder;
			}

			$referenceStore[$folder->id] = $folder;
		}

		$sort = array();

		foreach ($tree as $topFolder)
		{
			/**
			 * @var AssetFolder $topFolder
			 */
			$sort[] = $topFolder->getVolume()->sortOrder;
		}

		array_multisort($sort, $tree);

		return $tree;
	}

	/**
	 * Applies WHERE conditions to a DbCommand query for folders.
	 *
	 * @param Query          $query
	 * @param FolderCriteria $criteria
	 *
	 * @return null
	 */
	private function _applyFolderConditions($query, FolderCriteria $criteria)
	{
		$whereConditions = array();
		$whereParams = array();

		if ($criteria->id)
		{
			$whereConditions[] = DbHelper::parseParam('f.id', $criteria->id, $whereParams);
		}

		if ($criteria->volumeId)
		{
			$whereConditions[] = DbHelper::parseParam('f.volumeId', $criteria->volumeId, $whereParams);
		}

		if ($criteria->parentId)
		{
			$whereConditions[] = DbHelper::parseParam('f.parentId', $criteria->parentId, $whereParams);
		}

		if ($criteria->name)
		{
			$whereConditions[] = DbHelper::parseParam('f.name', $criteria->name, $whereParams);
		}

		if (!is_null($criteria->path))
		{
			// This folder has a comma in it.
			if (strpos($criteria->path, ',') !== false)
			{
				// Escape the comma.
				$condition = DbHelper::parseParam('f.path', str_replace(',', '\,', $criteria->path), $whereParams);
				$lastKey = key(array_slice($whereParams, -1, 1, true));

				// Now un-escape it.
				$whereParams[$lastKey] = str_replace('\,', ',', $whereParams[$lastKey]);
			}
			else
			{
				$condition = DbHelper::parseParam('f.path', $criteria->path, $whereParams);
			}

			$whereConditions[] = $condition;
		}

		if (count($whereConditions) == 1)
		{
			$query->where($whereConditions[0], $whereParams);
		}
		else
		{
			array_unshift($whereConditions, 'and');
			$query->where($whereConditions, $whereParams);
		}
	}

	/**
	 * Saves the record for an asset.
	 *
	 * @param Asset $asset
	 *
	 * @throws \Exception
	 * @return bool
	 */
	private function _storeAssetRecord(Asset $asset)
	{
		$isNewAsset = !$asset->id;

		if (!$isNewAsset)
		{
			$assetRecord = AssetRecord::findOne(['id' => $asset->id]);

			if (!$assetRecord)
			{
				throw new AssetMissingException(Craft::t('app', 'No asset exists with the ID “{id}”.', array('id' => $asset->id)));
			}
		}
		else
		{
			$assetRecord = new AssetRecord();
		}

		$assetRecord->volumeId     = $asset->volumeId;
		$assetRecord->folderId     = $asset->folderId;
		$assetRecord->filename     = $asset->filename;
		$assetRecord->kind         = $asset->kind;
		$assetRecord->size         = $asset->size;
		$assetRecord->width        = $asset->width;
		$assetRecord->height       = $asset->height;
		$assetRecord->dateModified = $asset->dateModified;

		$assetRecord->validate();
		$asset->addErrors($assetRecord->getErrors());

		if ($asset->hasErrors())
		{
			$exception = new ModelValidationException(
				Craft::t('app', 'Saving the Asset failed with the following errors: {errors}',
					['errors' => join(', ', $asset->getAllErrors())]
				)
			);

			$exception->setModel($asset);

			throw $exception;
		}

		if ($isNewAsset && !$asset->getContent()->title)
		{
			// Give it a default title based on the file name
			$asset->getContent()->title = str_replace('_', ' ', IOHelper::getFilename($asset->filename, false));
		}

		$transaction = Craft::$app->getDb()->getTransaction() === null ? Craft::$app->getDb()->beginTransaction() : null;

		try
		{
			$event = new AssetEvent(array(
					'asset' => $asset
				)
			);
			$this->trigger(static::EVENT_BEFORE_SAVE_ASSET, $event);

			// Is the event giving us the go-ahead?
			if ($event->performAction)
			{
				// Save the element
				$success = Craft::$app->elements->saveElement($asset, false);

				// If it didn't work, rollback the transaction in case something changed in onBeforeSaveAsset
				if (!$success)
				{
					if ($transaction !== null)
					{
						$transaction->rollback();
					}

					throw new ElementSaveException(Craft::t('app', 'Failed to save the Asset Element'));
				}

				// Now that we have an element ID, save it on the other stuff
				if ($isNewAsset)
				{
					$assetRecord->id = $asset->id;
				}

				// Save the file row
				$assetRecord->save(false);

				$this->trigger(static::EVENT_AFTER_SAVE_ASSET, $event);
			}
			else
			{
				throw new ActionCancelledException(Craft::t('app', 'A plugin cancelled the save operation for {asset}!', array('asset' => $asset->filename)));
			}

			// Commit the transaction regardless of whether we saved the asset, in case something changed
			// in onBeforeSaveAsset
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
}
