<?php
if (!defined('_PS_VERSION_')) {
	exit;
}

class BillysBilling extends Module {
    private $apiKey;
    private $shippingId;
    private $accountId;
    private $vatModelId;
    private $currency;
    private $client;

	public function __construct() {
	    $this->name = 'billysbilling';
	    $this->tab = 'billing_invoicing';
	    $this->version = 0.1;
	    $this->author = 'Billy\'s Billing';
	    $this->need_instance = 0;

	    parent::__construct();

	    $this->displayName = $this->l('Billy\'s Billing');
	    $this->description = $this->l('Automatically sends all incoming orders (including customer details and product details) as invoices to Billy\'s Billing.');
	}
	
	public function install() {
		if (parent::install() == false OR !$this->registerHook('postUpdateOrderStatus') OR !$this->registerHook('cart')) {
			return false;
		}
		return true;
	}
	
	public function uninstall() {
		if (!parent::uninstall()) {
			//Db::getInstance()->Execute('DELETE FROM `'._DB_PREFIX_.'mymodule`');
		}
		parent::uninstall();
	}
	
	public function hookPostUpdateOrderStatus($params) {
        $orderState = $params['newOrderStatus'];

        if ($orderState->template == "payment") {
            // Include Billy's PHP SDK
            require(dirname(__FILE__) . "/billysbilling-php/bootstrap.php");

            // Set variables
            $this->apiKey = "0rA6XS2blX4EEa8u20retof1OLdGaotQ";
            $this->shippingId = "190674-lTXK8T8qf2NzF";
            $this->accountId = "190614-4OR6MXKLjZ27A";
            $this->vatModelId = "190597-1B4Efvc9XXV3R";

            // Create new client with API key
            $this->client = new Billy_Client($this->apiKey);

            // Get order info
            $order = new Order($orderState->id);
            $currency = new Currency($order->id_currency);
            $this->currency = $currency->iso_code;

            // Get contact ID
            $contactId = $this->insertIgnore("contacts", $order);

            // Run through each order item
            foreach ($order->getProducts() as $product) {
                // Get product ID
                $productId = $this->insertIgnore("products", $product);

                // Add item to product array
                $products[] = array(
                    "productId" => $productId,
                    "quantity" => $product['product_quantity'],
                    "unitPrice" => $product['unit_price_tax_excl']
                );
            }
            // Add shipping costs to product array
            $products[] = array(
                "productId" => $this->shippingId,
                "quantity" => 1,
                "unitPrice" => $order->total_shipping_tax_excl
            );

            // Order date
            $date = date("Y-m-d", strtotime($order->invoice_date));

            // Format invoice details
            $invoice = array(
                "type" => "invoice",
                "contactId" => $contactId,
                "entryDate" => $date,
                "dueDate" => $date,
                "currencyId" => $this->currency,
                "state" => "approved",
                "lines" => $products
            );

            // Create new invoice
            $response = $this->client->post("invoices", $invoice);

            // Send debug to local script
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, "invoice_response=" . json_encode($response) . "&invoice_data=" . json_encode($invoice));
            curl_setopt($ch, CURLOPT_URL, 'http://posttest.dev/index.php?type=prestashop');
            curl_exec($ch);
            curl_close($ch);
        }
	}

    /**
     * Take a data object from Magento, use it to either search for existing entries in BB and return ID of that, or
     * insert a new entry in BB and return ID of that.
     *
     * @param $type string "contacts" or "products"
     * @param $data Address object or Product object
     *
     * @return int ID of inserted or found entry
     */
    private function insertIgnore($type, $data) {
        // Format data
        $data = $this->formatArray($type, $data);

        // Check for existing contact
        $response = $this->client->get($type . "?q=" . urlencode($data['name']));
        $responseArray = $response->$type;
        if (count($responseArray) > 0) {
            // If existing contact, then save ID
            $id = $responseArray[0]->id;
        } else {
            // Create new contact and contact person, then save ID
            $response = $this->client->post($type, $data);
            $id = $response->id;
        }
        return $id;
    }

    /**
     * Take a data object from Magento and convert it into something usable by BB API.
     *
     * @param $type string "contacts" or "products"
     * @param $data Address object or Product object
     * @return array of either contact or product
     */
    private function formatArray($type, $data) {
        if ($type == 'contacts') {
            $customer = new Customer($data->id_customer);
            $address = new Address($data->id_address_invoice);

            // Set name depending on company or not
            if ($address->company) {
                $name = $address->company;
            } else {
                $name = $address->firstname . ' ' . $address->lastname;
            }

            return array(
                'name' => $name,
                'street' => $address->address1,
                'zipcode' => $address->postcode,
                'city' => $address->city,
                'countryId' => str_replace('21', 'US', $address->id_country),
                'state' => $address->id_state,
                'phone' => $address->phone,
                'persons' => array(
                    array(
                        'name' => $address->firstname . ' ' . $address->lastname,
                        'email' => $customer->email,
                        'phone' => $address->phone
                    )
                )
            );
        } else if ($type == 'products') {
            return array(
                "name" => $data['product_name'],
                "accountId" => $this->accountId,
                "vatModelId" => $this->vatModelId,
                "productType" => "product",
                "productNo" => $data['product_reference'],
                "suppliersProductNo" => $data['product_supplier_reference'],
                "prices" => array(
                    array(
                        "currencyId" => $this->currency,
                        "unitPrice" => $data['unit_price_tax_excl']
                    )
                )
            );
        }
        return null;
    }
	
	public function hookCart($params) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_POSTFIELDS, "hook=cart&params=" . json_encode($params));
		curl_setopt($ch, CURLOPT_URL, 'http://posttest.dev/index.php?type=prestashop');
		curl_exec($ch);
		curl_close($ch);
	}
}