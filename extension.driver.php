<?php

	class Extension_cachelite extends Extension
	{
		protected $_cacheLite = null;
		protected $_lifetime = null;
		protected $_url = null;
		protected $_get = null;
		private $_sections = array();
		private $_entries = array();

		public function __construct()
		{
			require_once('lib/class.cachelite.php');
			$this->_lifetime = $this->getLifetime();
			$this->_cacheLite = new Cache_Lite(array(
				'cacheDir' => CACHE . '/',
				'lifeTime' => $this->_lifetime
			));
			$this->updateFromGetValues();
		}

		/*-------------------------------------------------------------------------
			Extension
		-------------------------------------------------------------------------*/

		public function uninstall()
		{
			// Remove preferences
			Symphony::Configuration()->remove('cachelite');
			Symphony::Configuration()->write();

			// Remove file
			if (@file_exists(MANIFEST . '/cachelite-excluded-pages')) {
				@unlink(MANIFEST . '/cachelite-excluded-pages');
			}

			// Remove extension table
			Symphony::Database()->query("DROP TABLE IF EXISTS `tbl_cachelite_references`");
		}

		public function install()
		{
			// Create extension table
			Symphony::Database()->query("
				CREATE TABLE `tbl_cachelite_references` (
				  `page` varchar(255) NOT NULL default '',
				  `sections` varchar(255) default NULL,
				  `entries` varchar(255) default NULL,
				  PRIMARY KEY  (`page`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
			");

			if (!@file_exists(MANIFEST . '/cachelite-excluded-pages')) {
				@touch(MANIFEST . '/cachelite-excluded-pages');
			}

			// Base configuration
			Symphony::Configuration()->set('lifetime', '86400', 'cachelite');
			Symphony::Configuration()->set('show-comments', 'no', 'cachelite');
			Symphony::Configuration()->set('backend-delegates', 'no', 'cachelite');

			return Symphony::Configuration()->write();
		}

		public function update($previousVersion = false)
		{
			return true;
		}

		public function getSubscribedDelegates()
		{
			return array(
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'FrontendPageResolved',
					'callback'	=> 'interceptPage'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'FrontendOutputPreGenerate',
					'callback'	=> 'parsePageData'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'FrontendOutputPostGenerate',
					'callback'	=> 'writePageCache'
				),
				array(
					'page' => '/system/preferences/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'appendPreferences'
				),
				array(
					'page' => '/system/preferences/success/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'appendPreferences'
				),
				array(
					'page' => '/system/preferences/',
					'delegate' => 'Save',
					'callback' => 'savePreferences'
				),
				array(
					'page'		=> '/publish/new/',
					'delegate'	=> 'EntryPostCreate',
					'callback'	=> 'entryCreate'
				),
				array(
					'page'		=> '/publish/edit/',
					'delegate'	=> 'EntryPreEdit',
					'callback'	=> 'entryEdit'
				),
				array(
					'page'		=> '/publish/',
					'delegate'	=> 'EntryPreDelete',
					'callback'	=> 'entryDelete'
				),
				array(
					'page' => '/blueprints/events/new/',
					'delegate' => 'AppendEventFilter',
					'callback' => 'addFilterToEventEditor'
				),
				array(
					'page' => '/blueprints/events/edit/',
					'delegate' => 'AppendEventFilter',
					'callback' => 'addFilterToEventEditor'
				),
				array(
					'page' => '/blueprints/events/new/',
					'delegate' => 'AppendEventFilterDocumentation',
					'callback' => 'addFilterDocumentationToEvent'
				),
				array(
					'page' => '/blueprints/events/edit/',
					'delegate' => 'AppendEventFilterDocumentation',
					'callback' => 'addFilterDocumentationToEvent'
				),
				array(
					'page' => '/frontend/',
					'delegate' => 'EventPreSaveFilter',
					'callback' => 'processEventData'
				),
				array(
					'page' => '/frontend/',
					'delegate' => 'EventPostSaveFilter',
					'callback' => 'processPostSaveData'
				),
			);
		}

		/*-------------------------------------------------------------------------
			Preferences
		-------------------------------------------------------------------------*/

		public function appendPreferences($context)
		{
			// Add new fieldset
			$group = new XMLElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild(new XMLElement('legend', 'CacheLite'));

			// Add Site Reference field
			$label = Widget::Label(__('Cache Period'));
			$label->appendChild(Widget::Input('settings[cachelite][lifetime]', General::Sanitize($this->getLifetime())));
			$group->appendChild($label);
			$group->appendChild(new XMLElement('p', __('Length of cache period in seconds.'), array('class' => 'help')));

			$label = Widget::Label(__('Excluded URLs'));
			$label->appendChild(Widget::Textarea('cachelite[excluded-pages]', 10, 50, $this->getExcludedPages()));
			$group->appendChild($label);
			$group->appendChild(new XMLElement('p', __('Add a line for each URL you want to be excluded from the cache. Add a <code>*</code> to the end of the URL for wildcard matches.'), array('class' => 'help')));

			$label = Widget::Label();
			$label->setAttribute('for', 'cachelite-show-comments');
			$hidden = Widget::Input('settings[cachelite][show-comments]', 'no', 'hidden');
			$input = Widget::Input('settings[cachelite][show-comments]', 'yes', 'checkbox');
			$input->setAttribute('id', 'cachelite-show-comments');
			if (Symphony::Configuration()->get('show-comments', 'cachelite') == 'yes') {
				$input->setAttribute('checked', 'checked');
			}
			$label->setValue(__('%s Show comments in page source?', array($hidden->generate() . $input->generate())));
			$group->appendChild($label);

			$label = Widget::Label();
			$label->setAttribute('for', 'cachelite-backend-delegates');
			$hidden = Widget::Input('settings[cachelite][backend-delegates]', 'no', 'hidden');
			$input = Widget::Input('settings[cachelite][backend-delegates]', 'yes', 'checkbox');
			$input->setAttribute('id', 'cachelite-backend-delegates');
			if (Symphony::Configuration()->get('backend-delegates', 'cachelite') == 'yes') {
				$input->setAttribute('checked', 'checked');
			}
			$label->setValue( __('%s Expire cache when entries are created/updated through the backend?', array($hidden->generate() . $input->generate())));
			$group->appendChild($label);
			$context['wrapper']->appendChild($group);
		}

		public function savePreferences($context)
		{
			$this->saveExcludedPages(stripslashes($_POST['cachelite']['excluded-pages']));
		}

		/*-------------------------------------------------------------------------
			Events
		-------------------------------------------------------------------------*/

		public function addFilterToEventEditor($context)
		{
			// adds filters to Filters select box on Event editor page
			$context['options'][] = array('cachelite-entry', @in_array('cachelite-entry', $context['selected']) , 'CacheLite: ' . __('Expire cache for pages showing this entry'));
			$context['options'][] = array('cachelite-section', @in_array('cachelite-section', $context['selected']) , 'CacheLite: ' . __('Expire cache for pages showing content from this section'));
			$context['options'][] = array('cachelite-url', @in_array('cachelite-url', $context['selected']) , 'CacheLite: ' . __('Expire cache for the passed URL'));
		}

		public function processEventData($context)
		{
			// flush the cache based on entry IDs
			if (in_array('cachelite-entry', $context['event']->eParamFILTERS) && isset($_POST['cachelite']['flush-entry'])) {
				if (is_array($_POST['id'])) {
					foreach($_POST['id'] as $id) {
						$this->clearPagesByReference($id, 'entry');
					}
				} elseif (isset($_POST['id'])) {
					$this->clearPagesByReference($_POST['id'], 'entry');
				}
			}

			// flush cache based on the Section ID of the section this Event accesses
			if (in_array('cachelite-section', $context['event']->eParamFILTERS) && isset($_POST['cachelite']['flush-section'])) {
				$this->clearPagesByReference($context['event']->getSource(), 'section');
			}
		}

		public function processPostSaveData($context)
		{
			// flush the cache based on explicit value
			if (in_array('cachelite-url', $context['event']->eParamFILTERS)) {
				$flush = (empty($_POST['cachelite']['flush-url']))
					? $this->_url
					: $this->computeHash(General::sanitize($_POST['cachelite']['flush-url']));
				$this->_cacheLite->remove($flush, 'default', true);
			}
		}

		public function addFilterDocumentationToEvent($context)
		{
			if (in_array('cachelite-entry', $context['selected']) || in_array('cachelite-section', $context['selected'])) $context['documentation'][] = new XMLElement('h3', __('CacheLite: Expiring the cache'));
			if (in_array('cachelite-entry', $context['selected']))
			{
				$context['documentation'][] = new XMLElement('h4', __('Expire cache for pages showing this entry'));
				$context['documentation'][] = new XMLElement('p', __('When editing existing entries (one or many, supports the <em>Allow Multiple</em> option) any pages showing this entry will be flushed. Add the following in your form to trigger this filter:'));
				$code = '<input type="hidden" name="cachelite[flush-entry]" value="yes"/>';
				$context['documentation'][] = contentBlueprintsEvents::processDocumentationCode($code);
			}
			if (in_array('cachelite-section', $context['selected']))
			{
				$context['documentation'][] = new XMLElement('h4', __('Expire cache for pages showing content from this section'));
				$context['documentation'][] = new XMLElement('p', __('This will flush the cache of pages using any entries from this event&#8217;s section. Since you may want to only run it when creating new entries, this will only run if you pass a specific field in your HTML:'));
				$code = '<input type="hidden" name="cachelite[flush-section]" value="yes"/>';
				$context['documentation'][] = contentBlueprintsEvents::processDocumentationCode($code);
			}
			if (in_array('cachelite-url', $context['selected']))
			{
				$context['documentation'][] = new XMLElement('h4', __('Expire cache for the passed URL'));
				$context['documentation'][] = new XMLElement('p', __('This will expire the cache of the URL at the value you pass it. For example'));
				$code = '<input type="hidden" name="cachelite[flush-url]" value="/article/123/"/>';
				$context['documentation'][] = contentBlueprintsEvents::processDocumentationCode($code);
				$context['documentation'][] = new XMLElement('p', __('Will flush the cache for <code>http://domain.tld/article/123/</code>. If no value is passed it will flush the cache of the current page (i.e., the value of <code>action=""</code> in you form):'));
				$code = '<input type="hidden" name="cachelite[flush-url]"/>';
				$context['documentation'][] = contentBlueprintsEvents::processDocumentationCode($code);
			}
			return;
		}

		/*-------------------------------------------------------------------------
			Caching
		-------------------------------------------------------------------------*/

		public function interceptPage($context)
		{
			if ($this->inExcludedPages() || !$this->isGetRequest()) {
				return;
			}

			$logged_in = Symphony::isLoggedIn();
			$this->updateFromGetValues();

			if ($logged_in && array_key_exists('flush', $this->_get) && $this->_get['flush'] == 'site')
			{
				$this->_cacheLite->clean();
			}
			else if ($logged_in && array_key_exists('flush', $this->_get))
			{
				unset($this->_get['flush']);
				$url = $this->computeHash($this->_get);
				$this->_cacheLite->remove($url, 'default', true);
			}
			else if (!$logged_in && $output = $this->_cacheLite->get($this->_url))
			{
				// Add comment
				if ($this->getCommentPref() == 'yes') {
					$output .= "<!-- Cache served: ". $this->_cacheLite->_fileName ." -->";
				}

				if (!isset($context['page_data']['type']) || !is_array($context['page_data']['type']) || empty($context['page_data']['type'])) {
					header('Content-Type: text/html; charset=utf-8');
				} else if (@in_array('XML', $context['page_data']['type']) || @in_array('xml', $context['page_data']['type'])) {
					header('Content-Type: text/xml; charset=utf-8');
				} else {
					foreach($context['page_data']['type'] as $type) {
						$content_type = Symphony::Configuration()->get(strtolower($type), 'content-type-mappings');

						if (!is_null($content_type)){
							header("Content-Type: $content_type;");
						}

						if ($type{0} == '.') {
							$FileName = $page_data['handle'];
  							header("Content-Disposition: attachment; filename={$FileName}{$type}");
						}
					}
				}

				if (@in_array('404', $context['page_data']['type'])) {
					header('HTTP/1.0 404 Not Found');
				} else if (@in_array('403', $context['page_data']['type'])) {
					header('HTTP/1.0 403 Forbidden');
				}

				// Add some cache specific headers
				$modified = $this->_cacheLite->lastModified();
				$modified_gmt = gmdate('r', $modified);

				$etag = md5($modified . $this->_url);
				header(sprintf('ETag: "%s"', $etag));

				// Set proper cache headers
				if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
					if ($_SERVER['HTTP_IF_MODIFIED_SINCE'] == $modified_gmt || str_replace('"', NULL, stripslashes($_SERVER['HTTP_IF_NONE_MATCH'])) == $etag){
						header('HTTP/1.1 304 Not Modified');
						exit();
					}
				}

				header('Last-Modified: ' . $modified_gmt);
				header('Cache-Control: public');
				header("Expires: " . gmdate("D, d M Y H:i:s", $modified + $this->_lifetime) . " GMT");
				header("X-Frame-Options: SAMEORIGIN");
				header("Access-Control-Allow-Origin: " . URL);
				header(sprintf('Content-Length: %d', strlen($output)));
				print $output;
				exit();
			}
		}

		public function writePageCache(&$output)
		{
			if ($this->inExcludedPages() || !$this->isGetRequest()) return;
			$logged_in = Symphony::isLoggedIn();

			if (!$logged_in) {
				$this->updateFromGetValues();

				$render = $output['output'];

				// rebuild entry/section reference list for this page
				$this->deletePageReferences($this->_url);
				$this->savePageReferences($this->_url, $this->_sections, $this->_entries);

				if (!$this->_cacheLite->get($this->_url)) {
					$this->_cacheLite->save($render);
				}

				// Add comment
				if ($this->getCommentPref() == 'yes') {
					$render .= "<!-- Cache generated: ". $this->_cacheLite->_fileName ." -->";
				}

				header("Expires: " . gmdate("D, d M Y H:i:s", $this->_lifetime) . " GMT");
				header("Cache-Control: max-age=" . $this->_lifetime . ", must-revalidate");
				header("Last-Modified: " . gmdate('D, d M Y H:i:s', time()) . ' GMT');
				header("X-Frame-Options: SAMEORIGIN");
				header("Access-Control-Allow-Origin: " . URL);
				header(sprintf('Content-Length: %d', strlen($render)));
				echo $render;
				exit();
			}
		}

		// Parse any Event or Section elements from the page XML
		public function parsePageData($context)
		{
			$xml = @DomDocument::loadXML($context['xml']->generate());
			if (!$xml) {
				return;
			}
			$xpath = new DOMXPath($xml);

			$sections_xpath = $xpath->query('//section[@id and @handle]');
			$sections = array();
			foreach($sections_xpath as $section) {
				$sections[] = $section->getAttribute('id');
			}

			$entries_xpath = $xpath->query('//entry[@id]');
			$entries = array();
			foreach($entries_xpath as $entry) {
				$entries[] = $entry->getAttribute('id');
			}

			$this->_sections = array_unique($sections);
			$this->_entries = array_unique($entries);
		}

		public function entryCreate($context)
		{
			if (Symphony::Configuration()->get('backend-delegates', 'cachelite') == 'no') return;
			// flush by Section ID
			if (isset($context['section'])) {
				$this->clearPagesByReference($context['section']->get('id'), 'section');
			}
		}

		public function entryEdit($context)
		{
			if (Symphony::Configuration()->get('backend-delegates', 'cachelite') == 'no') return;
			// flush by Entry ID
			if (isset($context['entry'])) {
				$this->clearPagesByReference($context['entry']->get('id'), 'entry');
			}
		}

		public function entryDelete($context)
		{
			if (Symphony::Configuration()->get('backend-delegates', 'cachelite') == 'no') return;
			// flush by Entry ID
			$this->clearPagesByReference($context['entry_id'], 'entry');
		}

		public function clearPagesByReference($id, $type)
		{
			// get a list of pages matching this entry/section ID
			$pages = $this->getPagesByContent($id, $type);
			// flush the cache for each
			foreach($pages as $page) {
				$url = $page['page'];
				$this->_cacheLite->remove($url, 'default', true);
				$this->deletePageReferences($url);
			}
		}

		/*-------------------------------------------------------------------------
			Helpers
		-------------------------------------------------------------------------*/

		private function getLifetime()
		{
			$default_lifetime = 86400;
			$val = Symphony::Configuration()->get('lifetime', 'cachelite');
			return (isset($val)) ? $val : $default_lifetime;
		}

		private function getCommentPref()
		{
			return Symphony::Configuration()->get('show-comments', 'cachelite');
		}

		private function getExcludedPages()
		{
			return @file_get_contents(MANIFEST . '/cachelite-excluded-pages');
		}

		private function saveExcludedPages($string)
		{
			return @file_put_contents(MANIFEST . '/cachelite-excluded-pages', $string);
		}

		private function inExcludedPages()
		{
			$segments = explode('/', $this->_get['symphony-page']);
			$domain = explode('/', DOMAIN);
			foreach($segments as $key => $segment) {
				if (in_array($segment, $domain) || empty($segment)) {
					unset($segments[$key]);
				}
			}
			$path = "/" . implode("/", $segments);

			$rules = file(MANIFEST . '/cachelite-excluded-pages', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$rules = array_filter(array_map('trim', $rules));
			if (count($rules) > 0) {
				foreach($rules as $r) {
					// Make sure we're matching `url/blah` not `/url/blah
					$r = "/" . trim($r, "/"); 
					//wildcard
					if ($r == '*') {
						return true;
					}
					// wildcard after
					else if (substr($r, -1) == '*' && strncasecmp($path, $r, strlen($r) - 2) == 0) {
						return true;
					}
					// wildcard before
					else if (substr($r, -1) == '*' && strpos($r, $path) !== false) {
						return true;
					}
					// wildcard before and after
					else if (substr($r, -1) == '*' && substr($r, 0) == '*' && strncasecmp($path, $r, strlen($r) - 2) == 0) {
						return true;
					}
					// perfect match
					else if (strcasecmp($r, $path) == 0) {
						return true;
					}
				}
			}
			return false;
		}


		/*-------------------------------------------------------------------------
			Database Helpers
		-------------------------------------------------------------------------*/

		private function getPagesByContent($id, $type)
		{
			return Symphony::Database()->fetch(
				sprintf(
					"SELECT page FROM tbl_cachelite_references WHERE %s LIKE '%%|%s|%%'",
					(($type=='entry') ? 'entries' : 'sections'),
					$id
				)
			);
		}

		private function deletePageReferences($url)
		{
			Symphony::Database()->query(
				sprintf(
					"DELETE FROM tbl_cachelite_references WHERE page='%s'",
					$url
				)
			);
		}

		protected function savePageReferences($url, $sections, $entries)
		{
			Symphony::Database()->query(
				sprintf(
					"INSERT INTO tbl_cachelite_references (page, sections, entries) VALUES ('%s','%s','%s')",
					$url,
					'|' . implode('|', $sections) . '|',
					'|' . implode('|', $entries) . '|'
				)
			);
		}

		/*-------------------------------------------------------------------------
			Utilities
		-------------------------------------------------------------------------*/

		private function computeHash($url)
		{
			return hash('sha512', (__SECURE__ ? 'https:' : '').serialize($url));
		}

		private function updateFromGetValues()
		{
			// Cache sorted $_GET;
			$this->_get = $_GET;
			ksort($this->_get);
			// hash it to make sure it wont overflow 255 chars
			$this->_url = $this->computeHash($this->_get);
		}

		private function isGetRequest()
		{
			return $_SERVER['REQUEST_METHOD'] == 'GET' || $_SERVER['REQUEST_METHOD'] == 'HEAD';
		}
	}