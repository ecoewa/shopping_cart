<?php

/**
 * Cart Session Component
 * Part of the ShoppingCart plugin
 *
 * @author Dean Sofer (ProLoser)
 * @author Jesse Adams (techno-geek)
 * @author Rick Guyer (ricog)
 * 
 * @version	0.2
 * @package	CakePHP Shopping Cart Plugin Suite
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 */
 
 class CartSessionComponent extends Object {

	var $components = array('Session');
	/**
	 * Used to determine wether or not the table should be used
	 *
	 * @var boolean
	 * @access public
	 */
	var $useTable;
	/**
	 * Default table field for product names
	 *
	 * @var string
	 * @access public
	 */
	var $nameField = 'name';
	/**
	 * Default table field for product descriptions
	 *
	 * @var string
	 * @access public
	 */
	var $descField = 'description';
	/**
	 * Default table field for product prices
	 *
	 * @var string
	 * @access public
	 */
	var $priceField = 'price';
	/**
	 * Default table field for product taxable boolean. Set to false 
	 * to make all products taxable or if there is no taxable field
	 *
	 * @var string
	 * @access public
	 */
	var $taxableField = 'taxable';

	/**
	 * The shipping rate to be applied during calcTotal()
	 *
	 * @var decimal
	 * @access public
	 */
	var $shipRate = 0;
	/**
	 * Specifies wether the shipping rate is a flat rate (true) or 
	 * percentage (false)
	 *
	 * @var boolean
	 * @access public
	 */
	var $shipFlat = true;
	
	var $userId = null;
	var $persistentCart = false;
	
    /**
	 * Initializes the component, gets a reference to the controller 
	 * and stores configuration options.
	 *
	 * @param object $controller A reference to the controller
	 * @param array $options Passed component configuration settings
	 * @return void
	 * @access public
	 */
	function initialize(&$controller, $options) {
        // saving the controller reference for later use
        $this->controller =& $controller;
		
		if (isset($options['useTable'])) {
			$this->useTable = $options['useTable'];
		} else {
			$this->useTable = $this->controller->{$this->controller->modelClass}->useTable;
		}
		
		if (isset($options['nameField'])) {
			$this->nameField = $options['nameField'];
		} else {
			$this->nameField = $this->controller->{$this->controller->modelClass}->displayField;
		}
		if (isset($options['priceField'])) {
			$this->priceField = $options['priceField'];
		}
		if (isset($options['taxableField'])) {
			$this->taxableField = $options['taxableField'];
		}
		
		if (Configure::read('ShoppingCart.persistent') === true) {
			$this->persistentCart = true;
			
			if ($this->Session->check('Auth')) {
				$this->userId = $this->Session->read('Auth.User.id');
			}
		}
    }
    //called after Controller::beforeFilter()
    function startup(&$controller) {
    }
    //called after Controller::beforeRender()
    function beforeRender(&$controller) {
    }
    //called after Controller::render()
    function shutdown(&$controller) {
    }
    //called before Controller::redirect()
    function beforeRedirect(&$controller, $url, $status=null, $exit=true) {
    }
	
	/**
	 * Creates a fresh new cart with empty information
	 *
	 * @param boolean $full Specifies wether or not to retain the user 
	 * Billing and Shipping info
	 * @return <success> boolean Session->write() success
	 * @access public
	 */
	function resetCart($full = true) {
		$data = null;
		$returnValue = null;
		if ($data = $this->Session->read('Order') && !$full) {
			unset($data['LineItem'], $data['Billing'], $data['Shipping'], $data['Total']);
			$returnValue = $this->Session->write('Order', $data);
		} else {
			$returnValue = $this->Session->delete('Order');
		}
		
		if ($this->userId && $this->persistentCart) {
			$this->deleteDatabaseRecord();
		}		
		
		return $returnValue;
	}
	
	function resetShipping() {
		if($this->Session->check('Order')) {
			$this->Session->write('Order.Totals.shipping', 0);
			$this->Session->del('Order.Shipping');
			
			$this->Session->write('Order.Totals.tax', 0);
			$this->Session->del('Order.TaxRate');
		}
	}
	
	function applyCoupon($code) {
		$this->controller->loadModel('Coupon');
		$coupon = $this->controller->Coupon->find('first', array(
			'conditions' => array(
				'Coupon.code' => $code,
			),	
		));
		
		$coupons = array();
		if ($this->Session->check('Order.Coupon')) {
			$coupons = $this->Session->read('Order.Coupon');
		}		
		
		$alreadyExists = false;
		foreach ($coupons as $existingCoupon) {
			if ($existingCoupon['Coupon']['id'] == $coupon['Coupon']['id']) {
				$alreadyExists = true;
			}
		}
		
		$result = false;
		if (!$alreadyExists) {
			$coupons[] = $coupon;
			$this->Session->write('Order.Coupon', $coupons);
			
			$this->calcTotal();
			
			if ($this->userId && $this->persistentCart) {
				$this->updateDatabaseRecord();
			}		
			
			$result = true;
		}
		
		return $result;
	}
	
	/**
	 * Add's an item to the cart, requires a Product.id number or data
	 *
	 * @param int/array $product The product ID from the table, or a custom 
	 * array that has the ID field if useTable = true
	 * @return <success> boolean
	 * @access public
	 */
	function addItem($data, $quantity = 1, $attribs = array(), $version = null, $shipping = null) {
		$this->log('[ShoppingCart] Adding Item: ' . $data . ' ' . $quantity . ' ' . serialize($attribs), LOG_DEBUG);
		
		// Handles the 3 possible ways to pass a product: 
		//		$product = 3, Merges database data with custom data
		//		$product['Model']['id'] = 3, Merges database data with custom data
		//		$product['id'] = 3,  Custom products only, for use without a table
		$lineItem = array();
		if ($this->useTable) {
			if (is_array($data)) {
				$product = $this->controller->{$this->controller->modelClass}->find('first', array('recursive' => -1, 'conditions'=>array('id'=>$data['id'])));
				if (!$product) {
					return false;
				}
				$lineItem['Product'] = array_merge($data, $product[$this->controller->modelClass]);
			} else {
				if ($this->Session->check('Order.LineItem.' . $data)) {
						$lineItem = $this->Session->read('Order.LineItem.' . $data);
				} else {
					if (empty($this->controller->Product)) {
						$this->controller->loadModel('Product');
					}
					$product = $this->controller->Product->find('first', array('recursive' => -1, 'conditions'=>array('id'=>$data)));
					if (!$product) {
						return false;
					}				
					$lineItem['Product'] = $product['Product'];
				}
			}
		} else {
			$lineItem['Product'] = $product = $data;
		}
		
		if ($version) {
			$lineItem['Product']['version'] = $version;
		}
		
		if ($shipping) {
			$lineItem['Product']['shipping'] = $shipping;
		}
		
		// Get the list of line items in the cart
		$lineItems = array();
		if ($this->Session->check('Order.LineItem')) {
			$lineItems = $this->Session->read('Order.LineItem');
		}
		
		$this->log('LineItems Before: ' . serialize($lineItems), LOG_DEBUG);
		
		// Check to see if the item is already in the cart
		if(!empty($lineItems[$lineItem['Product']['id']])) {	
			// The item already exists, just edit it
			$match = null;
			foreach($lineItems[$lineItem['Product']['id']]['Selection'] as $count => $selection) {
				if ($selection['attributes'] == $attribs) {
					$match = $count;
				}
			}

			if ($match !== null) {
				$quantity = $lineItems[$lineItem['Product']['id']]['Selection'][$match]['quantity'] += $quantity;
				$returnValue = $this->updateItem($lineItem['Product']['id'], $match, $quantity);
			} else {
				$returnValue = $this->_addItemNew($lineItem, $attribs, $quantity, $lineItems);
			}
		} else {
			$returnValue = $this->_addItemNew($lineItem, $attribs, $quantity, $lineItems);
		}
		
		// Reset shipping data
		$this->resetShipping();
		
		if ($this->userId && $this->persistentCart) {
			$this->updateDatabaseRecord();
		}
		
		return $returnValue;
	}
	
	/**
	 * Private method to avoid code duplication. Used in $this->addItem()
	 *
	 * @param $lineItem
	 * @param $attribs
	 * @param $quantity
	 * @param $lineItems
	 * @return <success> boolean
	 * @access protected
	 */	
	function _addItemNew($lineItem, $attribs = array(), $quantity, $lineItems) {
		$this->log('[ShoppingCart] Item was new', LOG_DEBUG);
		
		// Apply LineItem specific attributes
		$selection = array();
		$selection['name'] = $lineItem['Product'][$this->nameField];
		if(isset($lineItem['Product']['recurring_trial_price'])) {
			$selection['price'] = $lineItem['Product']['recurring_trial_price'];
		} else {
			$selection['price'] = $lineItem['Product'][$this->priceField];	
		}
		$selection['description'] = ($this->descField) ? $lineItem['Product'][$this->descField] : null;
		$selection['taxable'] = ($this->taxableField) ? $lineItem['Product'][$this->taxableField] : true;
		$selection['quantity'] = $quantity;	
		$selection['subtotal'] = $quantity * $selection['price'];	
		$selection['attributes'] = $attribs;

		// Add the item to the main list
		$lineItem['Selection'][] = $selection;
		$lineItems[$lineItem['Product']['id']] = $lineItem;
			
		$returnValue = $this->Session->write("Order.LineItem", $lineItems);
		$this->calcTotal();
		
		return $returnValue;
	}
	
	/**
	 * Updates a specific cart item. If quantity is passed at 0 or less, item is deleted.
	 * If $product information is passed, the Product array is updated (does not change lineItem price)
	 * TODO: I am unsure if I should have multiple actions perform at once (product info + quantity + price).
	 *
	 * @param int $id LineItem id number
	 * @param int $quantity
	 * @param array $product LineItem Product array
	 * @return <success> boolean
	 * @access public
	 */
	function updateItem($id, $selectionCount, $quantity = false, $product = null) {
		$returnValue = null;
		
		// @TODO Handle the $product parameter
/*		if (!empty($product)) {
			$returnValue = $this->Session->write("Order.LineItem.$id.Product", $product);
		}*/
		
		if ($quantity <= 0) {
			$returnValue = $this->removeItem($id, $selectionCount);
		} else if ($quantity) {
			$selection = $this->Session->read("Order.LineItem.$id.Selection");
			
			$selection[$selectionCount]['subtotal'] = $quantity * $selection[$selectionCount]['price']; 
			$selection[$selectionCount]['quantity'] = $quantity;
			
			$returnValue = $this->Session->write("Order.LineItem.$id.Selection", $selection);
			$this->calcTotal();
			
			// Reset shipping data
			$this->resetShipping();	
		}	
		
		// Clear Coupons
		$this->Session->del('Order.Coupon');
		
		if ($this->userId && $this->persistentCart) {
			$this->updateDatabaseRecord();
		}		
		
		return $returnValue;
	}
	
	/**
	 * Removes a LineItem from the shopping cart
	 *
	 * @param int $id LineItem id number
	 * @return <success> boolean
	 * @access public
	 */
	// TODO Updated removeItem to work with selections
	function removeItem($id, $selection) {
		$returnValue = $this->Session->delete("Order.LineItem.$id.Selection.$selection");
		
		$selectionArray = $this->Session->read("Order.LineItem.$id.Selection");

		if (empty($selectionArray)) {
			$this->Session->delete("Order.LineItem.$id");
		}
		
		$lineItems = $this->Session->read("Order.LineItem");

		if (empty($lineItems)) {
			$this->resetCart();
		} else {	
			$this->calcTotal();
		}
		
		// Reset shipping data
		$this->resetShipping();		
		
		return $returnValue;
	}
	
	/**
	 * Returns the Order information for checkout
	 *
	 * @param 
	 * @return array()	Returns the currently stored shopping cart session with calculations
	 * @access public
	 */
	function getOrderDetails() {
		if ($this->Session->check('Order')) {
			return $this->Session->read('Order');
		} else {
			return false;
		}
	}

	/**
	 * Returns the total weight of the order
	 *
	 * @param 
	 * @return array()	Returns the currently stored shopping cart session with calculations
	 * @access public
	 */
	function getOrderMeasurements() {
		if ($this->Session->check('Order')) {
			$order = $this->Session->read('Order');
			$weight = 0;
			if (count($order['LineItem'])) {
				foreach ($order['LineItem'] as $item) {
					foreach ($item['Selection'] as $selection) {
						$weight = $weight + ($selection['quantity'] * $item['Product']['weight']);
					}
				}
			}
			
			return array('totals' => array('weight' => $weight));
		} else {
			return array();
		}
	}
	
	/*
	 * The setPaymentInfo function is a wrapper function to simply fill in both
	 * shipping and billing information at once. If only $billingData is passed
	 * it is copied over to shipping.
	 *
	 * @param array $billingData
	 * @param array $shippingData
	 * @return void
	 * @access public
	 */
	function setPaymentInfo($billingData, $shippingData = null) {
		$this->setBillInfo($billingData);
		
		if ($shippingData) {
			$this->setShipInfo($shippingData);
		} else {
			$this->setShipInfo($billingData);
		}
	}
	
	/**
	 * Stores the customer billing address info
	 *
	 * @param array $data
	 * @return <success> boolean
	 * @access public
	 */
	function setBillInfo($data) {
		return $this->Session->write('Order.Billing', $data);
	}
	
	/**
	 * Stores the customer shipping address info
	 *
	 * @param array $data
	 * @return <success> boolean
	 * @access public
	 */
	function setShipInfo($data) {
		return $this->Session->write('Order.Shipping', $data);
	}
	
	/**
	 * Checks to make sure tax rate is a decimal and stores for use in calcTotal()
	 *
	 * @param decimal $taxRate
	 * @return void
	 * @access public
	 */
	function setTaxRates($rates) {
		if(!$this->Session->check('Order.TaxRate')) {
			$this->Session->write('Order.TaxRate', $rates);
		}
		
		$this->calcTotal();
	}
	
	/**
	 * Checks to make sure shipping rate is a decimal if it's not a flat fee and 
	 * stores for use in calcTotal()
	 *
	 * @param decimal $shipRate
	 * @param boolean $flat Wether or not the shipping rate is a flat fee
	 * @return void
	 * @access public
	 */
	function setShipRate($shipRate, $flat = true) {
		// Ensures that percent values are changed to decimal
		if (!$flat && $shipRate > 1)
			$shipRate = $shipRate / 100;
	
		$this->shipRate = $shipRate;
		$this->calcTotal();
	}
	
	/**
	 * Conveniance method for handling all shipping in one call
	 *
	 * @param decimal $price
	 * @param boolean $info
	 * @return void
	 * @access public
	 */	
	function setShipping($price, $info) {
		$this->setShipRate($price);
		$this->setShipInfo($info);
	}
	
	/**
	 * This function takes the order out of the session, goes row by row totalling up
	 * the prices into $subtotal, applying tax to individual products that are taxable = true,
	 * and then adds it all together, including shipping, which it stores back into the
	 * Order session under Order.Totals
	 *
	 * @return <success> boolean
	 * @access public
	 */
	function calcTotal() {
		$order = $this->Session->read('Order');
		$subtotal = 0;	
		
		foreach ($order['LineItem'] as $id => $lineItem) {
			
			$lineQuantity = 0;
			$lineTotal = 0;
			foreach ($lineItem['Selection'] as $count => $selection) {
				$selectionTotal = $selection['price'] * $selection['quantity'];
/*				if ($selection['taxable'])
					$selectionTotal += $selectionTotal * $this->taxRate;*/
				$subtotal += $selectionTotal;
				
				$lineQuantity += $selection['quantity'];
				$lineTotal += $selectionTotal;
			}
			
			$totals = array(
				'quantity' => $lineQuantity,
				'subtotal' => $lineTotal,
				'numAttribs' => count($selection['attributes']),
			);
			
			$this->Session->write('Order.LineItem.' . $id . '.Totals', $totals);
		}
		
		if (!empty($order['Coupon'])) {
			foreach ($order['Coupon'] as $count => $coupon) {
				$discount = 0;
				if ($coupon['Coupon']['deduction_type'] == 'amount') {
					$discount = $coupon['Coupon']['discount'];
				} else {
					if (count($coupon['Restriction']) > 0) {
						$restrictions = array();
						foreach ($coupon['Restriction'] as $restriction) {
							$restrictions[] = $restriction['id'];
						}
						
						foreach ($order['LineItem'] as $id => $lineItem) {
							if (in_array($lineItem['Product']['id'], $restrictions)) {
								$discount += $this->Session->read('Order.LineItem.' . $id . '.Totals.subtotal') * ($coupon['Coupon']['discount'] / 100);
							}	
						}					
					} else {
						$discount = $subtotal * ($coupon['Coupon']['discount'] / 100);
					}
				}
				
				$totals = array(
					'discount' => $discount,
				);
				
				$this->Session->write('Order.Coupon.' . $count . '.Totals', $totals);
				$subtotal -= $discount;
			}
		}
		
		$tax = 0;
		if ($this->Session->check('Order.TaxRate')) {
			// Determine taxable state
			$taxableState = null;
			if ($this->Session->check('Order.Shipping.State')) {
				$taxableState = $this->Session->read('Order.Shipping.State.id');
			} else {
				$abbreviation = Configure::read('store.tax.defaultState');
				$this->controller->loadModel('State');
				$state = $this->controller->State->find('first', array(
					'conditions' => array(
						'State.abbreviation' => $abbreviation,
					),
				));
				
				$taxableState = $state['State']['id'];
			}
			
			// Determine which rates apply
			$taxRates = array();
			foreach ($this->Session->read('Order.TaxRate') as $rate) {
				foreach ($rate['State'] as $state) {
					if ($state['id'] == $taxableState) {
						$rate['subtotal'] = 0;
						$taxRates[] = $rate;
					}
				}
			}
			
			if(!empty($taxRates)) {
				foreach($order['LineItem'] as $lineItem) {
					if ($lineItem['Product']['taxable'] == 'yes') {
						foreach ($taxRates as $key => $taxRate) {
							$amount = $lineItem['Totals']['subtotal'] * ($taxRate['rate'] / 100);
							$taxRates[$key]['subtotal'] += $amount;
							$tax += $amount;
						}
					}
				}
			}
			
			$this->Session->write('Order.TaxRate', $taxRates);
		}
		
		$shipping = 0;
		if ($this->shipRate > 0) {
			$shipping = ($this->shipFlat) ? $this->shipRate : $subtotal * $this->shipRate;
		} elseif (!empty($order['Totals']) && $order['Totals'] > 0) {
			$shipping = $order['Totals']['shipping'];	
		}
		
		$total = $subtotal + $tax + $shipping;
		
		$data = array(
			'subtotal' => $subtotal,
			'tax' => $tax,
			'shipping' => $shipping,
			'total' => $total,
		);
		
		return $this->Session->write('Order.Totals', $data);
	}
	
	function updateDatabaseRecord() {
		$this->controller->loadModel('Cart');

		$cart = $this->controller->Cart->find('first', array(
			'conditions' => array(
				'Cart.user_id' => $this->userId,
				'Cart.state' => 'active',
			),
		));

		if (!empty($cart)) {
			$this->controller->Cart->id = $cart['Cart']['id'];
			$this->controller->Cart->saveField('data', serialize($this->getOrderDetails()));
		} else {	
			$this->controller->Cart->create();
			$cart['Cart']['data'] = serialize($this->getOrderDetails());
			$cart['Cart']['user_id'] = $this->userId;	
			$this->controller->Cart->save($cart);					
		}
		
		$this->Session->write('cartId', $this->controller->Cart->id);
	}
	
	function deleteDatabaseRecord($id = null) {
		$this->controller->loadModel('Cart');

		$conditions = array();
		if ($id) {
			$conditions['Cart.id'] = $id;
		} else {
			$conditions['Cart.user_id'] = $this->userId;
			$conditions['Cart.state'] = 'active';
		}
		
		$cart = $this->controller->Cart->find('first', array(
			'conditions' => $conditions,
		));

		if (!empty($cart)) {
			$this->controller->Cart->del($cart['Cart']['id']);
		}
	}
	
	function mergeData($userId = null) {
		$reading = "no";
		if ($this->persistentCart) {
			$reading = "yes";
		}
		
		$this->log("User ID:" . $userId . ", Persist: $reading", LOG_DEBUG);
		$this->userId = $userId;
		
		if ($userId && $this->persistentCart) {
			$this->controller->loadModel('Cart');
	
			$cart = $this->controller->Cart->find('first', array(
				'conditions' => array(
					'Cart.user_id' => $userId,
					'Cart.state' => 'active',
				),
			));
			
			if (!empty($cart) && !$this->Session->check('Order.Totals')) {
				$this->log('[ShoppingCart] Merging carts: ' . serialize($cart), LOG_DEBUG);
				$cart = unserialize($cart['Cart']['data']);
			
				if(count($cart['LineItem']) > 0) {
					foreach ($cart['LineItem'] as $productId => $lineItem) {
						foreach($lineItem['Selection'] as $selection) {
							$this->addItem($productId, $selection['quantity'], $selection['attributes']);
						}
					}
				}
			} else {
				$this->log('[ShoppingCart] Updating record in DB. No merge needed.', LOG_DEBUG);
				$this->updateDatabaseRecord();
			}
		}
	}
}
?>
