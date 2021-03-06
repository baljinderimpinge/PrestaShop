<?php

/**
* Map Connec Invoice representation to/from Prestashop Invoice
*/

class InvoiceMapper extends BaseMapper {
	
	
	public function __construct() 
	{
		parent::__construct();

		$this->connec_entity_name = 'Invoice';
		$this->local_entity_name = 'Invoice';
		$this->connec_resource_name = 'invoices';
		$this->connec_resource_endpoint = 'invoices';
	}
	
	// Return the Product local id
	protected function getId($invoice) 
	{
		return $invoice['object']->id;
	}
	
	// Return a local Product by id
	protected function loadModelById($local_id) 
	{

	}
	
	// Map the Connec resource attributes onto the Prestashop Product
	protected function mapConnecResourceToModel($invoice_hash, $invoice) 
	{
		// Not saved locally, one way to connec!	

	}
	
	// Map the Prestashop Product to a Connec resource hash
	protected function mapModelToConnecResource($invoice) 
	{
		$invoice_hash = array();
		
		$invObj = $invoice['object'];
		$cart = $invoice['cart'];
		
        // Missing transaction lines are considered as deleted by Connec!
        $invoice_hash['opts'] = array('sparse' => false);
        
        // Get customer mno_id_map
        $customerMnoIdMap = MnoIdMap::findMnoIdMapByLocalIdAndEntityName($cart->id_customer, 'CUSTOMERS');
        $customerInfo = $this->loadCustomerByID($invoice['cart']->id_customer);
        
        $orderMnoIdMap = MnoIdMap::findMnoIdMapByLocalIdAndEntityName($invObj->id_order, 'SALESORDERS');
        
        
        $invoice_hash['title'] = 'Prestashop invoice #' . $invObj->id_order . " (".$customerInfo['firstname']." ".$customerInfo['lastname'].")";
		$invoice_hash['transaction_number'] = $invObj->id_order;
		$invoice_hash['transaction_date'] = $cart->date_add;
		$invoice_hash['due_date'] = $cart->date_add;
		
		// Order Status Default set "SUBMITTED"
		$invoice_hash['status'] = 'SUBMITTED';

        $invoice_hash['private_note'] = "Generated by Prestashop\n" . $invoice_hash['title'];
        $invoice_hash['person_id'] = $customerMnoIdMap['mno_entity_guid'];
		$invoice_hash['sales_order_id'] = $orderMnoIdMap['mno_entity_guid'];
		
		// Total Amount of cart
		$invoice_hash['amount'] = $invObj->total_paid_tax_incl;
		
		
		// Shipping and Billing Address
        $billingAddress = $this->getAddress($cart->id_address_invoice);
        $shippingAddress = $this->getAddress($cart->id_address_delivery);
        $billing = array(
          'attention_first_name' => $billingAddress['firstname'],
          'attention_last_name' => $billingAddress['lastname'],
          'line1' => $billingAddress['address1'],
          'line2' => $billingAddress['address2'],
          'city' => $billingAddress['city'],
          'postal_code' => $billingAddress['postcode'],
          'region' => $billingAddress['state_code'],
          'country' => $billingAddress['country_code']
        );
        
        $shipping = array(
          'attention_first_name' => $shippingAddress['firstname'],
          'attention_last_name' => $shippingAddress['lastname'],
          'line1' => $shippingAddress['address1'],
          'line2' => $shippingAddress['address2'],
          'city' => $shippingAddress['city'],
          'postal_code' => $shippingAddress['postcode'],
          'region' => $shippingAddress['state_code'],
          'country' => $shippingAddress['country_code']
        );
        $invoice_hash['billing_address'] = $billing;
        $invoice_hash['shipping_address'] = $shipping;      
        
        
        // Products In cart
		$items = $cart->getProducts();
		if (count($items) > 0) {
			$invoice_hash['lines'] = array();
			$line_number = 1;
			foreach($items as $item) {
				$line_hash = array();
				
				$line_hash['status'] = 'ACTIVE';
				$line_hash['line_number'] = $line_number;
		
				//get the Product MnoID Map
				$productMnoIdMap = MnoIdMap::findMnoIdMapByLocalIdAndEntityName($item['id_product'], 'Products');				 
				$line_hash['item_id'] = $productMnoIdMap['mno_entity_guid'];
						
				$line_hash['description'] = $item['description_short'];
				$line_hash['quantity'] = $item['cart_quantity'];
				$line_hash['unit_price'] = array();
				$line_hash['unit_price']['total_amount'] = $item['price_wt']; //Unit Price including Tax
				$line_hash['unit_price']['tax_rate'] = $item['rate'];
				
				$line_hash['total_price'] = array();
				$line_hash['total_price']['total_amount'] = $item['total_wt']; //Total Price including Tax
				$line_hash['total_price']['tax_rate'] = $item['rate'];
				$line_hash['total_price']['tax_amount'] = $item['rate'];
				
				$tax_code_id = InvoiceMapper::mapTaxToConnecResource($item['id_product']);
				if($tax_code_id != ""){
					$line_hash['tax_code_id'] = $tax_code_id;
				}
				
				$invoice_hash['lines'][] = $line_hash;  
				
				$line_number++;
			}	
		}
        
        
        
        // Add Shipping if applicable
		if ($invObj->total_shipping_tax_incl > 0){
			$line_hash = array();
			$line_hash['description'] = "Prestashop Shipping";
			$line_hash['is_shipping'] = true;
			$line_hash['quantity'] = 1;
			$line_hash['unit_price'] = array();
			$line_hash['unit_price']['total_amount'] = $invObj->total_shipping_tax_incl;			
			$line_hash['total_price']['total_amount'] = $invObj->total_shipping_tax_incl;			
			$invoice_hash['lines'][] = $line_hash;
		}
        
        return $invoice_hash;
	}
	
	
	// get the customer info from ID
	public function loadCustomerByID($customer_id)
	{
		$sql = "SELECT * FROM "._DB_PREFIX_."customer WHERE id_customer = '".pSQL($customer_id)."'";
		if ($row = Db::getInstance()->getRow($sql)){			
			return $row;
		}		
	}
	
	// get the billing and shipping address
	// get the Country and State code
	public function getAddress($id)
	{
		$sql = "SELECT a.*, c.iso_code as country_code, s.iso_code as state_code FROM "._DB_PREFIX_."address a 
					INNER JOIN "._DB_PREFIX_."country c ON a.id_country = c.id_country
					INNER JOIN "._DB_PREFIX_."state s ON a.id_state = s.id_state 
					WHERE a.id_address = '".pSQL($id)."'";
		if ($row = Db::getInstance()->getRow($sql)){
			return $row;
		}
	}
	
	// Add tax to product hash
	public static function mapTaxToConnecResource($product_id) 
	{ 
		$sql = "SELECT t.id_tax FROM "._DB_PREFIX_."product p INNER JOIN "._DB_PREFIX_."tax_rule t ON p.id_tax_rules_group = t.id_tax_rules_group WHERE p.id_product = '".pSQL($product_id)."'";		
		if ($row = Db::getInstance()->getRow($sql)){			
			if($row['id_tax'] > 0){
				$mno_id_map = MnoIdMap::findMnoIdMapByLocalIdAndEntityName($row['id_tax'], 'TAXRECORD');
				if($mno_id_map){ 					
					return $mno_id_map['mno_entity_guid']; 
				}
			}			
		}		
	}
  
}
