<?php
/*
	Plugin Name: Woocommerce Custom Profits
	Plugin URI: https://tecpro.ir
	Description: Add your custom order profit right after an order is recieved and calculate total shop sells and profits summary for any selected dates period in plugins admin area.
	Version: v1.0
	Author: Mojtaba Rezaeian
	Author URI: https://mrez.ir
	License:		GPL3
	License URI:	https://www.gnu.org/licenses/gpl-3.0.en.html
*/

defined('ABSPATH') or exit();
define('WCP_PLUGIN_DIR', __DIR__);
define('WCP_PLUGIN_URL', plugin_dir_url(__FILE__));


if (!defined('WP_DEBUG') OR !WP_DEBUG) {
	//error_reporting(E_ERROR);
	error_reporting(E_ALL & ~( E_NOTICE | E_USER_NOTICE | E_STRICT | E_DEPRECATED | E_USER_DEPRECATED | E_WARNING | E_CORE_WARNING | E_USER_WARNING | E_COMPILE_WARNING | E_PARSE ));
	ini_set('display_errors', 0);
	ini_set('display_startup_errors', 0);
} else {
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
}


// Links added to wordpress Plugins Install/Uninstall List
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wcp_add_action_links');
function wcp_add_action_links($links) {
	$mylinks = array(
		'<a href="' . admin_url('admin.php?page=wc-profits') . '">Settings</a>',
	);
	return array_merge($links, $mylinks);
}


add_action('admin_menu', 'wcp_admin_menu_button');
function wcp_admin_menu_button() {
	add_submenu_page('woocommerce', 'wc-profits', __('WC Profits','wc-profits'), 'manage_options', 'wc-profits', 'wcp_setting_page');
}


add_action( 'woocommerce_checkout_order_created', 'add_custom_field_on_placed_order' );
function add_custom_field_on_placed_order( $order ){
	update_post_meta($order->get_id(), 'Profit', 0);
}

add_action('admin_init', array('wcp', 'i'), 99, 0);
class wcp {

	private static $_inst;
	public static $options = null;

	function __construct() {
		load_plugin_textdomain( 'wc-profits', false, basename( dirname( __FILE__ ) ) . '/lang/' );
	}

	//var Singleton
	public static function i(): wcp {
		if (is_null(self::$_inst)) {
			self::$_inst = new self();
		}
		return self::$_inst;
	}

	public static function options(){
		if (self::$options == null or empty(self::$options)){
			self::$options = get_option('wcp_options', null);
			if (self::$options == null or empty(self::$options)){
				self::$options = array(

					'd1' => (new datetime('-1 months'))->format('Y-m-d'),
					'd2' => (new datetime('today'))->format('Y-m-d'),

				);
				self::save_options();
			}
		}
		return self::$options;
	}

	public static function save_options($new_options = null){
		if (!(is_null($new_options) or empty(self::$options) or array_diff(['d1','d2'],array_keys($new_options) )))
		{
			self::$options = $new_options;
		}
		if(self::$options['d1'] > self::$options['d2']){
			$tmp = self::$options['d1'];
			self::$options['d1'] = self::$options['d2'];
			self::$options['d2'] = $tmp;
		}
		update_option('wcp_options', self::$options);
	}

	public static function getdir(string $target){
		switch ($target) {
			case 'js':
			case 'img':
				$target = 'assets/' . $target;
			default:
				break;
		}
		return WCP_PLUGIN_DIR . '/' . $target;
	}

	public static function geturl(string $target){
		switch ($target) {
			case 'js':
			case 'img':
				$target = 'assets/' . $target;
			default:
				break;
		}
		return WCP_PLUGIN_URL . '/' . $target;
	}


