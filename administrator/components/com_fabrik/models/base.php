<?php
/**
 * Fabrik Base Admin Model
 *
 * @package     Joomla.Administrator
 * @subpackage  Fabrik
 * @copyright   Copyright (C) 2005-2015 fabrikar.com - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 * @since       3.5
 */
namespace Fabrik\Admin\Models;

use JForm;
use Joomla\Utilities\ArrayHelper;
use JFolder;
use JDate;
use Fabrik\Helpers\Text;
use Fabrik\Storage\MySql as Storage;
use JPluginHelper;
use Fabrik\Helpers\Worker;
use JHTML;
use stdClass;
use Joomla\Registry\Registry;
use JFactory;
use Fabrik\Helpers\String;
use JEventDispatcher;
use Fabrik\Plugins\Element\Element;
use JComponentHelper;
use Fabrik\Admin\Helpers\Fabrik;
use Fabrik\Admin\Models\Join;
use Joomla\String\Inflector;
use JFile;
use Fabrik\Helpers\HTML;
use RuntimeException;
use JModelBase;
use JFilterInput;

/**
 * Fabrik Base Admin Model
 *
 * @package     Joomla.Administrator
 * @subpackage  Fabrik
 * @since       3.5
 */
class Base extends JModelBase
{
	/**
	 * List of items
	 *
	 * @var array
	 */
	protected $items;

	/**
	 * Model name
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * If true encase table and element names with "`" when getting element list
	 *
	 * @var bool
	 */
	protected $addDbQuote = false;

	/**
	 * Form model
	 *
	 * @var object model
	 */
	protected $formModel = null;

	/**
	 * Joins
	 *
	 * @var array
	 */
	private $joins = null;

	/**
	 * Concat string to create full element names
	 *
	 * @var string
	 */
	public $joinTableElementStep = '___';

	/**
	 * Data storage model
	 *
	 * @var Storage|null
	 */
	protected $storage = null;

	/**
	 * @var \JApplicationCms
	 */
	public $app = null;

	/**
	 * @var \Joomla\Input\Input;
	 */
	protected $input = null;

	/**
	 * Instantiate the model.
	 *
	 * @param   Registry $state The model state.
	 *
	 * @since   12.1
	 */
	public function __construct(Registry $state = null)
	{
		parent::__construct($state);
		$this->app     = $this->state->get('app', JFactory::getApplication());
		$this->input = $this->app->input;
		$this->user    = $this->state->get('user', JFactory::getUser());
		$this->config  = $this->state->get('config', JFactory::getConfig());
		$this->session = $this->state->get('session', $session = JFactory::getSession());

		if ($this->name === '')
		{
			$path       = explode('\\', get_class($this));
			$this->name = strtolower(array_pop($path));
		}

		$this->storage = new Storage;

		$this->populateState();
	}

	/**
	 * Method to auto-populate the model state.
	 *
	 * Note. Calling getState in this method will result in recursion.
	 *
	 * @since    1.6
	 *
	 * @return  void
	 */
	protected function populateState()
	{
	}

	/**
	 * Filter the list items based on the stored filter state.
	 *
	 * @param $items
	 *
	 * @return array
	 */
	protected function filterItems($items)
	{
		$items = array_filter($items, function ($item)
		{
			$filters = $this->get('filter');

			foreach ($filters as $field => $value)
			{
				if ($field <> 'search' && $value <> '*' && $value <> '')
				{
					if (isset($item->$field) && $item->$field <> $value)
					{
						return false;
					}
				}

				//return true;
			}

			return true;
		});

		return $items;
	}

	/**
	 * Get the list id
	 *
	 * @return  string  list id
	 */
	public function getId()
	{
		return JFilterInput::getInstance()->clean($this->get('id'), 'WORD');
	}

	/**
	 * Get the item -
	 * if no id set load data template
	 *
	 * @param  string $id
	 *
	 * @return Registry
	 */
	public function getItem($id = null)
	{
		/*if (is_null($id))
		{
			$id = $this->getId();
		}*/
		$id = JFilterInput::getInstance()->clean($this->get('id'), 'WORD');
		// @TODO - save & load from session?

		if ($id === '')
		{
			$json = file_get_contents(JPATH_COMPONENT_ADMINISTRATOR . '/models/schemas/template.json');
		}
		else
		{
			$json = file_get_contents(JPATH_COMPONENT_ADMINISTRATOR . '/models/views/' . $id . '.json');
		}

		$item = json_decode($json);

		return new Registry($item);
	}

	/**
	 * Get list view items.
	 *
	 * @return array
	 */
	public function getItems()
	{
		return $this->getViews();
	}

	protected final function getViews()
	{
		$path  = JPATH_COMPONENT_ADMINISTRATOR . '/models/views';
		$files = JFolder::files($path, '.json', false, true);
		$items = array();

		foreach ($files as $file)
		{
			$json             = file_get_contents($file);
			$item             = json_decode($json);
			$items[$item->id] = $item;
		}

		return $items;
	}

	/**
	 * Get the list pagination object
	 *
	 * @return \JPagination
	 */
	public function getPagination()
	{
		$items = $this->getItems();

		return new \JPagination(count($items), 0, 0);
	}

	/**
	 * Get published elements to show in list
	 *
	 * @return  array
	 */

	public function getPublishedListElements()
	{
		if (!isset($this->publishedListElements))
		{
			$this->publishedListElements = array();
		}

		$input  = $this->app->input;
		$params = $this->getParams();

		// $$$ rob fabrik_show_in_list set in admin module params (will also be set in menu items and content plugins later on)
		// its an array of element ids that should be show. Overrides default element 'show_in_list' setting.
		$showInList = (array) $input->get('fabrik_show_in_list', array(), 'array');
		$sig        = empty($showInList) ? 0 : implode('.', $showInList);

		if (!array_key_exists($sig, $this->publishedListElements))
		{
			$this->publishedListElements[$sig] = array();
			$elements                          = $this->getMyElements();

			foreach ($elements as $elementModel)
			{
				$element = $elementModel->getElement();

				if ($params->get('list_view_and_query', 1) == 1 && $element->get('published') == 1 && $elementModel->canView('list'))
				{
					if (empty($showInList))
					{
						if ($element->get('show_in_list_summary'))
						{
							$this->publishedListElements[$sig][] = $elementModel;
						}
					}
					else
					{
						if (in_array($element->get('id'), $showInList))
						{
							$this->publishedListElements[$sig][] = $elementModel;
						}
					}
				}
			}
		}

		return $this->publishedListElements[$sig];
	}

