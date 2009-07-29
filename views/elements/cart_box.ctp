<?php 
	$cart->init($session);
	if (!isset($currency)) $currency = 'USD';
?>
<div class="cart">
	<?php if ($cart->hasItems()): ?>				
		<h1><?php echo $html->link('Shopping Cart', array('controller' => 'products', 'action' => 'view_cart')); ?></h1>
		<strong>Item(s):</strong> <?php echo $cart->getValue('quantity'); ?>
		<span class="price"><?php echo $number->currency($cart->getValue('subtotal'), $currency); ?></span>
	<?php endif; ?>
</div>