	public static function orders_without_profit(){

		global $wpdb;

		$date_a = strtotime(self::options()['d1']);
		$date_b = strtotime(self::options()['d2']);

		$total_sell = $wpdb->get_results( "
			SELECT p.*, usr.meta_value as userID, prf.meta_value as Profit, useri.user_email as user_email
			FROM {$wpdb->prefix}posts as p
			LEFT JOIN {$wpdb->prefix}postmeta AS prf ON (p.ID = prf.post_id AND prf.meta_key = 'Profit')
			LEFT JOIN {$wpdb->prefix}postmeta AS usr ON (usr.post_id = p.ID AND usr.meta_key = '_customer_user')
			LEFT JOIN {$wpdb->prefix}users AS useri ON (usr.meta_value = useri.ID)
			WHERE p.post_type = 'shop_order'
			AND p.post_status IN ('wc-processing','wc-completed')
			AND UNIX_TIMESTAMP(p.post_date) >= $date_a
			AND UNIX_TIMESTAMP(p.post_date) < $date_b
			AND (prf.meta_value IS NULL OR prf.meta_value = 0)
			ORDER BY p.post_date_gmt DESC
		" );

		return $total_sell;
	}

	public static function html_none_profits(){
		$all = self::orders_without_profit();
		return self::html_get_each_none_profit($all);
	}

	public static function html_get_each_none_profit($all){

		$a = '<h3>' . __('There are ','wc-profits') . count($all) . __(' orders found in this range which you haven\'t set any profits to them','wc-profits') . ':</h3><ol dir="ltr">';
		for($i=0;$i<count($all);$i++){
			If ($i>100){
				$a .= '...<p>Too many none-profit orders are in this list!</br>Listing is skipped to keep optimized performance...</p>';
				break;
			}
			$a .= "<li style='display:block;'> Order:<a target='_blank' href='/wp-admin/post.php?post=" . $all[$i]->ID . "&action=edit'>" . $all[$i]->ID . "</a>	[" . $all[$i]->post_date . "] From: <a target='_blank' href='/wp-admin/edit.php?post_status=all&post_type=shop_order&_customer_user=" . $all[$i]->userID . "'>" . $all[$i]->user_email . "</a></li>";
		}
		$a .= '</ol>';

		return $a;
	}


	public static function get_daily_purchases_total(){

		global $wpdb;

		$date_a = strtotime(self::options()['d1']);
		$date_b = strtotime(self::options()['d2']);

		$total_sell = $wpdb->get_results( "
			SELECT SUM(pm1.meta_value) as total_sell_sum, SUM(prf.meta_value) as total_benefit_sum, COUNT(p.ID) as total_sell_count, COUNT(prf.meta_value) as total_benefit_count, (count(p.ID) - COUNT(prf.meta_value)) as without_profit_count
			FROM {$wpdb->prefix}posts as p
			LEFT JOIN {$wpdb->prefix}postmeta as pm1 ON (p.ID = pm1.post_id AND pm1.meta_key = '_order_total')
			LEFT JOIN {$wpdb->prefix}postmeta as prf ON (p.ID = prf.post_id AND prf.meta_key = 'Profit' AND prf.meta_value > 0)
			WHERE p.post_type = 'shop_order'
			AND p.post_status IN ('wc-processing','wc-completed')
			AND UNIX_TIMESTAMP(p.post_date) >= $date_a
			AND UNIX_TIMESTAMP(p.post_date) < $date_b
			AND pm1.meta_value IS NOT NULL
		" )[0];

		return $total_sell;
	}

	public static function html_totals(){
		$total = self::get_daily_purchases_total();
		return 
		'<div dir="ltr">' . self::options()['d1'] . ' <= sells < ' . self::options()['d2'] . '</div>' .
		'<b>' . __('Total sells between selected days','wc-profits') . ': </b> <h4 style="display:inline;">' . wc_price($total->total_sell_sum) . "</h4> " . __('sum of sales for','wc-profits') . " <b>$total->total_sell_count</b> " . __('completed orders','wc-profits') . "." .
		'</br><b>' . __('sells without profit','wc-profits') . ':</b> ' . $total->without_profit_count .
		'</br><b>' . __('Total Profit','wc-profits') . ':</b> <h2 style="display:inline-block;">' . $total->total_benefit_sum . "</h2> " . __('for','wc-profits') . " <b>$total->total_benefit_count</b> " . __('completed orders with custom profit','wc-profits') . '.';
	}

}

function wcp_setting_page() {

	// init options
	wcp::options();
	if (isset($_POST['d1']) and strtotime($_POST['d1']))
		wcp::$options['d1'] = $_POST['d1'];
	if (isset($_POST['d2']) and strtotime($_POST['d2']))
		wcp::$options['d2'] = $_POST['d2'];
	wcp::save_options(wcp::$options);
	?>

	<div id='wcp_admin' style='margin:10px;max-width:800px;' dir='<?php echo __('ltr','wc-profits') ?>'>


	<h1><?php echo __('Woocommerce Custom Profits','wc-profits');?></h1>
	<form action="" method="POST">
	<label for='d1'><?php echo __('From Date:','wc-profits');?></label><input id='d1' type="text" value="<?php echo wcp::$options['d1'] ?>" name='d1' dir='ltr'>
	<label for='d2'><?php echo __('Until (before this date)','wc-profits');?>: </label><input id='d2' type="text" value="<?php echo wcp::$options['d2'] ?>" name="d2" dir='ltr'></br></br>
	<input type="submit" value='<?php echo __('Calculate','wc-profits');?>' >
	</form></br> 

	<?php

	echo wcp::html_totals();
	echo wcp::html_none_profits();

	?>
</div><?php

}