	/**
	 * Get joins
	 *
	 * @return array join objects (table rows - not table objects or models)
	 */
	public function &getJoins()
	{
		if (!isset($this->joins))
		{
			$item = $this->getItem();
			// FIXME - load element joins as well - and order them after the list joins.
			$this->joins = (array) $item->get('database.joins');
			$this->_makeJoinAliases($this->joins);

			foreach ($this->joins as &$join)
			{
				if (is_string($join->params))
				{
					$join->params = new Registry($join->params);
					$this->setJoinPk($join);
				}
			}
		}

		return $this->joins;
	}

	/**
	 * @param  array|object $post
	 * @param  string  $view
	 *
	 * @return Registry
	 */
	protected function prepareSave($post, $view = null)
	{
		if (is_null($view))
		{
			$view = $this->getKlass();
		}
		if (is_array($post))
		{
			// We are saving from the form submission
			$file        = JPATH_COMPONENT_ADMINISTRATOR . '/models/views/' . ArrayHelper::getValue($post, 'view') . '.json';
			$data        = file_exists($file) ? json_decode(file_get_contents($file)) : new stdClass;
			$post        = ArrayHelper::toObject($post);
			$data->$view = $post;

			if ($view === 'list' || $view === 'form')
			{
				$data->view = $data->$view->view;

				$data->published = $data->$view->published;
				unset($data->$view->view);
			}

		}
		else
		{
			// We are saving from anywhere except the form - in this case the json file is being passed in
			$data = $post;
		}

		return new Registry($data);
	}

