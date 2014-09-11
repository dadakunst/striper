<?php
if (!class_exists('Stripe')) {
    require_once("lib/stripe-php/lib/Stripe.php");
}
/*
 * Title   : Stripe Payment extension for WooCommerce
 * Author  : Sean Voss
 * Url     : http://seanvoss.com/woostriper
 * License : http://seanvoss.com/woostriper/legal
 */

class Striper extends WC_Payment_Gateway
{
	private $version = '0.30';
	private $path;
	private $url;
	
	
    protected $usesandboxapi              = true;
    protected $order                      = null;
    protected $transactionId              = null;
    protected $transactionErrorMessage    = null;
    protected $stripeTestApiKey           = '';
    protected $stripeLiveApiKey           = '';
    protected $publishable_key            = '';

    public function __construct()
    {
		$this->setup_paths_and_urls();
		
        $this->id = 'striper';
		$this->method_title = __('Striper', 'striper');
		$this->method_description = __('Process credit cards via Stripe', 'striper');
        $this->has_fields      = true;

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
		
		$alt_image = $this->get_option('alternate_imageurl');
        $this->icon = empty($alt_image) ? $this->url['assets'] . 'images/credits.png' : $alt_image;
        
		$this->usesandboxapi      = $this->get_option('sandbox') == 'yes';
		
        $this->testApiKey 		  = $this->get_option('test_api_key');
        $this->liveApiKey 		  = $this->get_option('live_api_key');
        $this->testPublishableKey = $this->get_option('test_publishable_key');
        $this->livePublishableKey = get_option('live_publishable_key');
		$this->publishable_key    = $this->usesandboxapi ? $this->testPublishableKey : $this->livePublishableKey;
        $this->secret_key         = $this->usesandboxapi ? $this->testApiKey : $this->liveApiKey;
        
		$this->useUniquePaymentProfile = $this->get_option('enable_unique_profile') == 'yes';
        $this->useInterval        = $this->get_option('enable_interval') == 'yes';
        $this->capture            = $this->get_option('capture') == 'yes';

        // tell WooCommerce to save options
        add_action('woocommerce_update_options_payment_gateways_' . $this->id , array($this, 'process_admin_options'));
        add_action('admin_notices', array($this, 'perform_ssl_check'));
        if($this->useInterval)
        {
            wp_enqueue_script('the_striper_js', plugins_url('/striper.js',__FILE__) );
        }
        wp_enqueue_script('the_stripe_js', 'https://js.stripe.com/v2/' );

    }

