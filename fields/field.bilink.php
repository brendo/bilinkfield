<?php

	if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

	class FieldBiLink extends Field {
		protected $_driver = null;

		protected static $ready = false;
		protected static $db = null;
		protected static $em = null;
		protected static $fm = null;
		protected static $sm = null;

		private static $cacheSections = array();
		private static $cacheEntries = array();
		private static $cacheFields = array();

	/*-------------------------------------------------------------------------
		Definition:
	-------------------------------------------------------------------------*/

		public function __construct(&$parent) {
			parent::__construct($parent);

			$this->_name = 'Bi-Link';
			$this->_required = true;

			if (class_exists('Frontend')) {
				$symphony = Frontend::instance();
			}
			else {
				$symphony = Administration::instance();
			}

			if (!self::$ready) {
				self::$db = Symphony::Database();
				self::$em = new EntryManager($symphony);
				self::$fm = new FieldManager($symphony);
				self::$sm = new SectionManager($symphony);
			}

			// Set defaults:
			$this->set('show_column', 'yes');
		}

		public function createTable() {
			$field_id = $this->get('id');

			return self::$db->query("
				CREATE TABLE IF NOT EXISTS `tbl_entries_data_{$field_id}` (
					`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`entry_id` INT(11) UNSIGNED NOT NULL,
					`linked_entry_id` INT(11) UNSIGNED DEFAULT NULL,
					PRIMARY KEY (`id`),
					KEY `entry_id` (`entry_id`),
					KEY `linked_entry_id` (`linked_entry_id`)
				)
			");
		}

		public function canFilter() {
			return true;
		}

		public function allowDatasourceParamOutput() {
			return true;
		}

		public function entryDataCleanup($entry_id, $data = null) {
			$field_id = $this->get('id');

			$entries = self::$cacheEntries[$entry_id . "-" . $field_id] = self::$db->fetchCol('linked_entry_id',
				sprintf("
					SELECT
						f.linked_entry_id
					FROM
						`tbl_entries_data_{$field_id}` AS f
					WHERE
						f.entry_id = '{$entry_id}'
				")
			);

			foreach ($entries as $linked_entry_id) {
				if (is_null($linked_entry_id)) continue;

				$linked_section_id = $this->get('linked_section_id');
				$entry = self::cachedEntry($linked_entry_id, $linked_section_id);

				if (!is_object($entry)) continue;

				$values = $entry->getData($this->get('linked_field_id'));

				if (array_key_exists('linked_entry_id', $values)) {
					$values = $values['linked_entry_id'];
				}

				if (is_null($values)) {
					$values = array();

				} else if (!is_array($values)) {
					$values = array($values);
				}

				$values = array_diff($values, array($entry_id));

				$entry->setData($this->get('linked_field_id'), array(
					'linked_entry_id'	=> $values
				));
				$entry->commit();
			}

			return parent::entryDataCleanup($entry_id, $data);
		}

	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/

		protected function getFields() {
			$section = self::cachedSection($this->get('linked_section_id'));

			if (empty($section)) return null;

			return $section->fetchFields();
		}

	/*-------------------------------------------------------------------------
		Settings:
	-------------------------------------------------------------------------*/

		public function findDefaults(&$fields) {
			if (!isset($fields['allow_multiple'])) $fields['allow_multiple'] = 'yes';
			if (!isset($fields['column_size'])) $fields['column_size'] = 'medium';
		}

		public function findOptions() {
		  	$sections = self::$sm->fetch(null, 'ASC', 'name');
			$groups = $options = array();

			if (is_array($sections) and !empty($sections)) {
				foreach ($sections as $section) {
					$groups[$section->get('id')] = array(
						'fields'	=> $section->fetchFields(),
						'section'	=> $section
					);
				}
			}

			$options[] = array('', '', __('None'));

			foreach ($groups as $group) {
				if (!is_array($group['fields'])) continue;

				$fields = array();

				foreach ($group['fields'] as $field) {
					if (
						$field->get('type') == 'bilink'
						and $field->get('id') != $this->get('id')
					) {
						$selected = $this->get('linked_field_id') == $field->get('id');
						$fields[] = array(
							$field->get('id'), $selected, $field->get('label')
						);
					}
				}

				if (empty($fields)) continue;

				$options[] = array(
					'label'		=> $group['section']->get('name'),
					'options'	=> $fields
				);
			}

			return $options;
		}

		public function findModes() {
			$modes = array(
				array('count', false, 'Entry Count'),
				array('first-item', false, 'First Item'),
				array('last-item', false, 'Last Item')
			);

			foreach ($modes as &$mode) {
				$mode[1] = ($mode[0] == $this->get('column_mode'));
			}

			return $modes;
		}

		public function displaySettingsPanel(&$wrapper, $errors = null) {
			parent::displaySettingsPanel($wrapper, $errors);

			$field_id = $this->get('id');
			$order = $this->get('sortorder');

		// Linked -------------------------------------------------------------

			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');

			$label = Widget::Label(__('Options'));

			$label->appendChild(Widget::Select(
				"fields[{$order}][linked_field_id]", $this->findOptions()
			));

			if (isset($errors['linked_field_id'])) {
				$label = Widget::wrapFormElementWithError($label, $errors['linked_field_id']);
			}

			$group->appendChild($label);

		// Column Mode --------------------------------------------------------

			$label = Widget::Label(__('Column Mode'));

			$label->appendChild(Widget::Select(
				"fields[{$order}][column_mode]", $this->findModes()
			));

			if (isset($errors['column_mode'])) {
				$label = Widget::wrapFormElementWithError($label, $errors['column_mode']);
			}

			$group->appendChild($label);
			$wrapper->appendChild($group);

		// Allow Multiple -----------------------------------------------------

			$label = Widget::Label();
			$input = Widget::Input(
				"fields[{$order}][allow_multiple]", 'yes', 'checkbox'
			);

			if ($this->get('allow_multiple') == 'yes') $input->setAttribute('checked', 'checked');

			$label->setValue($input->generate() . ' ' . __('Allow selection of multiple options'));

			$wrapper->appendChild($label);
			$this->appendShowColumnCheckbox($wrapper);
			$this->appendRequiredCheckbox($wrapper);
		}

		public function commit() {
			if (!parent::commit() or $field_id === false) return false;

			$field_id = $this->get('id');
			$handle = $this->handle();

			$linked_field_id = $this->get('linked_field_id');
			$linked_section_id = self::$db->fetchVar('parent_section', 0, "
				SELECT
					f.parent_section
				FROM
					`tbl_fields` AS f
				WHERE
					f.id = {$linked_field_id}
				LIMIT 1
			");

			$fields = array(
				'field_id'			=> $this->get('id'),
				'linked_section_id'	=> $linked_section_id,
				'linked_field_id'	=> $linked_field_id,
				'allow_multiple'	=> ($this->get('allow_multiple') ? $this->get('allow_multiple') : 'no'),
				'column_mode'		=> $this->get('column_mode')
			);

		// Cleanup ------------------------------------------------------------

			self::$db->query("
				DELETE FROM
					`tbl_fields_{$handle}`
				WHERE
					`field_id` = '$field_id'
				LIMIT 1
			");

		// Create -------------------------------------------------------------

			if (!self::$db->insert($fields, "tbl_fields_{$handle}")) return false;

			// Update child field:
			if ($linked_field_id) {
				$field = self::cachedFields($linked_field_id);

				if (is_object($field) and $field->get('linked_field_id') != $field_id) {
					$field->set('linked_section_id', $this->get('parent_section'));
					$field->set('linked_field_id', $field_id);
					$field->commit();
				}
			}

			return true;
		}

	/*-------------------------------------------------------------------------
		Publish:
	-------------------------------------------------------------------------*/

		public function findEntries($entry_ids) {
			$section = self::cachedSection($this->get('linked_section_id'));
			$entries = self::$em->fetch(null, $this->get('linked_section_id'));
			$options = array();
			if(is_null($entry_ids)) $entry_ids = array();

			if ($this->get('required') != 'yes') $options[] = array(null, false, null);

			if (!is_object($section) or empty($entries)) return $options;

			foreach ($entries as $order => $entry) {
				if (!is_object($entry)) continue;

				$field = self::cachedSectionVisibleColumn($section->get('id'), $section);

				if (!is_object($field)) continue;

				$selected = in_array($entry->get('id'), $entry_ids);

				$value = $field->prepareTableValue(
					$entry->getData($field->get('id'))
				);

				$options[] = array(
					$entry->get('id'), $selected, $value
				);
			}

			return $options;
		}

		public function displayPublishPanel(&$wrapper, $data = null, $error = null, $prefix = null, $postfix = null) {
			$handle = $this->get('element_name'); $entry_ids = array();

			if (!is_array($data['linked_entry_id']) and !is_null($data['linked_entry_id'])) {
				$entry_ids = array($data['linked_entry_id']);

			} else {
				$entry_ids = $data['linked_entry_id'];
			}

			$options = $this->findEntries($entry_ids);

			$fieldname = "fields{$prefix}[{$handle}]{$postfix}";

			if ($this->get('allow_multiple') == 'yes') $fieldname .= '[]';

			$label = Widget::Label($this->get('label'));
			$select = Widget::Select($fieldname, $options);

			if ($this->get('allow_multiple') == 'yes') {
				$select->setAttribute('multiple', 'multiple');
			}

			$label->appendChild($select);

			if ($error != null) {
				$label = Widget::wrapFormElementWithError($label, $error);
			}

			$wrapper->appendChild($label);
		}

	/*-------------------------------------------------------------------------
		Input:
	-------------------------------------------------------------------------*/

		public function processRawFieldData($data, &$status, $simulate = false, $entry_id = null) {
			$field_id = $this->get('id');
			$status = self::__OK__;

			if (!is_array($data)) $data = array($data);

			if (empty($data)) return null;

			$result = array();

			foreach ($data as $a => $value) {
				$result['linked_entry_id'][] = $data[$a];
			}

			// Update linked field:
			$remove = self::$db->fetchCol('linked_entry_id',
				sprintf("
					SELECT
						f.linked_entry_id
					FROM
						`tbl_entries_data_{$field_id}` AS f
					WHERE
						f.entry_id = '{$entry_id}'
				")
			);

			$remove = array_diff($remove, $data);

			if (!$simulate) {

				// Remove old entries:
				foreach ($remove as $linked_entry_id) {
					if (is_null($linked_entry_id)) continue;

					$entry = self::cachedEntry($linked_entry_id, $this->get('linked_section_id'));

					if (!is_object($entry)) continue;

					$values = $entry->getData($this->get('linked_field_id'));

					if (array_key_exists('linked_entry_id', $values)) {
						$values = $values['linked_entry_id'];
					}

					if (is_null($values)) {
						$values = array();

					} else if (!is_array($values)) {
						$values = array($values);
					}

					$values = array_diff($values, array($entry_id));

					$entry->setData($this->get('linked_field_id'), array(
						'linked_entry_id'	=> $values
					));
					$entry->commit();
				}

				// Link new entries:
				foreach ($data as $linked_entry_id) {
					if (is_null($linked_entry_id)) continue;

					$entry = self::cachedEntry($linked_entry_id, $this->get('linked_section_id'));

					if (!is_object($entry)) continue;

					$values = $entry->getData($this->get('linked_field_id'));

					if (array_key_exists('linked_entry_id', $values)) {
						$values = $values['linked_entry_id'];
					}

					if (is_null($values)) {
						$values = array();

					} else if (!is_array($values)) {
						$values = array($values);
					}

					if (!in_array($entry_id, $values)) $values[] = $entry_id;

					$entry->setData($this->get('linked_field_id'), array(
						'linked_entry_id'	=> $values
					));
					$entry->commit();
				}
			}

			return $result;
		}

	/*-------------------------------------------------------------------------
		Output:
	-------------------------------------------------------------------------*/

		public function getParameterPoolValue($data) {
			if (!is_array($data['linked_entry_id'])) {
				$data['linked_entry_id'] = array($data['linked_entry_id']);
			}

			return implode(', ', $data['linked_entry_id']);
		}

		public function fetchIncludableElements() {
			return array(
				$this->get('element_name') . ': count',
				$this->get('element_name') . ': items',
				$this->get('element_name') . ': entries'
			);
		}

		public function prepareData($data) {
			if (!is_array($data['linked_entry_id'])) {
				$data['linked_entry_id'] = array($data['linked_entry_id']);
			}

			if (is_null($data['linked_entry_id'])) {
				$data['linked_entry_id'] = array();

			} else if (!is_array($data['linked_entry_id'])) {
				$data['linked_entry_id'] = array($data['linked_entry_id']);
			}

			return $data;
		}

		public function appendFormattedElement(&$wrapper, $data, $encode = false, $mode = null, $entry_id = null) {
			$linked_section_id = $this->get('linked_section_id');
			$section = self::cachedSection($linked_section_id);
			$data = $this->prepareData($data);

			$list = new XMLElement($this->get('element_name'));
			$list->setAttribute('mode', $mode);
			$list->setAttribute('entries', count($data['linked_entry_id']));

			// No section or relations:
			if (empty($section) or @empty($data['linked_entry_id'][0])) {
				$list->setAttribute('entries', 0);
				$wrapper->appendChild($list);

				return;
			}

			// List:
			if ($mode == null or $mode == 'items') {
				$entries = self::$em->fetch($data['linked_entry_id'], $linked_section_id);

				$list->appendChild(new XMLElement(
					'section', $section->get('name'),
					array(
						'id'		=> $section->get('id'),
						'handle'	=> $section->get('handle')
					)
				));

				$field = self::cachedSectionVisibleColumn($section->get('id'), $section);
				foreach ($entries as $count => $entry) {
					if (empty($entry)) continue;

					$value = $field->prepareTableValue(
						$entry->getData($field->get('id'))
					);
					$handle = Lang::createHandle($value);

					$item = new XMLElement('item', General::sanitize($value));
					$item->setAttribute('id', $entry->get('id'));
					$item->setAttribute('handle', $handle);

					$list->appendChild($item);
				}

			// Full:
			} else if ($mode == 'entries') {
				$entries = self::$em->fetch($data['linked_entry_id'], $linked_section_id);

				$list->appendChild(new XMLElement(
					'section', $section->get('name'),
					array(
						'id'		=> $section->get('id'),
						'handle'	=> $section->get('handle')
					)
				));

				foreach ($entries as $count => $entry) {
					$associated = self::cachedEntryCounts($entry->get('id'), $entry);

					$data = $entry->getData();

					$item = new XMLElement('entry');
					$item->setAttribute('id', $entry->get('id'));

					if (is_array($associated) and !empty($associated)) {
						foreach ($associated as $section => $count) {
							$handle = self::$db->fetchVar('handle', 0, "
								SELECT
									s.handle
								FROM
									`tbl_sections` AS s
								WHERE
									s.id = '{$section}'
								LIMIT 1
							");

							$item->setAttribute($handle, (string)$count);
						}
					}

					// Add fields:
					foreach ($data as $field_id => $values) {
						$field = self::cachedFields($field_id);

						if ($field->get('type') == $this->get('type')) continue;

						$field->appendFormattedElement($item, $values, false);
					}

					$list->appendChild($item);
				}
			}

			$wrapper->appendChild($list);
		}

		public function prepareTableValue($data, XMLElement $link = null, $entry_id = null) {
			$section = self::cachedSection($this->get('linked_section_id'));
			$linked = self::cachedFields($this->get('linked_field_id'));
			$custom_link = null; $more_link = null;

			if ($section instanceof Section) {
				$field = self::cachedSectionVisibleColumn($section->get('id'), $section);
				$data = $this->prepareData($data);

				if (!is_null($field)) {
					if ($this->get('column_mode') != 'count') {
						if ($this->get('column_mode') == 'last-item') {
							$order = 'ASC';
						}
						else {
							$order = 'DESC';
						}

						self::$em->setFetchSorting('date', $order);
						$entries = self::$em->fetch($data['linked_entry_id'], $this->get('linked_section_id'), 1);

						if (is_array($entries) and !empty($entries)) {
							$entry = current($entries);
							$custom_link = new XMLElement('a');
							$custom_link->setAttribute(
								'href', sprintf(
									'%s/symphony/publish/%s/edit/%s/',
									URL,
									$section->get('handle'),
									$entry->get('id')
								)
							);
							$custom_link->setValue(strip_tags(
								$field->prepareTableValue($entry->getData($field->get('id')))
							));

							$more_link = new XMLElement('a');
							$more_link->setValue(__('more →'));
							$more_link->setAttribute(
								'href', sprintf(
									'%s/symphony/publish/%s/?filter=%s:%s',
									URL,
									$section->get('handle'),
									$linked->get('element_name'),
									$entry_id
								)
							);
						}

					} else {
						$joins = null; $where = null;

						$linked->buildDSRetrivalSQL(array($entry_id), $joins, $where, false);

						$cacheName = $this->get('linked_section_id') . $where . $joins;
						if(!isset(self::$cacheEntries['count'][$cacheName])) {
							self::$cacheEntries['count'][$cacheName] = self::$em->fetchCount(
								$this->get('linked_section_id'), $where, $joins
							);
						}
						$count = self::$cacheEntries['count'][$cacheName];

						if ($count > 0) {
							$custom_link = new XMLElement('a');
							$custom_link->setValue($count . __(' →'));
							$custom_link->setAttribute(
								'href', sprintf(
									'%s/symphony/publish/%s/?filter=%s:%s', URL,
									$section->get('handle'),
									$linked->get('element_name'),
									$entry_id
								)
							);
						}
					}
				}
			}

			if (is_null($custom_link)) {
				$custom_link = new XMLElement('a');
				$custom_link->setValue(__('0 →'));
				$custom_link->setAttribute(
					'href', sprintf(
						'%s/symphony/publish/%s/?filter=%s:%s',
						URL,
						$section->get('handle'),
						$linked->get('element_name'),
						$entry_id
					)
				);

				if ($this->get('column_mode') != 'count') {
					$more_link = $custom_link;
					$more_link->setValue(__('more →'));

					$custom_link = new XMLElement('span');
					$custom_link->setAttribute('class', 'inactive');
					$custom_link->setValue(__('None'));
				}
			}

			if ($link) {
				$link->setValue($custom_link->getValue());

				return $link->generate();
			}

			if ($this->get('column_mode') != 'count') {
				$wrapper = new XMLElement('span');
				$wrapper->setValue(
					sprintf(
						'%s, %s',
						$custom_link->generate(),
						$more_link->generate()
					)
				);

				return $wrapper;
			}

			return $custom_link;
		}

	/*-------------------------------------------------------------------------
		Filtering:
	-------------------------------------------------------------------------*/

		public function displayDatasourceFilterPanel(&$wrapper, $data = null, $errors = null, $prefix = null, $postfix = null) {
			$field_id = $this->get('id');

			$wrapper->appendChild(new XMLElement(
				'h4', sprintf(
					'%s <i>%s</i>',
					$this->get('label'),
					$this->name()
				)
			));

			$prefix = ($prefix ? "[{$prefix}]" : '');
			$postfix = ($postfix ? "[{$postfix}]" : '');

			$label = Widget::Label('Value');
			$label->appendChild(Widget::Input(
				"fields[filter]{$prefix}[{$field_id}]{$postfix}",
				($data ? General::sanitize($data) : null)
			));
			$wrapper->appendChild($label);

			$help = new XMLElement('p');
			$help->setAttribute('class', 'help');
			$help->setValue(__('To do a negative filter, prefix the value with <code>not:</code>.'));

			$wrapper->appendChild($help);
		}

		public function buildDSRetrivalSQL($data, &$joins, &$where, $andOperation = false) {
			$field_id = $this->get('id');
			$method_not = false;

			// Find mode:
			if (preg_match('/^(not):/', $data[0], $match)) {
				$data[0] = trim(substr($data[0], strlen(next($match)) + 1));
				$name = 'method_' . current($match); $$name = true;
			}

			if ($andOperation) {
				$match = ($method_not ? '!=' : '=');

				foreach ($data as $value) {
					$this->_key++;
					$value = $this->cleanValue($value);
					$joins .= "
						LEFT JOIN
							`tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
							ON (e.id = t{$field_id}_{$this->_key}.entry_id)
					";
					$where .= "
						AND t{$field_id}_{$this->_key}.linked_entry_id {$match} '{$value}'
					";
				}

			} else {
				$match = ($method_not ? 'NOT IN' : 'IN');

				if (!is_array($data)) $data = array($data);

				foreach ($data as &$value) {
					$value = $this->cleanValue($value);
				}

				$this->_key++;
				$data = implode("', '", $data);
				$joins .= "
					LEFT JOIN
						`tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
						ON (e.id = t{$field_id}_{$this->_key}.entry_id)
				";
				$where .= "
					AND t{$field_id}_{$this->_key}.linked_entry_id {$match} ('{$data}')
				";
			}

			return true;
		}

	/*-------------------------------------------------------------------------
		Caching:
	-------------------------------------------------------------------------*/

		public function cachedSection($section_id, $key = "fetch") {
			if(!isset(self::$cacheSections[$key][$section_id])) {
				self::$cacheSections[$key][$section_id] = self::$sm->fetch($section_id);
			}
			return self::$cacheSections[$key][$section_id];
		}

		public function cachedSectionVisibleColumn($section_id, $section, $key = "columns") {
			if(!isset(self::$cacheSections[$key][$section_id])) {
				self::$cacheSections[$key][$section_id] = current($section->fetchVisibleColumns());
			}
			return self::$cacheSections[$key][$section_id];
		}

		public function cachedEntry($linked_entry_id, $linked_section_id, $key = "fetch") {
			$cacheName = $linked_entry_id . "-" . $linked_section_id;

			if(!isset(self::$cacheEntries[$key][$cacheName])) {
				self::$cacheEntries[$key][$cacheName] = current(
					self::$em->fetch($linked_entry_id, $linked_section_id)
				);
			}
			return self::$cacheEntries[$key][$cacheName];
		}

		public function cachedEntryCounts($entry_id, $entry, $key = "associated-counts") {
			if(!isset(self::$cacheEntries[$key][$entry_id])) {
				self::$cacheEntries[$key][$entry_id] = $entry->fetchAllAssociatedEntryCounts();
			}
			return self::$cacheEntries[$key][$entry_id];
		}

		public function cachedFields($field_id, $key = "fetch") {
			if(!isset(self::$cacheFields[$key][$field_id])) {
				self::$cacheFields[$key][$field_id] = self::$fm->fetch($field_id);
			}
			return self::$cacheFields[$key][$field_id];
		}
	}

?>