	/**
	 * Save the form
	 *
	 * @param   Registry $data A Registry representing the view state
	 *
	 * @return bool
	 * @throws RuntimeException
	 */
	public function save($data)
	{
		// Clear out the form state if save is ok.
		//$this->storeFormState(array());

		//$this->app->enqueueMessage('Saved');
		$this->populateState();
		$input = $this->app->input;
		$user  = JFactory::getUser();
		$date  = JFactory::getDate();

		$data->set('list.order_by', $input->get('order_by', array(), 'array'));
		$data->set('list.order_dir', $input->get('order_dir', array(), 'array'));
		$data->set('checked_out', false);
		$data->set('checked_out_time', '');

		$this->collation($data);

		$file  = JPATH_COMPONENT_ADMINISTRATOR . '/models/views/' . $data->get('view') . '.json';
		$isNew = !JFile::exists($file);

		if (!$isNew)
		{
			$dateNow = JFactory::getDate();
			$data->set('list.modified', $dateNow->toSql());
			$data->set('list.modified_by', $user->get('id'));
		}

		if ($isNew)
		{
			if ($data->get('list.created') == '')
			{
				$data->set('list.created', $date->toSql());
			}

			$newTable = trim($data->get('list._database_name', ''));
			// Mysql will force db table names to lower case even if you set the db name to upper case - so use clean()
			$newTable = String::clean($newTable);

			// Check the entered database table does not already exist
			if ($newTable != '' && $this->storage->tableExists($newTable))
			{
				throw new RuntimeException(Text::_('COM_FABRIK_DATABASE_TABLE_ALREADY_EXISTS'));

				return false;
			}

			if (!$this->storage->canCreate())
			{
				throw new RuntimeException(Text::_('COM_FABRIK_INSUFFICIENT_RIGHTS_TO_CREATE_TABLE'));

				return false;
			}

			// Create fabrik form
			$data->set('form', $this->createLinkedForm($data));

			// Create fabrik group
			$groupData       = Worker::formDefaults('group');
			$groupName       = String::clean($data->get('list.label'));
			$groupData->name = $groupName;

			$data->set('form.groups', new stdClass);
			$data->set('form.groups.' . $groupName, $this->createLinkedGroup($groupData, false));

			if ($newTable == '')
			{
				// New fabrik list but existing db $groupName
				$this->createLinkedElements($data, $groupName);
			}
			else
			{
				$data->set('list.db_table_name', $newTable);
				$data->set('list.auto_inc', 1);

				$dbOpts            = array();
				$params            = new Registry($data->get('list.params'));
				$dbOpts['COLLATE'] = $params->get('collation', '');

				$fields = array('id' => array('plugin' => 'internalid', 'primary_key' => true),
					'date_time' => array('plugin' => 'date'));
				$fields = (array) $this->get('defaultfields', $fields);

				foreach ($fields as $name => $fieldData)
				{
					$data->set('form.groups.' . $groupName . '.fields.' . $name, $this->makeElement($name, $fieldData));
				}

				$res = $this->createDBTable($newTable, $fields, $dbOpts);

				if (is_array($res))
				{
					$data->set('list.db_primary_key', $newTable . '.' . $res[0]);
				}
			}
		}

		Fabrik::prepareSaveDate($data, 'list.publish_down');
		Fabrik::prepareSaveDate($data, 'list.created');
		Fabrik::prepareSaveDate($data, 'list.publish_up');

		$pk = $data->get('list.db_primary_key', '');

		if ($pk == '')
		{
			$pk    = $this->storage->getPrimaryKeyAndExtra();
			$key   = $pk[0]['colname'];
			$extra = $pk[0]['extra'];

			// Store without quoteNames as that is db specific
			if ($key)
			{
				$pk = $data->get('list.db_primary_key') == '' ? $data->get('list.db_table_name') . '.' . $key : $data->get('list.db_primary_key');
			}
			else
			{
				$pk = '';
			}

			$data->set('list.db_primary_key', $pk);

			$data->set('list.auto_inc', String::stristr($extra, 'auto_increment') ? true : false);
		}

		$this->updateJoins($data);

		$storage = $this->getStorage(array('table' => $data->get('list.db_table_name')));

		if (!$storage->isView())
		{
			$storage->updatePrimaryKey($data->get('list.db_primary_key', ''), $data->get('list.auto_inc'));
		}

		$data->set('list._database_name', null);
		$output = json_encode($data->toObject(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		file_put_contents($file, $output);

		// Make an array of elements and a presumed index size, map is then used in creating indexes

		$this->set('id', $data->get('view'));
		$map = $this->fieldSizeMap();
		// FIXME - from here on - not tested for json views

		// Update indexes (added array_key_exists check as these may be during after CSV import)
		if (!empty($aOrderBy) && array_key_exists($data->list->order_by, $map))
		{
			foreach ($aOrderBy as $orderBy)
			{
				if (array_key_exists($orderBy, $map))
				{
					$this->storage->addIndex($orderBy, 'tableorder', 'INDEX', $map[$orderBy]);
				}
			}
		}

		$params = new Registry($data->get('list'));

		if (!is_null($data->get('list.group_by')))
		{
			if ($data->get('list.group_by') !== '' && array_key_exists($data->get('list.group_by'), $map))
			{
				$this->storage->addIndex($data->get('list.group_by'), 'groupby', 'INDEX', $map[$data->get('list.group_by')]);
			}

			if (trim($params->get('group_by_order')) !== '')
			{
				$this->storage->addIndex($params->get('group_by_order'), 'groupbyorder', 'INDEX', $map[$params->get('group_by_order')]);
			}
		}

		$filterFields = (array) $params->get('filter-fields', array());

		foreach ($filterFields as $field)
		{
			if (array_key_exists($field, $map))
			{
				$this->storage->addIndex($field, 'prefilter', 'INDEX', $map[$field]);
			}
		}

		$this->cleanCache('com_fabrik');

		return true;
	}

	/**
	 * Alter the db table's collation
	 *
	 * @param   object $row Row being save
	 *
	 * @since   3.0.7
	 *
	 * @return boolean
	 */
	protected function collation($row)
	{
		// @FIXME - redo this after json view changes.
		return true;

		// Don't attempt to alter new table, or a view, or if we shouldn't alter the table
		if ($row->id == 0 || $this->storage->isView() || !$this->canAlterFields())
		{
			return;
		}

		$params        = new Registry($row->params);
		$origCollation = $params->get('collation', 'none');

		if (!empty($this->storage->table))
		{
			$origCollation = $this->storage->getCollation($origCollation);
		}

		$newCollation = $params->get('collation', 'none');

		if ($newCollation !== $origCollation)
		{
			return $this->storage->setCollation($newCollation);
		}

		return true;
	}

	protected function fieldSizeMap()
	{
		$map       = array();
		$groups    = $this->getGroupsHierarchy();

		foreach ($groups as $groupModel)
		{
			$elementModels = $groupModel->getMyElements();

			foreach ($elementModels as $element)
			{
				// Int and DATETIME elements cant have a index size attribute
				$colType = $element->getFieldDescription();

				if (String::stristr($colType, 'int'))
				{
					$size = '';
				}
				elseif (String::stristr($colType, 'datetime'))
				{
					$size = '';
				}
				else
				{
					$size = '10';
				}

				$map[$element->getFullName(false, false)] = $size;
				$map[$element->getElement()->get('id')]   = $size;
			}
		}

		return $map;
	}

	/**
	 * Get the models active/selected plug-ins
	 *
	 * @param   string $subView
	 *
	 * @return array
	 */
	public function getPlugins($subView = 'list')
	{
		$item = $this->getItem();
		// Load up the active plug-ins
		$plugins = $item->get($subView . '.params.plugins', array());

		return $plugins;
	}

	/**
	 * Create a drop down list of all the elements in the form
	 *
	 * @param   string $name               Drop down name
	 * @param   string $default            Current value
	 * @param   bool   $excludeUnpublished Add elements that are unpublished
	 * @param   bool   $useStep            Concat table name and el name with '___' (true) or "." (false)
	 * @param   bool   $incRaw             Include raw labels default = true
	 * @param   string $key                What value should be used for the option value 'name' (default) or 'id'
	 * @param   string $attribs            Select list attributes @since 3.1b
	 *
	 * @return    string    html list
	 */

	public function getElementList($name = 'order_by', $default = '', $excludeUnpublished = false,
		$useStep = false, $incRaw = true, $key = 'name', $attribs = 'class="inputbox" size="1"')
	{
		$aEls = $this->getElementOptions($useStep, $key, false, $incRaw);
		asort($aEls);

		array_unshift($aEls, JHTML::_('select.option', '', '-'));

		return JHTML::_('select.genericlist', $aEls, $name, $attribs, 'value', 'text', $default);
	}

	/**
	 * Load up the form model
	 *
	 * @return  Form  Form Model
	 */
	public function getFormModel()
	{
		if (is_null($this->formModel) || $this->formModel->get('id') !== $this->get('id'))
		{
			$this->formModel = new Form;

			$this->formModel->set('id', $this->get('id'));
		}

		return $this->formModel;
	}

	/**
	 * Get the groups list model
	 *
	 * @param   string  id - used in visualization model
	 *
	 * @return  object    list model
	 */
	public function getListModel($id = null)
	{
		if (isset($this->listModel))
		{
			return $this->listModel;
		}

		$this->listModel = $this->getFormModel()->getListModel();

		return $this->listModel;
	}

	/**
	 * Get an element
	 *
	 * @param   string $searchName Name to search for
	 * @param   bool   $checkInt   Check search name against element id
	 * @param   bool   $checkShort Check short element name
	 *
	 * @return  mixed  ok: element model not ok: false
	 */
	public function getElement($searchName = '', $checkInt = false, $checkShort = true)
	{
		$groups = $this->getGroupsHierarchy();

		foreach ($groups as $gid => $groupModel)
		{

			$elementModels = $groupModel->getMyElements();

			foreach ($elementModels as $elementModel)
			{
				$element = $elementModel->getElement();

				if ($searchName == $element->get('name') && $checkShort)
				{
					return $elementModel;
				}

				if ($searchName == $elementModel->getFullName(true, false))
				{
					return $elementModel;
				}

				if ($searchName == $elementModel->getFullName(false, false))
				{
					return $elementModel;
				}
			}
		}

		return false;
	}

	/**
	 * Attempts to determine if the form contains the element
	 *
	 * @param   string $searchName Element name to search for
	 * @param   bool   $checkInt   Check search name against element id
	 * @param   bool   $checkShort Check short element name
	 *
	 * @return  bool  true if found, false if not found
	 */
	public function hasElement($searchName, $checkInt = false, $checkShort = true)
	{
		return $this->getElement($searchName, $checkInt, $checkShort) === false ? false : true;
	}

	/**
	 * Set a state property
	 *
	 * @param   string $key
	 * @param   mixed  $value
	 *
	 * @return  mixed  The value that has been set
	 */
	public function set($key, $value)
	{
		return $this->state->set($key, $value);
	}

	/**
	 * Get a state property
	 *
	 * @param        $key
	 * @param string $default
	 *
	 * @return mixed
	 */
	public function get($key, $default = '')
	{
		return $this->state->get($key, $default);
	}

	/**
	 * Method to get the record form.
	 *
	 * @return  JForm    A JForm object on success, false on failure
	 */
	public function getForm()
	{
		return $this->loadForm($this->name);
	}

	/**
	 * Load a form
	 *
	 * @param string $name
	 * @param array  $options
	 *
	 * @return JForm
	 */
	public function loadForm($name, $options = array())
	{
		JForm::addFormPath(JPATH_COMPONENT . '/models/forms');
		JForm::addFieldPath(JPATH_COMPONENT . '/models/fields');
		JForm::addFormPath(JPATH_COMPONENT . '/model/form');
		JForm::addFieldPath(JPATH_COMPONENT . '/model/field');

		if (empty($options))
		{
			$options = array('control' => 'jform', 'load_data' => true);
		}

		$form = JForm::getInstance('com_fabrik.' . $name, $name, $options, false, false);
		$item = $this->getItem();

		$class = $this->getKlass();
		$data  = $this->getItem()->get($class);

		$data->view = $item->get('view');
		$form->bind($data);
		$form->model = $this;

		return $form;
	}

	/**
	 * Get the short class name which maps to the view json files key
	 * So lizt is returned as list
	 *
	 * @return array|string
	 */
	private function getKlass()
	{
		$inflector = Inflector::getInstance();
		$class     = explode("\\", get_class($this));
		$class     = strtolower(array_pop($class));

		if ($class === 'lizt')
		{
			$class = 'list';
		}

		$class2 = $inflector->toSingular($class);

		if ($class2 != '')
		{
			$class = $class2;
		}

		if ($class === 'lists')
		{
			// Inflector not working for lists.
			$class = 'list';
		}

		return $class;
	}

	/**
	 * Store the submitted form's data in the session.
	 * Used in getItem() to load submitted form data
	 * after a failed submission
	 *
	 * @param   array|object $data
	 *
	 * @return  void
	 */
	public function storeFormState($data)
	{
		if (is_array($data))
		{
			$data = ArrayHelper::toObject($data);
		}

		$this->app->setUserState('com_fabrik.edit.' . $this->name . '.data', $data);
	}

	/**
	 * Validate the form data, set's errors if found.
	 *
	 * @param   array $data Posted form data
	 *
	 * @return boolean
	 */
	public function validate($data)
	{
		$form  = $this->getForm();
		$valid = $form->validate($data);
		$this->set('errors', $form->getErrors());

		if (!$valid)
		{
			// Store the submitted data to the session so we can reload it after the redirect
			$this->storeFormState($data);
		}

		return $valid;
	}

	/**
	 * Prepare the data for saving. Run after validation
	 *
	 * @param  array &$data
	 *
	 * @return array
	 */
	public function prepare(&$data)
	{
		return $data;
	}

	/**
	 * Unpublish items
	 *
	 * @param array $ids
	 */
	public function unpublish($ids = array())
	{
		$items = $this->getItems();

		foreach ($ids as $id)
		{
			$items[$id]->published = 0;
			$items[$id]->id        = $id;
			$items[$id]            = $this->prepareSave($items[$id]);
			$this->save($items[$id]);
		}
	}

	/**
	 * Publish items
	 *
	 * @param array $ids
	 */
	public function publish($ids = array())
	{
		$items = $this->getItems();

		foreach ($ids as $id)
		{
			$items[$id]->published = 1;
			$items[$id]->id        = $id;
			$items[$id]            = $this->prepareSave($items[$id]);
			$this->save($items[$id]);
		}
	}

	/**
	 * Check in the item
	 *
	 * @return bool
	 */
	public function checkin()
	{
		$item = $this->getItem();
		$view = $item->get('view');
		$item->set('checked_out', '');
		$item->set('checked_out_time', '');
		$item   = $item->toObject();
		$output = json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$file   = JPATH_COMPONENT_ADMINISTRATOR . '/models/views/' . $view . '.json';

		return file_put_contents($file, $output);
	}

	/**
	 * Checkout the item
	 *
	 * @return bool
	 */
	public function checkout()
	{
		$now  = new JDate;
		$item = $this->getItem();
		$view = $item->get('view');
		$item->set('checked_out', $this->user->get('id'));
		$item->set('checked_out_time', $now->toSql());
		$item   = $item->toObject();
		$output = json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$file   = JPATH_COMPONENT_ADMINISTRATOR . '/models/views/' . $view . '.json';

		return file_put_contents($file, $output);
	}

	public function cleanCache($option)
	{
		// FIXME - needs to be implemented - copy from JModelLegacy?
	}

	/**
	 * Can the user perform a core action on the model
	 *
	 * @param   string   $task Action to perform e.g 'delete'
	 * @param   stdClass $item Item
	 *
	 * @return mixed
	 */
	protected function can($task, $item = null)
	{
		return $this->user->authorise('core.' . $task, 'com_fabrik');
	}

	/**
	 * Delete main json files.
	 *
	 * @param   array $ids File names
	 *
	 * @return  int  Number of deleted files
	 */
	public function delete($ids)
	{
		// Include the content plugins for the on delete events.
		JPluginHelper::importPlugin('content');
		$dispatcher = JEventDispatcher::getInstance();
		$count      = 0;

		if (!$this->can('delete'))
		{
			$this->app->enqueueMessage(Text::_('JLIB_APPLICATION_ERROR_EDIT_STATE_NOT_PERMITTED'));

			return $count;
		}

		foreach ($ids as $id)
		{
			$dispatcher->trigger('onContentBeforeDelete', array('com_fabrik.list', $id));
			$file = JPATH_COMPONENT_ADMINISTRATOR . '/models/views/' . $id . '.json';

			if (JFile::delete($file))
			{
				$count++;
			}

			$dispatcher->trigger('onContentAfterDelete', array('com_fabrik.list', $id));
		}

		return $count;
	}

	/**
	 * Drop a series of lists' tables.
	 *
	 * @param   array $ids List references
	 *
	 * @return  bool
	 */
	public function drop($ids = array())
	{
		JPluginHelper::importPlugin('content');
		$dispatcher = JEventDispatcher::getInstance();
		$dbPrefix   = $this->app->get('dbprefix');

		foreach ($ids as $id)
		{
			$item  = $this->getItem($id);
			$table = $item->get('list.db_table_name');
			$dispatcher->trigger('onContentBeforeDrop', array('com_fabrik.list', $id));

			if (strncasecmp($table, $dbPrefix, String::strlen($dbPrefix)) == 0)
			{
				$this->app->enqueueMessage(Text::sprintf('COM_FABRIK_TABLE_NOT_DROPPED_PREFIX', $table, $dbPrefix), 'notice');
			}
			else
			{
				$storage = $this->getStorage(array('table' => $table));
				$storage->drop();

				// Remove any groups that were set to be repeating and hence were storing in their own db table.
				$joinModels = $this->getInternalRepeatJoins();

				foreach ($joinModels as $joinModel)
				{
					if ($joinModel->getJoin()->table_join !== '')
					{
						$storage->drop($joinModel->getJoin()->table_join);
					}
				}

				$this->app->enqueueMessage(Text::sprintf('COM_FABRIK_TABLE_DROPPED', $table));
			}

			$dispatcher->trigger('onContentAfterDrop', array('com_fabrik.list', $id));
		}

		return true;
	}

	/**
	 * Get the storage model
	 *
	 * @param array $options
	 *
	 * @return Storage
	 */
	protected function getStorage($options = array())
	{
		if (!isset($this->storage))
		{
			if (!array_key_exists('db', $options))
			{
				$options['db'] = $this->getDb();
			}
			$this->storage = new Storage($options);
		}

		return $this->storage;
	}

	/**
	 * When saving a list that links to a database for the first time we
	 * automatically create a form to allow the update/creation of that tables
	 * records
	 *
	 * @param   Registry $view   View containing all info
	 * @param   int       $formId to copy from. If = 0 then create a default form. If not 0 then copy the form id
	 *                            passed in
	 *
	 * @return  object  form model
	 */
	protected function createLinkedForm($view, $formId = 0)
	{
		$user = JFactory::getUser();
		$this->getFormModel();

		if ($formId == 0)
		{
			$this->formModel->getForm();
			jimport('joomla.utilities.date');
			$createDate = JFactory::getDate();
			$createDate = $createDate->toSql();

			$form = Worker::formDefaults('form');

			$form->id                  = uniqid();
			$form->label               = $view->get('list.label');
			$form->record_in_database  = 1;
			$form->created             = $createDate;
			$form->created_by          = $user->get('id');
			$form->created_by_alias    = $user->get('username');
			$form->error               = Text::_('COM_FABRIK_FORM_ERROR_MSG_TEXT');
			$form->submit_button_label = Text::_('COM_FABRIK_SAVE');
			$form->published           = $view->get('list.published');
			$form->form_template       = 'bootstrap';
			$form->view_only_template  = 'bootstrap';
		}
		else
		{
			// @TODO json view this.
			$this->set('list.form_id', $formId);
			$this->formModel->setId($formId);
			$this->formModel->getTable();
			$this->formModel->copy();
		}

		return $form;
	}

	/**
	 * Create a group
	 * used when creating a fabrik table from an existing db table
	 *
	 * @param   stdClass $data     group data
	 * @param   bool     $isJoin   is the group a join default false
	 * @param   bool     $isRepeat is the group repeating
	 *
	 * @return  stdClass Group
	 */

	protected function createLinkedGroup($data, $isJoin = false, $isRepeat = false)
	{
		$user             = JFactory::getUser();
		$createDate       = JFactory::getDate();
		$data->created    = $createDate->toSql();
		$data->created_by = $user->get('id');
		if (!isset($data->fields))
		{
			$data->fields = new stdClass;
		}

		$data->id                      = uniqid();
		$data->created_by_alias        = $user->get('username');
		$data->published               = 1;
		$opts                          = new stdClass;
		$data->repeat_group_button     = $isRepeat ? 1 : 0;
		$opts->repeat_group_show_first = 1;
		$data->params                  = $opts;
		$data->is_join                 = ($isJoin == true) ? 1 : 0;

		return $data;
	}

	/**
	 * When saving a list that links to a database for the first time we
	 * need to create all the elements based on the database table fields and their
	 * column type
	 *
	 * @param   Registry $data      JSON view data
	 * @param   string    $groupName Group name
	 * @param   string    $table     Table name - if not set then use jform's db_table_name (@since 3.1)
	 *
	 * @return  void
	 */
	protected function createLinkedElements(&$data, $groupName = '', $table = '')
	{
		$table    = $table === '' ? $data->get('list.db_table_name') : $table;
		$elements = $this->makeElementsFromFields(0, $table);

		if ($groupName === '')
		{
			$groupData = new stdClass;
			$groupName = $data->get('list.label');
			$groupData->$groupName;
			$data->set('form.groups.' . $groupName, $this->createLinkedGroup($groupData, false));
		}

		$data->set('form.groups.' . $groupName . '.fields', $elements);
	}

	/**
	 * Take a table name and make elements for all of its fields
	 *
	 * @param   int    $groupId   group id
	 * @param   string $tableName table name
	 *
	 * @return  object  elements
	 */
	protected function makeElementsFromFields($groupId, $tableName)
	{
		$elements     = new stdClass;
		$fabrikDb     = $this->storage->db;
		$dispatcher   = JEventDispatcher::getInstance();
		$input        = $this->app->input;
		$elementModel = new Element($dispatcher);
		$user         = JFactory::getUser();
		$fbConfig     = JComponentHelper::getParams('com_fabrik');
		$elementTypes = $input->get('elementtype', array(), 'array');
		$fields       = $fabrikDb->getTableColumns($tableName, false);
		$createDate   = JFactory::getDate()->toSQL();
		$key          = $this->storage->getPrimaryKeyAndExtra($tableName);
		$ordering     = 0;
		/**
		 * no existing fabrik table so we take a guess at the most
		 * relevant element types to  create
		 */
		$elementLabels = $input->get('elementlabels', array(), 'array');

		foreach ($fields as $label => $properties)
		{
			$plugin     = 'field';
			$type       = $properties->Type;
			$maxLength  = 255;
			$maxLength2 = 0;

			if (preg_match("/\((.*)\)/i", $type, $matches))
			{
				$maxLength = ArrayHelper::getValue($matches, 1, 255);
				$maxLength = explode(',', $maxLength);

				if (count($maxLength) > 1)
				{
					$maxLength2 = $maxLength[1];
					$maxLength  = $maxLength[0];
				}
				else
				{
					$maxLength  = $maxLength[0];
					$maxLength2 = 0;
				}
			}

			// Get the basic type
			$type    = explode(" ", $type);
			$type    = ArrayHelper::getValue($type, 0, '');
			$type    = preg_replace("/\((.*)\)/i", '', $type);
			$element = file_get_contents(JPATH_COMPONENT_ADMINISTRATOR . '/models/schemas/element.json');
			$element = json_decode($element);

			if (array_key_exists($ordering, $elementTypes))
			{
				// If importing from a CSV file then we have user select field definitions
				$plugin = $elementTypes[$ordering];
			}
			else
			{
				// If the field is the primary key and it's an INT type set the plugin to be the fabrik internal id
				if ($key[0]['colname'] == $label && String::strtolower(substr($key[0]['type'], 0, 3)) === 'int')
				{
					$plugin = 'internalid';
				}
				else
				{
					// Otherwise set default type
					switch ($type)
					{
						case "int":
						case "decimal":
						case "tinyint":
						case "smallint":
						case "mediumint":
						case "bigint":
						case "varchar":
						case "time":
							$plugin = 'field';
							break;
						case "text":
						case "tinytext":
						case "mediumtext":
						case "longtext":
							$plugin = 'textarea';
							break;
						case "datetime":
						case "date":
						case "timestamp":
							$plugin = 'date';
							break;
						default:
							$plugin = 'field';
							break;
					}
				}
				// Then alter if defined in Fabrik global config
				// Jaanus: but first check if there are any pk field and if yes then create as internalid
				$defType = String::strtolower(substr($key[0]['type'], 0, 3));
				$plugin  = ($key[0]['colname'] == $label && $defType === 'int') ? 'internalid' : $fbConfig->get($type, $plugin);
			}

			$element->id                   = uniqid();
			$element->plugin               = $plugin;
			$element->hidden               = $element->label == 'id' ? '1' : '0';
			$element->group_id             = $groupId;
			$element->name                 = $label;
			$element->created              = $createDate;
			$element->created_by           = $user->get('id');
			$element->created_by_alias     = $user->get('username');
			$element->published            = '1';
			$element->show_in_list_summary = '1';

			switch ($plugin)
			{
				case 'textarea':
					$element->width = '40';
					break;
				case 'date':
					$element->width = '10';
					break;
				default:
					$element->width = '30';
					break;
			}

			if ($element->width > $maxLength)
			{
				$element->width = $maxLength;
			}

			$element->height = '6';
			$p               = $elementModel->getDefaultAttribs();

			if (in_array($type, array('int', 'tinyint', 'smallint', 'mediumint', 'bigint')) && $plugin == 'field')
			{
				$p->integer_length = $maxLength;
				$p->text_format    = 'integer';
				$p->maxlength      = '255';
				$element->width    = '30';
			}
			elseif ($type == 'decimal' && $plugin == 'field')
			{
				$p->text_format    = 'decimal';
				$p->decimal_length = $maxLength2;
				$p->integer_length = $maxLength - $maxLength2;
				$p->maxlength      = '255';
				$element->width    = '30';
			}
			else
			{
				$p->maxlength = $maxLength;
			}

			$element->params = $p;
			$element->label  = ArrayHelper::getValue($elementLabels, $ordering, str_replace('_', ' ', $label));

			//Format Label
			$labelConfig = $fbConfig->get('format_labels', '0');
			switch ($labelConfig)
			{
				case '1':
					$element->label = strtolower($element->label);
					break;
				case '2':
					$element->label = ucwords($element->label);
					break;
				case '3':
					$element->label = ucfirst($element->label);
					break;
				case '4':
					$element->label = strtoupper($element->label);
					break;
				default:
					break;
			}

			$name            = $element->name;
			$elements->$name = $element;

			// FIXME - test what happens on save for user element etc.
			/*$element->store();
			$elementModel = $pluginManager->getPlugIn($element->plugin, 'element');
			$elementModel->setId($element->id);
			$elementModel->element = $element;

			// Hack for user element
			$details = array('group_id' => $element->group_id);
			$input->set('details', $details);
			$elementModel->onSave(array());*/
			$ordering++;
		}

		return $elements;
	}

	/**
	 * Load the database object associated with the list
	 *
	 * @since   3.0b
	 *
	 * @return  object database
	 */
	public function getDb()
	{
		$listId = $this->get('list.id');
		$item   = $this->getItem($listId);

		return Worker::getConnection($item)->getDb();
	}

	/**
	 * Deals with ensuring joins are managed correctly when table is saved
	 *
	 * @param   array $data jform data
	 *
	 * @return  void
	 */

	private function updateJoins($data)
	{
		// FIXME - test for json view;
		return;

		$db    = Worker::getDbo(true);
		$query = $db->getQuery(true);

		// If we are creating a new list then don't update any joins - can result in groups and elements being removed.
		if ((int) $this->get('list.id') === 0)
		{
			return;
		}
		// $$$ hugh - added "AND element_id = 0" to avoid fallout from "random join and group deletion" issue from May 2012
		// FIXME - jsonify
		$query->select('*')->from('#__fabrik_joins')->where('list_id = ' . (int) $this->get('list.id') . ' AND element_id = 0');
		$db->setQuery($query);
		$aOldJoins       = $db->loadObjectList();
		$params          = $data['params'];
		$aOldJoinsToKeep = array();
		$joinModel       = new Join;
		$joinIds         = ArrayHelper::getValue($params, 'join_id', array());
		$joinTypes       = ArrayHelper::getValue($params, 'join_type', array());
		$joinTableFrom   = ArrayHelper::getValue($params, 'join_from_table', array());
		$joinTable       = ArrayHelper::getValue($params, 'table_join', array());
		$tableKey        = ArrayHelper::getValue($params, 'table_key', array());
		$joinTableKey    = ArrayHelper::getValue($params, 'table_join_key', array());
		$repeats         = ArrayHelper::getValue($params, 'join_repeat', array());
		$jc              = count($joinTypes);

		// Test for repeat elements to ensure their join isn't removed from here
		foreach ($aOldJoins as $oldJoin)
		{
			if ($oldJoin->params !== '')
			{
				$oldParams = json_decode($oldJoin->params);

				if (isset($oldParams->type) && $oldParams->type == 'repeatElement')
				{
					$aOldJoinsToKeep[] = $oldJoin->id;
				}
			}
		}

		for ($i = 0; $i < $jc; $i++)
		{
			$existingJoin = false;
			$thisJoin     = false;

			foreach ($aOldJoins as $oOldJoin)
			{
				if ($joinIds[$i] == $oOldJoin->id)
				{
					$existingJoin   = true;
					$joinsToIndex[] = $oOldJoin;
					break;
				}
			}

			if (!$existingJoin)
			{
				$joinsToIndex[] = $this->makeNewJoin($tableKey[$i], $joinTableKey[$i], $joinTypes[$i], $joinTable[$i], $joinTableFrom[$i], $repeats[$i][0]);
			}
			else
			{
				/* load in the exisitng join
				 * if the table_join has changed we need to create a new join
				 * (with its corresponding group and elements)
				 *  and mark the loaded one as to be deleted
				 */
				$joinModel->setId($joinIds[$i]);
				$joinModel->clearJoin();
				$join = $joinModel->getJoin();

				if ($join->table_join != $joinTable[$i])
				{
					$this->makeNewJoin($tableKey[$i], $joinTableKey[$i], $joinTypes[$i], $joinTable[$i], $joinTableFrom[$i], $repeats[$i][0]);
				}
				else
				{
					// The table_join has stayed the same so we simply update the join info
					$join->table_key      = str_replace('`', '', $tableKey[$i]);
					$join->table_join_key = $joinTableKey[$i];
					$join->join_type      = $joinTypes[$i];
					$join->store();

					// Update group
					$group = $this->getTable('Group');
					$group->load($join->group_id);
					$gparams                      = json_decode($group->params);
					$gparams->repeat_group_button = $repeats[$i][0] == 1 ? 1 : 0;
					$group->params                = json_encode($gparams);
					$group->store();
					$aOldJoinsToKeep[] = $joinIds[$i];
				}
			}
		}
		// Remove non existing joins
		if (is_array($aOldJoins))
		{
			foreach ($aOldJoins as $oOldJoin)
			{
				if (!in_array($oOldJoin->id, $aOldJoinsToKeep))
				{
					// Delete join
					$join = $this->getTable('Join');
					$joinModel->setId($oOldJoin->id);
					$joinModel->clearJoin();
					$joinModel->getJoin();
					$joinModel->deleteAll($oOldJoin->group_id);
				}
			}
		}

		// And finally, Esther ... index the join FK's
		foreach ($joinsToIndex as $thisJoin)
		{
			$fields  = $this->storage->getDBFields($thisJoin->table_join, 'Field');
			$fkField = ArrayHelper::getValue($fields, $thisJoin->table_join_key, false);
			switch ($pkField->BaseType)
			{
				case 'VARCHAR':
					$fkSize = (int) $fkField->BaseLength < 10 ? $fkField->BaseLength : 10;
					break;
				case 'INT':
				case 'DATETIME':
				default:
					$fkSize = '';
					break;
			}
			$joinField = $thisJoin->table_join . '___' . $thisJoin->table_join_key;
			$this->getStorage()->addIndex($joinField, 'join_fk', 'INDEX', $fkSize);
		}
	}

	public function getFormOptions()
	{
		$items   = $this->getViews();
		$options = array();

		foreach ($items as $item)
		{
			$item          = new Registry($item);
			$option        = new stdClass;
			$option->value = $item->get('view');
			$option->text  = $item->get('form.label');
			$options[]     = $option;
		}

		return $options;
	}

	/**
	 * Method to copy one or more records.
	 *
	 * @param  array &$ids  Ids to copy
	 * @param  array $names Old to new name map.
	 *
	 * @throws \Exception
	 *
	 * @return  boolean    True if successful, false if an error occurs.
	 */
	public function copy(&$ids, $names)
	{
		$db         = $this->getDb();
		$nullDate   = $db->getNullDate();
		$user       = JFactory::getUser();
		$createDate = JFactory::getDate();
		$createDate = $createDate->toSql();

		foreach ($ids as $i => $pk)
		{
			$item     = $this->getItem($pk);
			$listName = $names[$pk]['listLabel'];
			$item->set('id', $listName);

			$item->set('list.created_by', $user->get('id'));
			$item->set('list.created', $createDate);
			$item->set('list.created_by_alias', $user->get('name'));
			$item->set('list.modified', $nullDate);
			$item->set('list.modified_by', '');
			$item->set('list.label', $listName);

			$item->set('form.label', $names[$pk]['formLabel']);
			$item->set('form.created', $createDate);
			$item->set('form.created_by_alias', $user->get('name'));
			$item->set('form.modified', $nullDate);
			$item->set('form.modified_by', '');

			foreach ($names[$pk]['groupNames'] as $groupId => $viewName)
			{
				$groupKey = 'form.groups.' . $groupId;
				$item->set($groupKey . '.name', $viewName);
				$item->set($groupKey . '.created', $createDate);
				$item->set($groupKey . '.created_by_alias', $user->get('name'));
				$item->set($groupKey . '.modified', $nullDate);
				$item->set($groupKey . '.modified_by', '');
			}

			$file = JPATH_COMPONENT_ADMINISTRATOR . '/models/views/' . $listName . '.json';

			if (JFile::exists($file))
			{
				throw new \Exception('Can not copy to ' . $pk . ', the file already exists');

				return false;
			}

			$output = json_encode($item->toObject(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			file_put_contents($file, $output);
		}

		return true;
	}

	/**
	 * Get an array of join models relating to the groups which were set to be repeating and thus thier data
	 * stored in a separate db table
	 *
	 * @return  \Fabrik\Admin\Models\Join[]  join models.
	 */
	public function getInternalRepeatJoins()
	{
		$return      = array();
		$groupModels = $this->getGroupsHierarchy();

		// Remove any groups that were set to be repeating and hence were storing in their own db table.
		foreach ($groupModels as $groupModel)
		{
			if ($groupModel->isJoin())
			{
				$joinModel  = $groupModel->getJoinModel();
				$join       = $joinModel->getJoin();
				$joinParams = is_string($join->params) ? json_decode($join->params) : $join->params;

				if (isset($joinParams->type) && $joinParams->type === 'group')
				{
					$return[] = $joinModel;
				}
			}
		}

		return $return;
	}

	/**
	 * As you may be joining to multiple versions of the same db table we need
	 * to set the various database name alaises that our SQL query will use
	 *
	 * @param   array &$joins joins
	 *
	 * @return  void
	 */
	protected function _makeJoinAliases(&$joins)
	{
		$prefix      = $this->app->get('dbprefix');
		$table       = $this->getItem();
		$aliases     = array($table->get('list.db_table_name'));
		$tableGroups = array();

		// Build up the alias and $tableGroups array first
		foreach ($joins as &$join)
		{
			$join->canUse = true;

			if ($join->table_join == '#__users' || $join->table_join == $prefix . 'users')
			{
				if (!$this->inJDb())
				{
					/* $$$ hugh - changed this to pitch an error and bang out, otherwise if we just set canUse to false, our getData query
					 * is just going to blow up, with no useful warning msg.
					* This is basically a bandaid for corner case where user has (say) host name in J!'s config, and IP address in
					* our connection details, or vice versa, which is not uncommon for 'locahost' setups,
					* so at least I'll know what the problem is when they post in the forums!
					*/

					$join->canUse = false;
				}
			}
			// $$$ rob Check for repeat elements In list view we don't need to add the join
			// as the element data is concatenated into one row. see elementModel::getAsField_html()
			$opts = json_decode($join->params);

			if (isset($opts->type) && $opts->type == 'repeatElement')
			{
				$join->canUse = false;
			}

			$tableJoin = str_replace('#__', $prefix, $join->table_join);

			if (in_array($tableJoin, $aliases))
			{
				$base = $tableJoin;
				$a    = $base;
				$c    = 0;

				while (in_array($a, $aliases))
				{
					$a = $base . '_' . $c;
					$c++;
				}

				$join->table_join_alias = $a;
			}
			else
			{
				$join->table_join_alias = $tableJoin;
			}

			$aliases[] = str_replace('#__', $prefix, $join->table_join_alias);

			if (!array_key_exists($join->group_id, $tableGroups))
			{
				if ($join->element_id == 0)
				{
					$tableGroups[$join->group_id] = $join->table_join_alias;
				}
			}
		}

		foreach ($joins as &$join)
		{
			// If they are element joins add in this table's name as the calling joining table.
			if ($join->join_from_table == '')
			{
				$join->join_from_table = $table->get('list.db_table_name');
			}

			/*
			 * Test case:
			* you have a table that joins to a 2nd table
			* in that 2nd table there is a database join element
			* that 2nd elements key needs to point to the 2nd tables name and not the first
			*
			* e.g. when you want to create a n-n relationship
			*
			* events -> (table join) events_artists -> (element join) artist
			*/

			$join->keytable = $join->join_from_table;

			if (array_key_exists($join->group_id, $tableGroups))
			{
				if ($join->element_id != 0)
				{
					$join->keytable        = $tableGroups[$join->group_id];
					$join->join_from_table = $join->keytable;
				}
			}
		}

		HTML::debug($joins, 'joins');
	}

}