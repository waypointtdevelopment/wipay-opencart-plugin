<?php
/**
 * OpenCart 4 engine base class the extension's model and controllers extend.
 *
 * @license GPL-2.0-or-later
 */

namespace Opencart\System\Engine {
	class Model {
		public $config;
		public $db;
		public $language;
	}

	class Controller {
		public $config;
		public $db;
		public $language;
		public $load;
		public $session;
		public $url;
		public $response;
		public $request;
	}
}