    public function perform_ssl_check()
    {
         if (!$this->usesandboxapi && get_option('woocommerce_force_ssl_checkout') == 'no' && $this->enabled == 'yes')
            echo 
				'<div class="error"><p>' . 
				sprintf(
					__('%s sandbox testing is disabled and can performe live transactions but the <a href="%s">force SSL option</a> is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.', 'striper'), 
					$this->method_title, 
					admin_url('admin.php?page=wc-settings&tab=checkout')
				) . 
				'</p></div>'
			;
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'striper'),
                'type'        => 'checkbox',
                'label'       => __('Enable Credit Card Payment', 'striper'),
                'default'     => 'yes'
            ),
			'title' => array(
                'title'       => __('Title', 'striper'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'striper'),
                'default'     => __('Credit Card Payment with Stripe', 'striper')
            ),
			'test_api_key' => array(
                'title'       => __('Stripe API Test Secret key', 'striper'),
                'type'        => 'text',
                'default'     => ''
            ),
            'test_publishable_key' => array(
                'title'       => __('Stripe API Test Publishable key', 'striper'),
                'type'        => 'text',
                'default'     => ''
            ),
            'live_api_key' => array(
                'title'       => __('Stripe API Live Secret key', 'striper'),
                'type'        => 'text',
                'default'     => ''
            ),
            'live_publishable_key' => array(
                'title'       => __('Stripe API Live Publishable key', 'striper'),
                'type'        => 'text',
                'default'     => ''
            ),
            'capture' => array(
                'title'       => __('Auth & Capture', 'striper'),
                'type'        => 'checkbox',
                'label'       => __('Enable Authorization & Capture', 'striper'),
                'default'     => 'no'
            ),
            'enable_interval' => array(
                'title'       => __('Enable Interval', 'striper'),
                'type'        => 'checkbox',
                'label'       => __('Use this only if nothing else is working', 'striper'),
                'default'     => 'no'
            ),
            'enable_unique_profile' => array(
                'title'       => __('Enable Payment Profile Creation', 'striper'),
                'type'        => 'checkbox',
                'label'       => __('Use this to always create a Payment Profile in Stripe (always creates new profile, regardless of logged in user), and associate the charge with the profile. This allows you more easily identify order, credit, or even make an additional charge (from Stripe admin) at a later date.', 'striper'),
                'default'     => 'no'
            ),
			'alternate_imageurl' => array(
                'title'       => __('Alternate Image to display on checkout', 'striper'),
                'type'        => 'text',
				'description' => __('Use fullly qualified url, served via https', 'striper'),
                'default'     => ''
            ),
			'sandbox' => array(
                'title'       => __('Testing', 'striper'),
                'type'        => 'checkbox',
                'label'       => __('Turn on testing with Stripe sandbox', 'striper'),
                'default'     => 'no'
            ),
       );
    }

    public function payment_fields()
    {
		$this->get_template('payment', array('publishable_key' => $this->publishable_key));
		
		wp_enqueue_script(
			'striper', 
			$this->url['assets'] . 'js/striper.js', 
			array('jquery'), 
			$this->version, 
			true
		);
    }

    protected function send_to_stripe()
    {
      global $woocommerce;

      // Set your secret key: remember to change this to your live secret key in production
      // See your keys here https://manage.stripe.com/account
      Stripe::setApiKey($this->secret_key);

      // Get the credit card details submitted by the form
      $data = $this->get_request_data();

      // Create the charge on Stripe's servers - this will charge the user's card
      try {

            if($this->useUniquePaymentProfile)
            {
              // Create the user as a customer on Stripe servers
              $customer = Stripe_Customer::create(array(
                "email" => $data['card']['billing_email'],
                "description" => $data['card']['name'],
                "card"  => $data['token']
              ));
              // Create the charge on Stripe's servers - this will charge the user's card

            $charge = Stripe_Charge::create(array(
              "amount"      => $data['amount'], // amount in cents, again
              "currency"    => $data['currency'],
              "card"        => $customer->default_card,
              "description" => $data['card']['name'],
              "customer"    => $customer->id,
              "capture"     => !$this->capture,
            ));
          } else {

            $charge = Stripe_Charge::create(array(
              "amount"      => $data['amount'], // amount in cents, again
              "currency"    => $data['currency'],
              "card"        => $data['token'],
              "description" => $data['card']['name'],
              "capture"     => !$this->capture,
            ));
        }
        $this->transactionId = $charge['id'];

        //Save data for the "Capture"
        update_post_meta( $this->order->id, 'transaction_id', $this->transactionId);
        update_post_meta( $this->order->id, 'key', $this->secret_key);
        update_post_meta( $this->order->id, 'auth_capture', $this->capture);
        return true;

      } catch(Stripe_Error $e) {
        // The card has been declined, or other error
        $body = $e->getJsonBody();
        $err  = $body['error'];
        error_log('Stripe Error:' . $err['message'] . "\n");
        wc_add_notice(__('Payment error:', 'striper') . $err['message'], 'error');
        return false;
      }
    }

    public function process_payment($order_id)
    {
        global $woocommerce;
        $this->order        = new WC_Order($order_id);
        if ($this->send_to_stripe())
        {
          $this->complete_order();

            $result = array(
                'result' => 'success',
                'redirect' => $this->get_return_url($this->order)
            );
          return $result;
        }
        else
        {
          $this->mark_as_failed_payment();
          wc_add_notice(__('Transaction Error: Could not complete your payment', 'striper'), 'error');
        }
    }

    protected function mark_as_failed_payment()
    {
        $this->order->add_order_note(
            sprintf(
                "%s Credit Card Payment Failed with message: '%s'",
                $this->method_title,
                $this->transactionErrorMessage
            )
        );
    }

    protected function complete_order()
    {
        global $woocommerce;

        if ($this->order->status == 'completed')
            return;

        $this->order->payment_complete();
        $woocommerce->cart->empty_cart();

        $this->order->add_order_note(
            sprintf(
                "%s payment completed with Transaction Id of '%s'",
                $this->method_title,
                $this->transactionId
            )
        );
    }


  protected function get_request_data()
  {
    if ($this->order AND $this->order != null)
    {
        return array(
            "amount"      => (float)$this->order->get_total() * 100,
            "currency"    => strtolower(get_woocommerce_currency()),
            "token"       => $_POST['stripeToken'],
            "description" => sprintf("Charge for %s", $this->order->billing_email),
            "card"        => array(
                "name"            => sprintf("%s %s", $this->order->billing_first_name, $this->order->billing_last_name),
                "address_line1"   => $this->order->billing_address_1,
                "address_line2"   => $this->order->billing_address_2,
                "address_zip"     => $this->order->billing_postcode,
                "address_state"   => $this->order->billing_state,
                "address_country" => $this->order->billing_country
            )
        );
    }
    return false;
  }
  
  private function setup_paths_and_urls() {
		$this->path['plugin_file'] = __FILE__;
		$this->path['plugin_dir'] = untrailingslashit(plugin_dir_path(__FILE__));
		//$this->path['includes'] = $this->path['plugin_dir'] . 'includes/';
		
		$this->url['plugin_dir'] = plugin_dir_url(__FILE__);
		$this->url['assets'] = $this->url['plugin_dir'] . 'assets/';
	}
	
	private function get_template($template_name, $args = array()) {
		wc_get_template(
			"$template_name.php",
			$args,
			'striper', 
			$this->path['plugin_dir'] . 'templates/'
		);
	}

}

//add_action('wp_ajax_capture_striper'     ,  'striper_order_status_completed');

function striper_order_status_completed($order_id = null)
{
  global $woocommerce;
  if (!$order_id)
      $order_id = $_POST['order_id'];

  $data = get_post_meta( $order_id );
  $total = $data['_order_total'][0] * 100;

  $params = array();
  if(isset($_POST['amount']) && $amount = $_POST['amount'])
  {
    $params['amount'] = round($amount);
  }

  $authcap = get_post_meta( $order_id, 'auth_capture', true);
  if($authcap)
  {
    Stripe::setApiKey(get_post_meta( $order_id, 'key', true));
    try
    {
      $tid = get_post_meta( $order_id, 'transaction_id', true);
      $ch = Stripe_Charge::retrieve($tid);
      if($total < $ch->amount)
      {
          $params['amount'] = $total;
      }
      $ch->capture($params);
    }
    catch(Stripe_Error $e)
    {
      // There was an error
      $body = $e->getJsonBody();
      $err  = $body['error'];
      error_log('Stripe Error:' . $err['message'] . "\n");
      wc_add_notice(__('Payment error:', 'striper') . $err['message'], 'error');
      return null;
    }
   return true;
  }
}

function striper_add_creditcard_gateway($methods)
{
    array_push($methods, 'Striper');
    return $methods;
}

add_filter('woocommerce_payment_gateways',                      'striper_add_creditcard_gateway');
add_action('woocommerce_order_status_processing_to_completed',  'striper_order_status_completed' );
