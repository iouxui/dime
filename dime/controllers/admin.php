<?php defined('IN_APP') or die('Get out of here');

/**
 *   Dime: Admin controller
 *
 *   The main admin interface controller, which should handle being able to
 *   edit, control, and modify everything possible about the Dime shop.
 */

class Admin_controller extends Controller {
	public $all = array('id', 'name', 'description', 'slug', 'price', 'image', 'total_stock', 'current_stock', 'discount', 'visible');
	
	public function __construct() {
		parent::__construct();
		
		//  Set the user session
		$this->session = Session::get(Config::get('session.user'), false);
		
		//  Bounce out if there's no session
		if($this->session === false and $this->url->segment(1) !== 'login') {
			Response::redirect('/admin/login');
		}
		
		//  Set the template path to the admin
		//  Since it's set as a static property and a constant,
		//  we have to do a bit of hacking. Ewww.
		$t = $this->template;
		$t::$path = APP_BASE . 'admin/template.html';
		$t::$base = APP_BASE . 'admin/';
		$t::$changed = true;
		
		$this->template->set(array(
			'partial_base' => APP_BASE . '/admin/partials/',
			'view_base' => APP_BASE . 'admin/',
			
			'theme_base' => APP_BASE . 'admin/',
			
			'loggedIn' => true,
			'url' => $this->url->segment(1)
		));
	}
	
	//  Decide what function to call
	//  This will be overriden by the routes
	public function delegate() {
		$url = str_replace('_', '', $this->url->segment(1));
		
		if(method_exists($this, $url) and $url !== __FUNCTION__) {
			Plugin::receive('admin_delegated', $url);
			return $this->{$url}();
		}
		
		echo $this->template->render('404');
	}
	
	//  Load the main index page
	//  Possibly redirect to adding a new product or something
	public function index() {
		echo $this->template->render('index');
	}
	
	public function products() {
		$products = $this->model->allProducts();
		if(count($products) < 1) {
			return Response::redirect('/admin/products/add');
		}
		
		Plugin::receive('admin_products', $products);
		
		echo $this->template->set('products', $products)->render('product/index');
	}
	
	public function product() {
		$id = $this->url->segment(2);
		$msg = false;
		
		if($this->input->posted()) {
			$data = array();
			foreach($this->all as $post) {
				$data[$post] = Input::post($post);
			}
			
			$msg = $this->model->update($id, $data);
			
			if($msg === false) {
				Response::redirect('/admin/products');
			}
		}
		
		echo $this->template->set(array(
			'product' => $this->model->findProduct($id),
			'msg' => $msg
		))->render('product/edit');
	}
	
	public function addProduct() {
		$required = array('name', 'price', 'slug', 'description');
		$errors = array();
		
		$stock = $this->_getStock();
		
		$handlers = array(
			'price' => function($str) {
				return preg_replace('/[^0-9]+/', '', $str);
			},
			'visible' => function($str) {
				return $str === 'yes';
			},
			'total_stock' => function() use($stock) {
				return $stock;
			},
			'current_stock' => function() use($stock) {
				return $stock;
			},
			'discount' => function($str) {
				return preg_replace('/[^0-9]+/', '', $str);
			}
		);

		if($this->input->posted()) {
			$product = array('');
			
			//  Check required fields
			foreach($required as $field) {
				if($this->input->post($field) === '') {
					$errors[] = 'Please fill out the ' . $field . '!';
				}
			}
			
			foreach($this->all as $post) {
				$data = $this->input->post($post);
				if(isset($handlers[$post])) {
					$data = $handlers[$post]($data);
				}
				
				$product[] = $data;
			}
			
			//  Display errors
			if(count($errors) > 0) {
				$this->template->set('msg', join($errors, '<br>'));
			} else {
				$product = $this->model->insertProduct($product);
				
				if($product === false) {
					$this->template->set('msg', 'Unexpected error. Try again in a minute.');
				} else {
					return Response::redirect('/admin/products/' . $product->id);
				}
			}
		}
		
		echo $this->template->render('product/add');
	}
	
	public function themes() {
		//$themes = glob();
		$themes = array();
		
		echo $this->template->set('themes', $themes)->render('theme/index');
	}
	
	public function login() {
		if($this->session !== false) {
			return Response::redirect('/admin');
		}
		
		//  We're not logged in, make sure you know that.
		$this->template->remove('loggedIn');
			
		//  Check if the username field is set
		//  If it is, we can assume they're trying to log in
		if($this->input->posted('username')) {
			$status = $this->model->findUser($this->input->post('username'), $this->input->post('password'));

			//  Log the user in
			if(is_object($status)) {
				unset($status->password);
				return Session::set(Config::get('session.user'), $status) and Response::redirect('/admin');
			}
						
			//  Slow down the response. You've been naughty.
			sleep(1);
			
			//  And show a message.
			$this->template->set('msg', 'Invalid username or password.');
		}
		
		echo $this->template->set('class', 'login')
				  ->render('login');
	}
	
	public function plugins() {
		//  Handle enabling/disabling plugins
		if(isset($_GET['enable']) or isset($_GET['disable'])) {
			$this->_modifyPlugin();
		}
		
		$active = explode(',', Config::get('plugins'));
		$plugins = array();
		
		foreach(glob(APP_BASE . 'plugins/*/about.json') as $plugin) {
			$data = json_decode(file_get_contents($plugin));
			$slug = basename(dirname($plugin));
			
			$data->active = false;
			if(in_array($slug, $active) !== false) {
				$data->active = true;
			}
			
			$data->slug = $slug;
			$data->page = Plugin::pages($slug);
			
			//  Bind the list of plugins
			$data = Plugin::receive('admin_plugin_list', $data);
			
			$plugins[] = $data;
		}
				
		echo $this->template->set('plugins', $plugins)->render('plugin/index');
	}
	
	private function _modifyPlugin() {
		foreach(array('en', 'dis') as $mode) {
			$data = $this->input->get($mode . 'able');
			
			if($data) {
				Plugin::receive('plugin_' . $mode . 'abled');
				$this->model->{$mode . 'ablePlugin'}($data);
				
				return Response::redirect('/admin/plugins');
			}
		}
	}
	
	public function plugin() {
		$slug = $this->url->segment(2);
		$plugin = Plugin::pages($slug);
		
		if($plugin === false) {
			Response::redirect('/admin/plugins');
		}
		
		echo $this->template->set(array('slug' => $slug, 'content' => $plugin))->render('plugin/single');
	}
	
	public function logout() {
		return Session::destroy(Config::get('session.user')) and Response::redirect('/admin/login');
	}
	
	private function _getStock() {
		$stock = $this->input->post('total_stock', 'unlimited');
		
		//  Just in case people take the quotes literally
		$stock = str_replace('"', '', $stock);
		
		if($stock === 'unlimited' or $stock > 2147483647) {
			$stock = 2147483647;
		}
		
		return $stock;
	}
}