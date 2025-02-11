<?php

namespace App\Utils;

use App\MobileCardTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use App\Exceptions\PurchaseSellMismatch;

use App\PurchaseLine;
use App\Transaction;
use App\TransactionPayment;
use App\TransactionSellLine;
use App\Contact;
use App\TaxRate;
use App\InvoiceScheme;
use App\Variation;
use App\Product;
use App\VariationLocationDetails;
use App\BusinessLocation;
use App\Business;
use App\Currency;
use App\TransactionSellLinesPurchaseLines;
use App\Restaurant\ResTable;
use App\RewardedPoint;

use App\Utils\ProductUtil;
use App\Http\DataHelper;
use App\DeliveryInCart;

class TransactionUtil extends Util
{
    /**
     * Add Sell transaction
     *
     * @param int $business_id
     * @param array $input
     * @param float $invoice_total
     * @param int $user_id
     *
     * @return boolean
     */
    public function createSellTransaction($business_id, $input, $invoice_total, $user_id)
    {
        $transaction = Transaction::create([
            'business_id' => $business_id,
            'location_id' => $input['location_id'],
            'type' => 'sell',
            'status' => $input['status'],
            'contact_id' => $input['contact_id'],
            'customer_group_id' => $input['customer_group_id'],
            'invoice_no' => $this->getInvoiceNumber($business_id, $input['status'], $input['location_id']),
            'ref_no' => '',
            'total_before_tax' => $invoice_total['total_before_tax'],
            'transaction_date' => $input['transaction_date'],
            'tax_id' => !empty($input['tax_rate_id']) ? $input['tax_rate_id'] : null,
            'discount_type' => $input['discount_type'],
            'discount_amount' => $this->num_uf($input['discount_amount']),
            'tax_amount' => $invoice_total['tax'],
            'final_total' => $this->num_uf($input['final_total']),
            'additional_notes' => $input['sale_note'],
            'staff_note' => !empty($input['staff_note']) ? $input['staff_note'] : null,
            'points' => $input['points'],
            'created_by' => $user_id,
            'is_direct_sale' => !empty($input['is_direct_sale']) ? $input['is_direct_sale'] : 0,
            'commission_agent' => $input['commission_agent'],
            'is_quotation' => isset($input['is_quotation']) ? $input['is_quotation'] : 0,
            'shipping_details' => isset($input['shipping_details']) ? $input['shipping_details'] : null,
            'shipping_charges' => isset($input['shipping_charges']) ? $this->num_uf($input['shipping_charges']) : 0,
            'exchange_rate' => !empty($input['exchange_rate']) ? 
                                $this->num_uf($input['exchange_rate']) : 1,
            'selling_price_group_id' => isset($input['selling_price_group_id']) ? $input['selling_price_group_id'] : null,
            'delivery_uid' => !empty($input['delivery_uid']) && $input['delivery_uid'] != 0 ? $input['delivery_uid'] : null,
        ]);

        return $transaction;
    }

    public function createTransactionFromDelivery($deliveries, $uid)
    {
        $business_id = $deliveries[0]['business_id'];
        $location_id = $deliveries[0]['location_id'];
        $invoice_no = $this->getInvoiceNumber($business_id, 'final', $location_id);
        $transation_date = \Carbon::now();
        $tax_id = $deliveries[0]['tax_id'];
        $points = $deliveries[0]['points'];
        $delivery_uid = $deliveries[0]['uid'];

        $total_before_tax = 0;
        $total_widtout_delivery = 0;
        $total_tax = 0;
        $total_discount = 0;
        foreach($deliveries as $new_order)
        {
            $total_before_tax += $new_order['sub_total'];
            $total_discount += $new_order['discount'] * $new_order['product_quantity'];
            $total_tax += $new_order['tax'] * $new_order['product_quantity'];
        }
        // $total_tax += $new_orders[0]['delivery_tax'];
        $total_widtout_delivery = $total_before_tax + $total_tax - $total_discount;
        $total = $total_widtout_delivery + $deliveries[0]['delivery_price'] + $deliveries[0]['delivery_tax'];

        $transaction = Transaction::create([
			'business_id' => $business_id,
			'location_id' => $location_id,
			'type' => 'sell',
			'status' => 'final',
			'customer_group_id' => 0,
			'invoice_no' => $invoice_no,
			'ref_no' => '',
			'total_before_tax' => $total_before_tax,
			'transaction_date' => $transation_date,
			'discount_type' => "fixed",
			'discount_amount' => $total_discount,
			'tax_amount' => $total_tax,
			'final_total' => $total,
			'is_direct_sale' => 0,
			'is_quotation' => 0,
			'payment_status' => 'paid',
			'shipping_charges' => 0,
			'exchange_rate' => 1,
            'selling_price_group_id' => null,
            'tax_id'=>!empty($tax_id) ? $tax_id : null,
            'points' => $points,
            'delivery_uid' => $delivery_uid,
            'res_table_id' => null
        ]);

        foreach($deliveries as $new_order)
        {
            TransactionSellLine::create([
                'transaction_id' => $transaction->id,
                'product_id' => $new_order['product_id'],
                'variation_id' => $new_order['variation_id'],
                'quantity' => $new_order['product_quantity'],
                'unit_price_before_discount' =>$new_order['unit_price'] ,
                // 'unit_price' => $new_order['unit_price'] - $new_order['discount'],
                // 'line_discount_type' => 'fixed',
                // 'line_discount_amount' => $new_order['discount'],
                'unit_price' => $new_order['unit_price'],
                'line_discount_type' => 'fixed',
                'line_discount_amount' => 0,
                'unit_price_inc_tax' => $new_order['unit_price'],
                'item_tax' => 0,
                'tax_id' => $tax_id
            ]);
        }

        /////////////transaction payment
        $prefix_type = 'sell_payment';
        $ref_count = $this->setAndGetReferenceCount($prefix_type);
        //Generate reference number
        $payment_ref_no = $this->generateReferenceNumber($prefix_type, $ref_count);
        $is_cash = ($deliveries[0]['payment_method'] == 'Cash');
        TransactionPayment::create([
            'amount' => $total,
            'method' =>  $is_cash ? 'cash' : 'mobile',
            'is_return' => 0,
            'paid_on' => $transation_date,
            'payment_ref_no' => $payment_ref_no,
            'transaction_id' => $transaction->id,
            'card_type' => $is_cash ? null : 'visa'
        ]);

        $productUtil = new ProductUtil();
        foreach ($deliveries as $new_order) {
            $productUtil->decreaseProductQuantity(
                $new_order['product_id'],
                $new_order['variation_id'],
                $location_id,
                $productUtil->num_uf($new_order['product_quantity'])
            );
        }


        /////////////
        $business = ['id' => $business_id,
            'accounting_method' => "FIFO",
            'location_id' => $location_id
            ];
        $this->mapPurchaseSell($business, $transaction->sell_lines, 'purchase');


        // $point_ratio = 1;
        $cart_uid = $is_cash ? $uid : 'ccard-'.$uid;
        // $total_price = $total;
        // RewardedPoint::create(compact('business_id', 'location_id', 'points', 'point_ratio', 'total_price', 'cart_uid'));
        RewardedPoint::where('cart_uid', $cart_uid)
            ->update(['transaction_id' => $transaction->id]);
        /**Add points to current business*/
        // BusinessLocation::where('id', $location_id)->increment('points', $points);
    }

    public function increasePointsFromDelivery($deliveries, $uid)
    {
        $is_cash = ($deliveries[0]['payment_method'] == 'Cash');
        $business_id = $deliveries[0]['business_id'];
        $location_id = $deliveries[0]['location_id'];
        $points = $deliveries[0]['points'];

        $total_before_tax = 0;
        $total_tax = 0;
        $total_discount = 0;
        foreach($deliveries as $new_order)
        {
            $total_before_tax += $new_order['sub_total'];
            $total_discount += $new_order['discount'] * $new_order['product_quantity'];
            $total_tax += $new_order['tax'] * $new_order['product_quantity'];
        }
        // $total_tax += $new_orders[0]['delivery_tax'];
        $total_price = $total_before_tax + $total_tax - $total_discount + $deliveries[0]['delivery_price'] + $deliveries[0]['delivery_tax'];

        $point_ratio = 1;
        $cart_uid = $is_cash ? $uid : 'ccard-'.$uid;
        $purchased = 1;
        RewardedPoint::create(compact('business_id', 'location_id', 'points', 'point_ratio', 'total_price', 'cart_uid', 'purchased'));
        // RewardedPoint::where('cart_uid', $cart_uid)
        //     ->update(['purchased' => true]);
        /**Add points to current business*/
        BusinessLocation::where('id', $location_id)->increment('points', $points);
    }

    public function decreasePointsFromDelivery($deliveries, $uid) 
    {
        $is_cash = ($deliveries[0]['payment_method'] == 'Cash');
        $location_id = $deliveries[0]['location_id'];
        $points = $deliveries[0]['points'];
        $cart_uid = $is_cash ? $uid : 'ccard-'.$uid;
        if( RewardedPoint::where('cart_uid', $cart_uid)
                    ->delete() )
        {
            /**Remove points to current business*/
            BusinessLocation::where('id', $location_id)->decrement('points', $points);
        }
    }
	/**
	 * Add Sell transaction from mobile credit card
	 *
	 * @param int $business_id
	 * @param array $input
	 * @param float $invoice_total
	 *
	 * @return boolean
	 */
	public function createMobileCardSellTransaction($business_id, $input, $invoice_total)
	{
		$transaction = Transaction::create([
			'business_id' => $business_id,
			'location_id' => $input['location_id'],
			'type' => 'sell',
			'status' => $input['status'],
			'customer_group_id' => 0,
			'invoice_no' => $this->getInvoiceNumber($business_id, $input['status'], $input['location_id']),
			'ref_no' => '',
			'total_before_tax' => $invoice_total['total_before_tax'],
			'transaction_date' => $input['transaction_date'],
			'discount_type' => "percentage",
			'discount_amount' => $invoice_total['discount'],
			'tax_amount' => $invoice_total['tax'],
			'final_total' => $this->num_uf($input['final_total']),
			'staff_note' => !empty($input['staff_note']) ? $input['staff_note'] : null,
			'is_direct_sale' => !empty($input['is_direct_sale']) ? $input['is_direct_sale'] : 0,
			'is_quotation' => isset($input['is_quotation']) ? $input['is_quotation'] : 0,
			'payment_status' => 'paid',
			'shipping_charges' => isset($input['shipping_charges']) ? $this->num_uf($input['shipping_charges']) : 0,
			'exchange_rate' => !empty($input['exchange_rate']) ?
				$this->num_uf($input['exchange_rate']) : 1,
            'selling_price_group_id' => isset($input['selling_price_group_id']) ? $input['selling_price_group_id'] : null,
            'tax_id'=>!empty($input['tax_id']) ? $input['tax_id'] : null,
            'points' => $invoice_total['points'],
            'delivery_uid' => !empty($input['delivery_uid']) && $input['delivery_uid'] != 0 ? $input['delivery_uid'] : null,
            'res_table_id' => !empty($input['res_table_id']) ? $input['res_table_id'] : null
		]);

		return $transaction;
	}

    /**
     * Add Sell transaction
     *
     * @param int $transaction_id
     * @param int $business_id
     * @param array $input
     * @param float $invoice_total
     * @param int $user_id
     *
     * @return boolean
     */
    public function updateSellTransaction($transaction_id, $business_id, $input, $invoice_total, $user_id)
    {
        $transaction = Transaction::where('id', $transaction_id)
                        ->where('business_id', $business_id)
                        ->firstOrFail();

        //Update invoice number if changed from draft to finalize or vice-versa
        $invoice_no = $transaction->invoice_no;
        if ($transaction->status != $input['status']) {
            $invoice_no = $this->getInvoiceNumber($business_id, $input['status'], $transaction->location_id);
        }

        $update_date = [
            'status' => $input['status'],
            'invoice_no' => $invoice_no,
            'contact_id' => $input['contact_id'],
            'customer_group_id' => $input['customer_group_id'],
            'total_before_tax' => $invoice_total['total_before_tax'],
            'tax_id' => $input['tax_rate_id'],
            'discount_type' => $input['discount_type'],
            'discount_amount' => $this->num_uf($input['discount_amount']),
            'tax_amount' => $invoice_total['tax'],
            'final_total' => $this->num_uf($input['final_total']),
            'additional_notes' => $input['sale_note'],
            'points' => (int)$input['points'],
            'staff_note' => !empty($input['staff_note']) ? $input['staff_note'] : null,
            'commission_agent' => $input['commission_agent'],
            'is_quotation' => isset($input['is_quotation']) ? $input['is_quotation'] : 0,
            'shipping_details' => isset($input['shipping_details']) ? $input['shipping_details'] : null,
            'shipping_charges' => isset($input['shipping_charges']) ? $this->num_uf($input['shipping_charges']) : 0,
            'exchange_rate' => !empty($input['exchange_rate']) ? 
                                $this->num_uf($input['exchange_rate']) : 1,
            'selling_price_group_id' => isset($input['selling_price_group_id']) ? $input['selling_price_group_id'] : null
        ];

        if (!empty($input['transaction_date'])) {
            $update_date['transaction_date'] = $input['transaction_date'];
        }
        
        $transaction->fill($update_date);
        $transaction->update();

        return $transaction;
    }

    /**
     * Add/Edit transaction sell lines
     *
     * @param object/int $transaction
     * @param array $products
     * @param array $location_id
     * @param boolean $return_deleted
     *
     * @return boolean/object
     */
    public function createOrUpdateSellLines($transaction, $products, $location_id, $return_deleted = false)
    {
        $lines_formatted = [];
        $modifiers_array = [];
        $edit_ids = [0];
        $modifiers_formatted = [];

        foreach ($products as $product) {
            //Check if transaction_sell_lines_id is set.
            if (!empty($product['transaction_sell_lines_id'])) {
                $edit_ids[] = $product['transaction_sell_lines_id'];
                $this->editSellLine($product, $location_id);

                //update or create modifiers for existing sell lines
                if ($this->isModuleEnabled('modifiers')) {
                    if (!empty($product['modifier'])) {
                        foreach ($product['modifier'] as $key => $value) {
                            if (!empty($product['modifier_sell_line_id'][$key])) {
                                //Dont delete modifier sell line if exists
                                $edit_ids[] = $product['modifier_sell_line_id'][$key];
                            } else {
                                if (!empty($product['modifier_price'][$key])) {
                                    $this_price = $this->num_uf($product['modifier_price'][$key]);
                                    $modifiers_formatted[] = new TransactionSellLine([
                                        'product_id' => $product['modifier_set_id'][$key],
                                        'variation_id' => $value,
                                        'quantity' => 1,
                                        'unit_price_before_discount' => $this_price,
                                        'unit_price' => $this_price,
                                        'unit_price_inc_tax' => $this_price,
                                        'parent_sell_line_id' => $product['transaction_sell_lines_id']
                                    ]);
                                }
                            }
                        }
                    }
                }
            } else {
                //calculate unit price and unit price before discount
                $unit_price_before_discount = $this->num_uf($product['unit_price']);
                $unit_price = $unit_price_before_discount;
                if (!empty($product['line_discount_type']) && $product['line_discount_amount']) {
                    $discount_amount = $this->num_uf($product['line_discount_amount']);
                    if ($product['line_discount_type'] == 'fixed') {
                        $unit_price = $unit_price_before_discount - $discount_amount;
                    } elseif ($product['line_discount_type'] == 'percentage') {
                        $unit_price = ((100 - $discount_amount) * $unit_price_before_discount) / 100;
                    }
                }

                $line = [
                    'product_id' => $product['product_id'],
                    'variation_id' => $product['variation_id'],
                    'quantity' => $this->num_uf($product['quantity']),
                    'unit_price_before_discount' => $unit_price_before_discount,
                    'unit_price' => $unit_price,
                    'line_discount_type' => !empty($product['line_discount_type']) ? $product['line_discount_type'] : null,
                    'line_discount_amount' => !empty($product['line_discount_amount']) ? $this->num_uf($product['line_discount_amount']) : 0,
                    'item_tax' => 0,
                    'tax_id' => !empty($product['tax_id']) ? $product['tax_id']: null,
//                    'unit_price_inc_tax' => $this->num_uf($product['unit_price_inc_tax']),
                    'unit_price_inc_tax' => $unit_price_before_discount,
                    'sell_line_note' => !empty($product['sell_line_note']) ? $product['sell_line_note'] : ''
                ];

                if (request()->session()->get('business.enable_lot_number') == 1 && !empty($product['lot_no_line_id'])) {
                    $line['lot_no_line_id'] = $product['lot_no_line_id'];
                }

                //Check if restaurant module is enabled then add more data related to that.
                if ($this->isModuleEnabled('modifiers')) {
                    $sell_line_modifiers = [];

                    if (!empty($product['modifier'])) {
                        foreach ($product['modifier'] as $key => $value) {
                            if (!empty($product['modifier_price'][$key])) {
                                $this_price = $this->num_uf($product['modifier_price'][$key]);
                                $sell_line_modifiers[] = [
                                    'product_id' => $product['modifier_set_id'][$key],
                                    'variation_id' => $value,
                                    'quantity' => 1,
                                    'unit_price_before_discount' => $this_price,
                                    'unit_price' => $this_price,
                                    'unit_price_inc_tax' => $this_price
                                ];
                            }
                        }
                    }
                    $modifiers_array[] = $sell_line_modifiers;
                }


                $lines_formatted[] = new TransactionSellLine($line);
            }
        }

        if (!is_object($transaction)) {
            $transaction = Transaction::findOrFail($transaction);
        }

        //Delete the products removed and increment product stock.
        $deleted_lines = [];
        if (!empty($edit_ids)) {
            $deleted_lines = TransactionSellLine::where('transaction_id', $transaction->id)->whereNotIn('id', $edit_ids)->select('id')->get()->toArray();
            $this->deleteSellLines($deleted_lines, $location_id);
        }

        if (!empty($lines_formatted)) {
            $transaction->sell_lines()->saveMany($lines_formatted);

            //Add corresponding modifier sell lines if exists
            if ($this->isModuleEnabled('modifiers')) {
                foreach ($lines_formatted as $key => $value) {
                    if (!empty($modifiers_array[$key])) {
                        foreach ($modifiers_array[$key] as $modifier) {
                            $modifier['parent_sell_line_id'] = $value->id;
                            $modifiers_formatted[] = new TransactionSellLine($modifier);
                        }
                    }
                }
            }
        }

        if (!empty($modifiers_formatted)) {
            $transaction->sell_lines()->saveMany($modifiers_formatted);
        }

        if ($return_deleted) {
            return $deleted_lines;
        }
        return true;
    }

    /**
     * Edit transaction sell line
     *
     * @param array $product
     * @param int $location_id
     *
     * @return boolean
     */
    public function editSellLine($product, $location_id)
    {
        //Get the old order quantity
        $sell_line = TransactionSellLine::find($product['transaction_sell_lines_id']);

        //Adjust quanity
        $difference = $sell_line->quantity - $this->num_uf($product['quantity']);
        $this->adjustQuantity($location_id, $product['product_id'], $product['variation_id'], $difference);

        $unit_price_before_discount = $this->num_uf($product['unit_price']);
        $unit_price = $unit_price_before_discount;
        if (!empty($product['line_discount_type']) && $product['line_discount_amount']) {
            $discount_amount = $this->num_uf($product['line_discount_amount']);
            if ($product['line_discount_type'] == 'fixed') {
                $unit_price = $unit_price_before_discount - $discount_amount;
            } elseif ($product['line_discount_type'] == 'percentage') {
                $unit_price = ((100 - $discount_amount) * $unit_price_before_discount) / 100;
            }
        }

        //Update sell lines.
        $sell_line->fill(['product_id' => $product['product_id'],
                'variation_id' => $product['variation_id'],
                'quantity' => $this->num_uf($product['quantity']),
                'unit_price_before_discount' => $unit_price_before_discount,
                'unit_price' => $unit_price,
                'line_discount_type' => !empty($product['line_discount_type']) ? $product['line_discount_type'] : null,
                'line_discount_amount' => !empty($product['line_discount_amount']) ? $this->num_uf($product['line_discount_amount']) : 0,
                'item_tax' => $this->num_uf($product['item_tax']),
                'tax_id' => $product['tax_id'],
                'unit_price_inc_tax' => $this->num_uf($product['unit_price_inc_tax']),
                'sell_line_note' => !empty($product['sell_line_note']) ? $product['sell_line_note'] : '',
            ]);
        $sell_line->save();
    }

    /**
     * Delete the products removed and increment product stock.
     *
     * @param array $transaction_line_ids
     * @param int $location_id
     *
     * @return boolean
     */
    public function deleteSellLines($transaction_line_ids, $location_id)
    {
        if (!empty($transaction_line_ids)) {
            $sell_lines = TransactionSellLine::whereIn('id', $transaction_line_ids)
                        ->get();

            //Adjust quanity
            foreach ($sell_lines as $line) {
                $this->adjustQuantity($location_id, $line->product_id, $line->variation_id, $line->quantity);
            }

            TransactionSellLine::whereIn('id', $transaction_line_ids)
                ->delete();
        }
    }

    /**
     * Adjust the quantity of product and its variation
     *
     * @param int $location_id
     * @param int $product_id
     * @param int $variation_id
     * @param float $increment_qty
     *
     * @return boolean
     */
    private function adjustQuantity($location_id, $product_id, $variation_id, $increment_qty)
    {
        if ($increment_qty != 0) {
            $enable_stock = Product::find($product_id)->enable_stock;

            if ($enable_stock == 1) {
                //Adjust Quantity in variations location table
                VariationLocationDetails::where('variation_id', $variation_id)
                ->where('product_id', $product_id)
                ->where('location_id', $location_id)
                ->increment('qty_available', $increment_qty);

                //TODO:Update quantity in products table
                // Product::where('id', $product_id)
                //     ->increment('total_qty_available', $increment_qty);
            }
        }
    }

	/**
	 * Add line for payment
	 *
	 * @param $transaction
	 * @param array $payments
	 *
	 * @param string $receipt_no : receipt number in card machine payment
	 * @param int $uid
	 * @return boolean
	 */
    public function createOrUpdatePaymentLines($transaction, $payments, $uid, $receipt_no)
    {
        $payments_formatted = [];
        $edit_ids = [0];

        if (!is_object($transaction)) {
            $transaction = Transaction::findOrFail($transaction);
        }

        //If status is draft don't add payment
        if($transaction->status == 'draft'){
            return true;
        }
        
        foreach ($payments as $payment) {
            //Check if transaction_sell_lines_id is set.
            if (!empty($payment['payment_id'])) {
                $edit_ids[] = $payment['payment_id'];
                $this->editPaymentLine($payment);
            } else {
                if (array_key_exists('points', $payment)) {
                    //If amount is 0 then skip.
                    if ($this->num_uf($payment['amount']) != 0 || $payment['points'] != 0) {
                        $prefix_type = 'sell_payment';
                        if ($transaction->type == 'purchase') {
                            $prefix_type = 'purchase_payment';
                        }
                        $ref_count = $this->setAndGetReferenceCount($prefix_type);
                        //Generate reference number
                        $payment_ref_no = $this->generateReferenceNumber($prefix_type, $ref_count);
                        $payment_data = [
                            'amount' => $this->num_uf($payment['amount']),
                            'method' => $payment['method'],
                            'is_return' => isset($payment['is_return']) ? $payment['is_return'] : 0,
                            'card_transaction_number' => $payment['card_transaction_number'],
                            'card_number' => $payment['card_number'],
                            'card_type' => $payment['card_type'],
                            'card_holder_name' => $payment['card_holder_name'],
                            'card_month' => $payment['card_month'],
                            'card_security' => $payment['card_security'],
                            'cheque_number' => $payment['cheque_number'],
                            'bank_account_number' => $payment['bank_account_number'],
                            'note' => $payment['note'],
                            'paid_on' => !empty($payment['paid_on']) ? $payment['paid_on'] : \Carbon::now()->toDateTimeString(),
                            'created_by' => auth()->user()->id,
                            'payment_for' => $transaction->contact_id,
                            'payment_ref_no' => $payment_ref_no,
                            'uid' => $uid,
                            'receipt_no' => $receipt_no
                        ];
                        if ($payment['method'] == 'custom_pay_1') {
                            $payment_data['transaction_no'] = $payment['transaction_no_1'];
                        } else if ($payment['method'] == 'custom_pay_2') {
                            $payment_data['transaction_no'] = $payment['transaction_no_2'];
                        } else if ($payment['method'] == 'custom_pay_3') {
                            $payment_data['transaction_no'] = $payment['transaction_no_3'];
                        }

                        $payments_formatted[] = new TransactionPayment($payment_data);
                    }
                } else {
                    if ($this->num_uf($payment['amount']) != 0) {
                        $prefix_type = 'sell_payment';
                        if ($transaction->type == 'purchase') {
                            $prefix_type = 'purchase_payment';
                        }
                        $ref_count = $this->setAndGetReferenceCount($prefix_type);
                        //Generate reference number
                        $payment_ref_no = $this->generateReferenceNumber($prefix_type, $ref_count);
                        $payment_data = [
                            'amount' => $this->num_uf($payment['amount']),
                            'method' => $payment['method'],
                            'is_return' => isset($payment['is_return']) ? $payment['is_return'] : 0,
                            'card_transaction_number' => $payment['card_transaction_number'],
                            'card_number' => $payment['card_number'],
                            'card_type' => $payment['card_type'],
                            'card_holder_name' => $payment['card_holder_name'],
                            'card_month' => $payment['card_month'],
                            'card_security' => $payment['card_security'],
                            'cheque_number' => $payment['cheque_number'],
                            'bank_account_number' => $payment['bank_account_number'],
                            'note' => $payment['note'],
                            'paid_on' => !empty($payment['paid_on']) ? $payment['paid_on'] : \Carbon::now()->toDateTimeString(),
                            'created_by' => auth()->user()->id,
                            'payment_for' => $transaction->contact_id,
                            'payment_ref_no' => $payment_ref_no,
                            'uid' => $uid,
                            'receipt_no' => $receipt_no
                        ];
                        if ($payment['method'] == 'custom_pay_1') {
                            $payment_data['transaction_no'] = $payment['transaction_no_1'];
                        } else if ($payment['method'] == 'custom_pay_2') {
                            $payment_data['transaction_no'] = $payment['transaction_no_2'];
                        } else if ($payment['method'] == 'custom_pay_3') {
                            $payment_data['transaction_no'] = $payment['transaction_no_3'];
                        }

                        $payments_formatted[] = new TransactionPayment($payment_data);
                    }
                }
            }
        }

        //Delete the payment lines removed.
        if (!empty($edit_ids)) {
            $transaction->payment_lines()->whereNotIn('id', $edit_ids)->delete();
        }

        if (!empty($payments_formatted)) {
            $transaction->payment_lines()->saveMany($payments_formatted);
        }

        return true;
    }

    /**
     * Edit transaction payment line
     *
     * @param array $product
     *
     * @return boolean
     */
    public function editPaymentLine($payment)
    {
        $payment_id = $payment['payment_id'];
        unset($payment['payment_id']);

        if ($payment['method'] == 'custom_pay_1') {
            $payment['transaction_no'] = $payment['transaction_no_1'];
        } else if ($payment['method'] == 'custom_pay_2') {
            $payment['transaction_no'] = $payment['transaction_no_2'];
        } else if ($payment['method'] == 'custom_pay_3') {
            $payment['transaction_no'] = $payment['transaction_no_3'];
        }

        unset($payment['transaction_no_1']);
        unset($payment['transaction_no_2']);
        unset($payment['transaction_no_3']);
        
        $payment['amount'] = $this->num_uf($payment['amount']);
        TransactionPayment::where('id', $payment_id)
            ->update($payment);

        return true;
    }

    /**
     * Get payment line for a transaction
     *
     * @param int $transaction_id
     *
     * @return boolean
     */
    public function getPaymentDetails($transaction_id)
    {
        $payment_lines = TransactionPayment::where('transaction_id', $transaction_id)
                    ->get()->toArray();

        return $payment_lines;
    }

    /**
     * Gives the receipt details in proper format.
     *
     * @param int $transaction_id
     * @param int $location_id
     * @param object $invoice_layout
     * @param array $business_details
     * @param array $receipt_details
     * @param string $receipt_printer_type
     *
     * @return array
     */
    public function getReceiptDetails($transaction_id, $location_id, $invoice_layout, $business_details, $location_details, $receipt_printer_type)
    {
        $il = $invoice_layout;

        $transaction = Transaction::find($transaction_id);
        $transaction_type = $transaction->type;

        $output = [
            'header_text' => isset($il->header_text) ? $il->header_text : '',
            'business_name' => ($il->show_business_name == 1) ? $business_details->name : '',
            'location_name' => ($il->show_location_name == 1) ? $location_details->name : '',
            'sub_heading_line1' => trim($il->sub_heading_line1),
            'sub_heading_line2' => trim($il->sub_heading_line2),
            'sub_heading_line3' => trim($il->sub_heading_line3),
            'sub_heading_line4' => trim($il->sub_heading_line4),
            'sub_heading_line5' => trim($il->sub_heading_line5),
            'table_product_label' => $il->table_product_label,
            'table_qty_label' => $il->table_qty_label,
            'table_unit_price_label' => $il->table_unit_price_label,
            'table_subtotal_label' => $il->table_subtotal_label,
        ];

        //Display name
        $output['display_name'] = $output['business_name'];
        if (!empty($output['location_name'])) {
            if (!empty($output['display_name'])) {
                $output['display_name'] .= ', ';
            }
            $output['display_name'] .= $output['location_name'];
        }

        //Logo
        $output['logo'] = ($il->show_logo != 0 && !empty($il->logo) && Storage::exists('public/invoice_logos/' . $il->logo)) ? config('app.url') . Storage::url('public/invoice_logos/' . $il->logo) : false;

        //Address
        $output['address'] = '';
        $temp = [];
        if ($il->show_landmark == 1) {
            $output['address'] .= $location_details->landmark . "\n";
        }
        if ($il->show_city == 1 &&  !empty($location_details->city)) {
            $temp[] = $location_details->city;
        }
        if ($il->show_state == 1 &&  !empty($location_details->state)) {
            $temp[] = $location_details->state;
        }
        if ($il->show_zip_code == 1 &&  !empty($location_details->zip_code)) {
            $temp[] = $location_details->zip_code;
        }
        if ($il->show_country == 1 &&  !empty($location_details->country)) {
            $temp[] = $location_details->country;
        }
        if (!empty($temp)) {
            $output['address'] .= implode(',', $temp);
        }

        $output['website'] = $location_details->website;
        $output['location_custom_fields'] = '';
        $temp = [];
        if (!empty($location_details->custom_field1)) {
            $temp[] = $location_details->custom_field1;
        }
        if (!empty($location_details->custom_field2)) {
            $temp[] = $location_details->custom_field2;
        }
        if (!empty($location_details->custom_field3)) {
            $temp[] = $location_details->custom_field3;
        }
        if (!empty($location_details->custom_field4)) {
            $temp[] = $location_details->custom_field4;
        }
        if (!empty($temp)) {
            $output['location_custom_fields'] .= implode(', ', $temp);
        }


        //Tax Info
        if ($il->show_tax_1 == 1 && !empty($business_details->tax_number_1)) {
            $output['tax_label1'] = !empty($business_details->tax_label_1) ? $business_details->tax_label_1 . ': ' : '';

            $output['tax_info1'] = $business_details->tax_number_1;
        }
        if ($il->show_tax_2 == 1 && !empty($business_details->tax_number_2)) {
            if (!empty($output['tax_info1'])) {
                $output['tax_info1'] .= ', ';
            }

            $output['tax_label2'] = !empty($business_details->tax_label_2) ? $business_details->tax_label_2 . ': ' : '';

            $output['tax_info2'] = $business_details->tax_number_2;
        }

        //Shop Contact Info
        $output['contact'] = '';
        if ($il->show_mobile_number == 1 && !empty($location_details->mobile)) {
            $output['contact'] .= 'Mobile: ' . $location_details->mobile;
        }
        if ($il->show_alternate_number == 1 && !empty($location_details->alternate_number)) {
            if (empty($output['contact'])) {
                $output['contact'] .= 'Mobile: ' . $location_details->alternate_number;
            } else {
                $output['contact'] .= ', ' . $location_details->alternate_number;
            }
        }
        if ($il->show_email == 1 && !empty($location_details->email)) {
            if (!empty($output['contact'])) {
                $output['contact'] .= "\n";
            }
            $output['contact'] .= 'Email: ' . $location_details->email;
        }

        //Customer show_customer
        $customer = Contact::find($transaction->contact_id);

        $output['customer_info'] = '';
        $output['customer_tax_number'] = '';
        $output['customer_tax_label'] = '';
        $output['customer_custom_fields'] = '';
        if ($il->show_customer == 1) {
            $output['customer_label'] = !empty($il->customer_label) ? $il->customer_label : '';
            
            $output['customer_info'] .= !empty($customer->name) ? $customer->name: '';
            if (!empty($output['customer_info']) && $receipt_printer_type != 'printer') {
                $output['customer_info'] .= '<br/>' . $customer->landmark;
                $output['customer_info'] .= '<br/>' . implode(',', array_filter([$customer->city, $customer->state, $customer->country]));
                $output['customer_info'] .= '<br/>' . $customer->mobile;
            }

            $output['customer_tax_number'] = !empty($customer->tax_number) ? $customer->tax_number : '';
            $output['customer_tax_label'] = !empty($il->client_tax_label) ? $il->client_tax_label : '';

            $temp = [];
            if (!empty($customer->custom_field1)) {
                $temp[] = $customer->custom_field1;
            }
            if (!empty($customer->custom_field2)) {
                $temp[] = $customer->custom_field2;
            }
            if (!empty($customer->custom_field3)) {
                $temp[] = $customer->custom_field3;
            }
            if (!empty($customer->custom_field4)) {
                $temp[] = $customer->custom_field4;
            }
            if (!empty($temp)) {
                $output['customer_custom_fields'] .= implode(',', $temp);
            }
        }

        $output['client_id'] = '';
        $output['client_id_label'] = '';
        if ($il->show_client_id == 1) {
            $output['client_id_label'] = !empty($il->client_id_label) ? $il->client_id_label : '';
            $output['client_id'] = !empty($customer->contact_id) ? $customer->contact_id : '';
        }

        //Sales person info
        $output['sales_person'] = '';
        $output['sales_person_label'] = '';
        if ($il->show_sales_person == 1) {
            $output['sales_person_label'] = !empty($il->sales_person_label) ? $il->sales_person_label : '';
            $output['sales_person'] = !empty($transaction->sales_person->user_full_name) ? $transaction->sales_person->user_full_name : '';
        }

        //Invoice info
        $output['invoice_no'] = $transaction->invoice_no;

        //Heading & invoice label, when quotation use the quotation heading.
        if ($transaction_type == 'sell_return') {
            $output['invoice_heading'] = $il->cn_heading;
            $output['invoice_no_prefix'] = $il->cn_no_label;
        } elseif ($transaction->status == 'draft' && $transaction->is_quotation == 1) {
            $output['invoice_heading'] = $il->quotation_heading;
            $output['invoice_no_prefix'] = $il->quotation_no_prefix;
        } else {
            $output['invoice_no_prefix'] = $il->invoice_no_prefix;
            $output['invoice_heading'] = $il->invoice_heading;
            if ($transaction->payment_status == 'paid' && !empty($il->invoice_heading_paid)) {
                $output['invoice_heading'] .= ' ' . $il->invoice_heading_paid;
            } elseif (in_array($transaction->payment_status, ['due', 'partial']) && !empty($il->invoice_heading_not_paid)) {
                $output['invoice_heading'] .= ' ' . $il->invoice_heading_not_paid;
            }
        }

        $output['date_label'] = $il->date_label;
        if ($il->show_time == 1) {
             $output['invoice_date'] = \Carbon::createFromFormat('Y-m-d H:i:s', $transaction->transaction_date)->format('M d, Y H:i');
        } else {
            $output['invoice_date'] = \Carbon::createFromFormat('Y-m-d H:i:s', $transaction->transaction_date)->toFormattedDateString();
        }
        
        $show_currency = true;
        if ($receipt_printer_type == 'printer' && trim(session('currency')['symbol']) != '$') {
            $show_currency = false;
        }

        //Invoice product lines
        $is_lot_number_enabled = request()->session()->get('business.enable_lot_number');
        $is_product_expiry_enabled = request()->session()->get('business.enable_product_expiry');

        $output['lines'] = [];
        if ($transaction_type == 'sell') {
            $sell_line_relations = ['modifiers'];

            if ($is_lot_number_enabled == 1) {
                $sell_line_relations[] = 'lot_details';
            }

            $lines = $transaction->sell_lines()->whereNull('parent_sell_line_id')->with($sell_line_relations)->get();

            $details = $this->_receiptDetailsSellLines($lines, $il, $is_product_expiry_enabled, $is_lot_number_enabled);
            $output['lines'] = $details['lines'];
            $output['taxes'] = $details['taxes']['taxes'];
        } elseif ($transaction_type == 'sell_return') {
            $lines = $transaction->purchase_lines;

            $details = $this->_receiptDetailsSellReturnLines($lines, $il, $is_product_expiry_enabled, $is_lot_number_enabled);
            $output['lines'] = $details['lines'];
            $output['taxes'] = $details['taxes']['taxes'];
        }

        //show cat code
        $output['show_cat_code'] = $il->show_cat_code;
        $output['cat_code_label'] = $il->cat_code_label;

        //Subtotal
        $output['subtotal_label'] = $il->sub_total_label . ':';
        $output['subtotal'] = ($transaction->total_before_tax != 0) ? $this->num_f($transaction->total_before_tax, $show_currency) : 0;

        //Discount
        $output['line_discount_label'] = $invoice_layout->discount_label;
        $output['discount_label'] = $invoice_layout->discount_label;
        $output['discount_label'] .= ($transaction->discount_type == 'percentage') ? ' (' . $transaction->discount_amount . '%) :' : '';

        if ($transaction->discount_type == 'percentage') {
            $discount = ($transaction->discount_amount/100) * $transaction->total_before_tax;
        } else {
            $discount = $transaction->discount_amount;
        }
        $output['discount'] = ($discount != 0) ? $this->num_f($discount, $show_currency) : 0;

        //Format tax
        if (!empty($output['taxes'])) {
            foreach ($output['taxes'] as $key => $value) {
                $output['taxes'][$key] = $this->num_f($value, $show_currency);
            }
        }

        //Order Tax
        $tax = $transaction->tax;
        $output['tax_label'] = $invoice_layout->tax_label;
        $output['line_tax_label'] = $invoice_layout->tax_label;
        if (!empty($tax) && !empty($tax->name)) {
            $output['tax_label'] .= ' (' . $tax->name . ')';
        }
        $output['tax_label'] .= ':';
        $output['tax'] = ($transaction->tax_amount != 0) ? $this->num_f($transaction->tax_amount, $show_currency) : 0;
        if ($transaction->tax_amount != 0 && $tax->is_tax_group) {
            $output['group_tax_details'] = $this->groupTaxDetails($tax, $transaction->tax_amount);

            foreach ($output['group_tax_details'] as $key => $value) {
                $output['group_tax_details'][$key] = $this->num_f($value, $show_currency);
            }
        }

        //Shipping charges
        $output['shipping_charges'] = ($transaction->shipping_charges != 0) ? $this->num_f($transaction->shipping_charges, $show_currency) : 0;
        $output['shipping_charges_label'] = trans("sale.shipping_charges");
        //Shipping details
        $output['shipping_details'] = $transaction->shipping_details;
        $output['shipping_details_label'] = trans("sale.shipping_details");

        //Delivery
        if(!empty($transaction->delivery_uid)) {
            $delivery_detail = DeliveryInCart::where('uid', $transaction->delivery_uid)
                                ->first();
            if(!empty($delivery_detail)) {
                $output['delivery'] = $delivery_detail->delivery_price;
                $output['delivery_label'] = trans("sale.delivery");

                $output['delivery_tax'] = $delivery_detail->delivery_tax;
                $output['delivery_tax_label'] = trans("sale.delivery_tax");
            }
        }
        //Total
        if ($transaction_type == 'sell_return') {
            $output['total_label'] = $invoice_layout->cn_amount_label . ':';
            $output['total'] = $this->num_f($transaction->final_total, $show_currency);
        } else {
            $output['total_label'] = $invoice_layout->total_label . ':';
            $output['total'] = $this->num_f($transaction->final_total, $show_currency);
        }

        //Paid & Amount due, only if final
        if ($transaction_type == 'sell' && $transaction->status == 'final') {
            $paid_amount = $this->getTotalPaid($transaction->id);
            $due = $transaction->final_total - $paid_amount;

            $output['total_paid'] = ($paid_amount == 0) ? 0 : $this->num_f($paid_amount, $show_currency);
            $output['total_paid_label'] = $il->paid_label;
            $output['total_due'] = ($due == 0) ? 0 : $this->num_f($due, $show_currency);
            $output['total_due_label'] = $il->total_due_label;
            $output['total_bullet_label'] = 'Total Bullet';
            $output['total_bullet'] = $transaction->points;

            //Get payment details
            $output['payments'] = [];
            if ($il->show_payments == 1) {
                $payments = $transaction->payment_lines->toArray();
                if (!empty($payments)) {
                    foreach ($payments as $value) {
                        if ($value['method'] == 'cash') {
                            $output['payments'][] =
                                ['method' => trans("lang_v1.cash") . ($value['is_return'] == 1 ? ' (' . trans("lang_v1.change_return") . ')(-)' : ''),
                                'amount' => $this->num_f($value['amount'], true),
                                'date' => $this->format_date($value['paid_on'])
                                ];
                            if ($value['is_return'] == 1) {
                            }
                        } elseif ($value['method'] == 'card') {
                            $output['payments'][] =
                                ['method' => trans("lang_v1.card") . (!empty($value['card_transaction_number']) ? (', Transaction Number:' . $value['card_transaction_number']) : ''),
                                'amount' => $this->num_f($value['amount'], true),
                                'date' => $this->format_date($value['paid_on'])
                                ];
                        } elseif ($value['method'] == 'cheque') {
                            $output['payments'][] =
                                ['method' => trans("lang_v1.cheque") . (!empty($value['cheque_number']) ? (', Cheque Number:' . $value['cheque_number']) : ''),
                                'amount' => $this->num_f($value['amount'], true),
                                'date' => $this->format_date($value['paid_on'])
                                ];
                        } elseif ($value['method'] == 'bank_transfer') {
                            $output['payments'][] =
                                ['method' => trans("lang_v1.bank_transfer") . (!empty($value['bank_account_number']) ? (', Account Number:' . $value['bank_account_number']) : ''),
                                'amount' => $this->num_f($value['amount'], true),
                                'date' => $this->format_date($value['paid_on'])
                                ];
                        } elseif ($value['method'] == 'other') {
                            $output['payments'][] =
                                ['method' => trans("lang_v1.other"),
                                'amount' => $this->num_f($value['amount'], true),
                                'date' => $this->format_date($value['paid_on'])
                                ];
                        } elseif ($value['method'] == 'custom_pay_1') {
                            $output['payments'][] =
                                ['method' => trans("lang_v1.custom_payment_1") . (!empty($value['transaction_no']) ? (', ' . trans("lang_v1.transaction_no") . ':' . $value['transaction_no']) : ''),
                                'amount' => $this->num_f($value['amount'], true),
                                'date' => $this->format_date($value['paid_on'])
                                ];
                        } elseif ($value['method'] == 'custom_pay_2') {
                            $output['payments'][] =
                                ['method' => trans("lang_v1.custom_payment_2") . (!empty($value['transaction_no']) ? (', ' . trans("lang_v1.transaction_no") . ':' . $value['transaction_no']) : ''),
                                'amount' => $this->num_f($value['amount'], true),
                                'date' => $this->format_date($value['paid_on'])
                                ];
                        } elseif ($value['method'] == 'custom_pay_3') {
                            $output['payments'][] =
                                ['method' => trans("lang_v1.custom_payment_3") . (!empty($value['transaction_no']) ? (', ' . trans("lang_v1.transaction_no") . ':' . $value['transaction_no']) : ''),
                                'amount' => $this->num_f($value['amount'], true),
                                'date' => $this->format_date($value['paid_on'])
                                ];
                        }
                    }
                }
            }
        }

        //Check for barcode
        $output['barcode'] = ($il->show_barcode == 1) ? $transaction->invoice_no : false;

        //Additional notes
        $output['additional_notes'] = $transaction->additional_notes;
        $output['footer_text'] = $invoice_layout->footer_text;
        
        //Barcode related information.
        $output['show_barcode'] = !empty($il->show_barcode) ? true : false;

        //Module related information.
        $il->module_info = !empty($il->module_info) ? json_decode($il->module_info, true) : [];
        if (!empty($il->module_info['tables']) && $this->isModuleEnabled('tables')) {
            //Table label & info
            $output['table_label'] = null;
            $output['table'] = null;
            if (isset($il->module_info['tables']['show_table'])) {
                $output['table_label'] = !empty($il->module_info['tables']['table_label']) ? $il->module_info['tables']['table_label'] : '';
                if (!empty($transaction->res_table_id)) {
                    $table = ResTable::find($transaction->res_table_id);
                }
                
                //res_table_id
                $output['table'] = !empty($table->name) ? $table->name : '';
            }
        }

        if (!empty($il->module_info['service_staff']) && $this->isModuleEnabled('service_staff')) {
            //Waiter label & info
            $output['service_staff_label'] = null;
            $output['service_staff'] = null;
            if (isset($il->module_info['service_staff']['show_service_staff'])) {
                $output['service_staff_label'] = !empty($il->module_info['service_staff']['service_staff_label']) ? $il->module_info['service_staff']['service_staff_label'] : '';
                if (!empty($transaction->res_waiter_id)) {
                    $waiter = \App\User::find($transaction->res_waiter_id);
                }
                
                //res_table_id
                $output['service_staff'] = !empty($waiter->id) ? implode(' ', [$waiter->first_name, $waiter->last_name]) : '';
            }
        }

        $output['design'] = $il->design;

        return (object)$output;
    }

    /**
     * Returns each line details for sell invoice display
     *
     * @return array
     */
    protected function _receiptDetailsSellLines($lines, $il, $is_product_expiry_enabled, $is_lot_number_enabled)
    {
        $output_lines = [];
        $output_taxes = ['taxes' => []];
        foreach ($lines as $line) {
            //Group product taxes by name.
            $tax_details = TaxRate::find($line->tax_id);
            if (!empty($tax_details)) {
                if ($tax_details->is_tax_group) {
                    $group_tax_details = $this->groupTaxDetails($tax_details, $line->quantity * $line->item_tax);
                    foreach ($group_tax_details as $key => $value) {
                        if (!isset($output_taxes['taxes'][$key])) {
                            $output_taxes['taxes'][$key] = 0;
                        }
                        $output_taxes['taxes'][$key] += $value;
                    }
                } else {
                    $tax_name = $tax_details->name;
                    if (!isset($output_taxes['taxes'][$tax_name])) {
                        $output_taxes['taxes'][$tax_name] = 0;
                    }
                    $output_taxes['taxes'][$tax_name] += ($line->quantity * $line->item_tax);
                }
            }

            $product = $line->product;
            $variation = $line->variations;
            $unit = $line->product->unit;
            $brand = $line->product->brand;
            $cat = $line->product->category;

            $line_array = [
                //Field for 1st column
                'name' => $product->name,
                'variation' => (empty($variation->name) || $variation->name == 'DUMMY') ? '' : $variation->name,
                //Field for 2nd column
                'quantity' => $line->quantity,
                'units' => !empty($unit->short_name) ? $unit->short_name : '',

                'unit_price' => $this->num_f($line->unit_price),
                'tax' => $this->num_f($line->item_tax),
                'tax_name' => !empty($tax_details) ? '(' . $tax_details->name . ')' : null,

                //Field for 3rd column
                'unit_price_inc_tax' => $this->num_f($line->unit_price_inc_tax),
                'unit_price_exc_tax' => $this->num_f($line->unit_price),

                //Fields for 4th column
                'line_total' => $this->num_f($line->unit_price_inc_tax * $line->quantity),
            ];
            $line_array['line_discount'] = method_exists($line, 'get_discount_amount') ? $this->num_f($line->get_discount_amount()) : 0;
            if ($line->line_discount_type == 'percentage') {
                $line_array['line_discount'] .= ' (' . $this->num_f($line->line_discount_amount) . '%)';
            }

            if ($il->show_brand == 1) {
                $line_array['brand'] = !empty($brand->name) ? $brand->name : '';
            }
            if ($il->show_sku == 1) {
                $line_array['sub_sku'] = !empty($variation->sub_sku) ? $variation->sub_sku : '' ;
            }
            if ($il->show_cat_code == 1) {
                $line_array['cat_code'] = !empty($cat->short_code) ? $cat->short_code : '';
            }
            if ($il->show_sale_description == 1) {
                $line_array['sell_line_note'] = !empty($line->sell_line_note) ? $line->sell_line_note : '';
            }
            if ($is_lot_number_enabled == 1 && $il->show_lot == 1) {
                $line_array['lot_number'] = !empty($line->lot_details->lot_number) ? $line->lot_details->lot_number : null;
                $line_array['lot_number_label'] = __('lang_v1.lot');
            }

            if ($is_product_expiry_enabled == 1 && $il->show_expiry == 1) {
                $line_array['product_expiry'] = !empty($line->lot_details->exp_date) ? $this->format_date($line->lot_details->exp_date) : null;
                $line_array['product_expiry_label'] = __('lang_v1.expiry');
            }

            //If modifier is set set modifiers line to parent sell line
            if (!empty($line->modifiers)) {
                foreach ($line->modifiers as $modifier_line) {
                    $product = $modifier_line->product;
                    $variation = $modifier_line->variations;
                    $unit = $modifier_line->product->unit;
                    $brand = $modifier_line->product->brand;
                    $cat = $modifier_line->product->category;

                    $modifier_line_array = [
                        //Field for 1st column
                        'name' => $product->name,
                        'variation' => (empty($variation->name) || $variation->name == 'DUMMY') ? '' : $variation->name,
                        //Field for 2nd column
                        'quantity' => $modifier_line->quantity,
                        'units' => !empty($unit->short_name) ? $unit->short_name : '',

                        //Field for 3rd column
                        'unit_price_inc_tax' => $this->num_f($modifier_line->unit_price_inc_tax),
                        'unit_price_exc_tax' => $this->num_f($modifier_line->unit_price),

                        //Fields for 4th column
                        'line_total' => $this->num_f($modifier_line->unit_price_inc_tax * $line->quantity),
                    ];
                    
                    if ($il->show_sku == 1) {
                        $modifier_line_array['sub_sku'] = !empty($variation->sub_sku) ? $variation->sub_sku : '' ;
                    }
                    if ($il->show_cat_code == 1) {
                        $modifier_line_array['cat_code'] = !empty($cat->short_code) ? $cat->short_code : '';
                    }
                    if ($il->show_sale_description == 1) {
                        $modifier_line_array['sell_line_note'] = !empty($line->sell_line_note) ? $line->sell_line_note : '';
                    }

                    $line_array['modifiers'][] = $modifier_line_array;
                }
            }

            $output_lines[] = $line_array;
        }

        return ['lines' => $output_lines, 'taxes' => $output_taxes];
    }

    /**
     * Returns each line details for sell return invoice display
     *
     * @return array
     */
    protected function _receiptDetailsSellReturnLines($lines, $il, $is_product_expiry_enabled, $is_lot_number_enabled)
    {
        $output_lines = [];
        $output_taxes = ['taxes' => []];
        foreach ($lines as $line) {
            //Group product taxes by name.
            $tax_details = TaxRate::find($line->tax_id);
            if (!empty($tax_details)) {
                if ($tax_details->is_tax_group) {
                    $group_tax_details = $this->groupTaxDetails($tax_details, $line->quantity * $line->item_tax);
                    foreach ($group_tax_details as $key => $value) {
                        if (!isset($output_taxes['taxes'][$key])) {
                            $output_taxes['taxes'][$key] = 0;
                        }
                        $output_taxes['taxes'][$key] += $value;
                    }
                } else {
                    $tax_name = $tax_details->name;
                    if (!isset($output_taxes['taxes'][$tax_name])) {
                        $output_taxes['taxes'][$tax_name] = 0;
                    }
                    $output_taxes['taxes'][$tax_name] += ($line->quantity * $line->item_tax);
                }
            }

            $product = $line->product;
            $variation = $line->variations;
            $unit = $line->product->unit;
            $brand = $line->product->brand;
            $cat = $line->product->category;

            $line_array = [
                //Field for 1st column
                'name' => $product->name,
                'variation' => (empty($variation->name) || $variation->name == 'DUMMY') ? '' : $variation->name,
                //Field for 2nd column
                'quantity' => $line->quantity,
                'units' => !empty($unit->short_name) ? $unit->short_name : '',

                'unit_price' => $this->num_f($line->purchase_price),
                'tax' => $this->num_f($line->item_tax),
                'tax_name' => !empty($tax_details) ? '(' . $tax_details->name . ')' : null,

                //Field for 3rd column
                'unit_price_inc_tax' => $this->num_f($line->purchase_price_inc_tax),
                'unit_price_exc_tax' => $this->num_f($line->purchase_price),

                //Fields for 4th column
                'line_total' => $this->num_f($line->purchase_price_inc_tax * $line->quantity),
            ];
            $line_array['line_discount'] = 0;

            if ($il->show_brand == 1) {
                $line_array['brand'] = !empty($brand->name) ? $brand->name : '';
            }
            if ($il->show_sku == 1) {
                $line_array['sub_sku'] = !empty($variation->sub_sku) ? $variation->sub_sku : '' ;
            }
            if ($il->show_cat_code == 1) {
                $line_array['cat_code'] = !empty($cat->short_code) ? $cat->short_code : '';
            }
            if ($il->show_sale_description == 1) {
                $line_array['sell_line_note'] = !empty($line->sell_line_note) ? $line->sell_line_note : '';
            }
            if ($is_lot_number_enabled == 1 && $il->show_lot == 1) {
                $line_array['lot_number'] = !empty($line->lot_details->lot_number) ? $line->lot_details->lot_number : null;
                $line_array['lot_number_label'] = __('lang_v1.lot');
            }

            if ($is_product_expiry_enabled == 1 && $il->show_expiry == 1) {
                $line_array['product_expiry'] = !empty($line->lot_details->exp_date) ? $this->format_date($line->lot_details->exp_date) : null;
                $line_array['product_expiry_label'] = __('lang_v1.expiry');
            }

            $output_lines[] = $line_array;
        }

        return ['lines' => $output_lines, 'taxes' => $output_taxes];
    }

    /**
     * Gives the invoice number for a Final/Draft invoice
     *
     * @param int $business_id
     * @param string $status
     * @param string $location_id
     *
     * @return string
     */
    public function getInvoiceNumber($business_id, $status, $location_id)
    {
        if ($status == 'final') {
            $scheme = $this->getInvoiceScheme($business_id, $location_id);
            
            if ($scheme->scheme_type == 'blank') {
                $prefix = $scheme->prefix;
            } else {
                $prefix = date('Y') . '-';
            }

            //Count
            $count = $scheme->start_number + $scheme->invoice_count;
            $count = str_pad($count, $scheme->total_digits, '0', STR_PAD_LEFT);

            //Prefix + count
            $invoice_no = $prefix . $count;

            //Increment the invoice count
            $scheme->invoice_count = $scheme->invoice_count + 1;
            $scheme->save();

            return $invoice_no;
        } else {
            return str_random(5);
        }
    }

    private function getInvoiceScheme($business_id, $location_id)
    {
        $scheme_id = BusinessLocation::where('business_id', $business_id)
                    ->where('id', $location_id)
                    ->first()
                    ->invoice_scheme_id;
        if (!empty($scheme_id) && $scheme_id != 0) {
            $scheme = InvoiceScheme::find($scheme_id);
        }

        //Check if scheme is not found then return default scheme
        if (empty($scheme)) {
            $scheme = InvoiceScheme::where('business_id', $business_id)
                    ->where('is_default', 1)
                    ->first();
        }

        return $scheme;
    }

    /**
     * Gives the list of products for a purchase transaction
     *
     * @param int $business_id
     * @param int $transaction_id
     *
     * @return array
     */
    public function getPurchaseProducts($business_id, $transaction_id)
    {
        $products = Transaction::join('purchase_lines as pl', 'transactions.id', '=', 'pl.transaction_id')
                            ->leftjoin('products as p', 'pl.product_id', '=', 'p.id')
                            ->leftjoin('variations as v', 'pl.variation_id', '=', 'v.id')
                            ->where('transactions.business_id', $business_id)
                            ->where('transactions.id', $transaction_id)
                            ->where('transactions.type', 'purchase')
                            ->select('p.id as product_id', 'p.name as product_name', 'v.id as variation_id', 'v.name as variation_name', 'pl.quantity as quantity')
                            ->get();
        return $products;
    }

    /**
     * Gives the total purchase amount for a business within the date range passed
     *
     * @param int $business_id
     * @param int $transaction_id
     *
     * @return array
     */
    public function getPurchaseTotals($business_id, $start_date = null, $end_date = null, $location_id = null)
    {
        $query = Transaction::where('business_id', $business_id)
                        ->where('type', 'purchase')
                        ->select(
                            'final_total',
                            DB::raw("(final_total - tax_amount) as total_exc_tax"),
                            DB::raw("SUM((SELECT SUM(tp.amount) FROM transaction_payments as tp WHERE tp.transaction_id=id)) as total_paid"),
                            DB::raw('SUM(total_before_tax) as total_before_tax')
                        )
                        ->groupBy('transactions.id');

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (!empty($start_date) && !empty($end_date)) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        //Filter by the location
        if (!empty($location_id)) {
            $query->where('transactions.location_id', $location_id);
        }

        $purchase_details = $query->get();

        $output['total_purchase_inc_tax'] = $purchase_details->sum('final_total');
        //$output['total_purchase_exc_tax'] = $purchase_details->sum('total_exc_tax');
        $output['total_purchase_exc_tax'] = $purchase_details->sum('total_before_tax');
        $output['purchase_due'] = $purchase_details->sum('final_total') -
                                    $purchase_details->sum('total_paid');

        return $output;
    }

    /**
     * Gives the total sell amount for a business within the date range passed
     *
     * @param int $business_id
     * @param int $transaction_id
     *
     * @return array
     */
    public function getSellTotals($business_id, $start_date = null, $end_date = null, $location_id = null, $created_by = null)
    {
        $query = Transaction::where('transactions.business_id', $business_id)
                    ->where('transactions.type', 'sell')
                    ->where('transactions.status', 'final')
                    ->leftJoin(\DB::raw('(SELECT * FROM delivery_in_carts GROUP BY delivery_in_carts.uid) AS D'), function($join) {
                        $join->on('transactions.delivery_uid', '=', 'D.uid');
                    })
                    ->select(
                        'transactions.id',
                        'transactions.final_total',
                        DB::raw("(transactions.final_total - transactions.tax_amount) as total_exc_tax"),
                        DB::raw('(SELECT SUM(IF(tp.is_return = 1, -1*tp.amount, tp.amount)) FROM transaction_payments as tp WHERE tp.transaction_id = transactions.id) as total_paid'),
                        DB::raw('SUM(total_before_tax) as total_before_tax'),
                        DB::raw('SUM(D.delivery_price) as delivery_price'),
                        DB::raw('SUM(D.delivery_tax) as delivery_tax')
                    )
                    ->groupBy('transactions.id');

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (!empty($start_date) && !empty($end_date)) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        //Filter by the location
        if (!empty($location_id)) {
            $query->where('transactions.location_id', $location_id);
        }

        if (!empty($created_by)) {
            $query->where('transactions.created_by', $created_by);
        }

        $sell_details = $query->get();

        $points = RewardedPoint::where('business_id', $business_id)
                    ->where('purchased', 1)
                    ->whereDate('created_at', '>=', $start_date)
                    ->whereDate('created_at', '<=', $end_date);
        if( !empty($location_id) )
            $points = $points->where('location_id', $location_id);
        
        $points = $points->sum('points');
        $output['total_bullet'] = $points;
        $output['total_sell_inc_tax'] = $sell_details->sum('final_total');
        //$output['total_sell_exc_tax'] = $sell_details->sum('total_exc_tax');
        $output['total_sell_exc_tax'] = $sell_details->sum('total_before_tax');
        $output['invoice_due'] = $sell_details->sum('final_total') - $sell_details->sum('total_paid');

        ////////////////delivery add/////////////////
        $delivery_price = $sell_details->sum('delivery_price');
        $delivery_tax = $sell_details->sum('delivery_tax');

        // $output['total_sell_inc_tax'] += $delivery_price + $delivery_tax;
        // $output['total_sell_exc_tax'] += $delivery_price;
        $output['total_delivery'] = $delivery_price;// + $delivery_tax;

        return $output;
    }

    /**
     * Gives the total input tax for a business within the date range passed
     *
     * @param int $business_id
     * @param string $start_date default null
     * @param string $end_date default null
     *
     * @return float
     */
    public function getInputTax($business_id, $start_date = null, $end_date = null, $location_id = null)
    {
        $query1 = Transaction::where('transactions.business_id', $business_id)
                        ->leftjoin('tax_rates as T', 'transactions.tax_id', '=', 'T.id')
                        ->where('type', 'purchase')
                        ->whereNotNull('transactions.tax_id')
                        ->select(
                            DB::raw("SUM( transactions.tax_amount ) as transaction_tax"),
                            'T.name as tax_name',
                            'T.id as tax_id',
                            'T.is_tax_group'
                        );

        $query2 = Transaction::where('transactions.business_id', $business_id)
                        ->leftjoin('purchase_lines as pl', 'transactions.id', '=', 'pl.transaction_id')
                        ->leftjoin('tax_rates as T', 'pl.tax_id', '=', 'T.id')
                        ->where('type', 'purchase')
                        ->whereNotNull('pl.tax_id')
                        ->select(
                            DB::raw("SUM( pl.quantity * pl.item_tax ) as product_tax"),
                            'T.name as tax_name',
                            'T.id as tax_id',
                            'T.is_tax_group'
                        );

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query1->whereIn('transactions.location_id', $permitted_locations);
            $query2->whereIn('transactions.location_id', $permitted_locations);
        }

        if (!empty($start_date) && !empty($end_date)) {
            $query1->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
            $query2->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        if (!empty($location_id)) {
            $query1->where('transactions.location_id', $location_id);
            $query2->where('transactions.location_id', $location_id);
        }

        $transaction_tax_details = $query1->groupBy('T.id')
                                    ->get();

        $product_tax_details = $query2->groupBy('T.id')
                                    ->get();
        $tax_details = [];
        foreach ($transaction_tax_details as $transaction_tax) {
            $tax_details[$transaction_tax->tax_id]['tax_name'] = $transaction_tax->tax_name;
            $tax_details[$transaction_tax->tax_id]['tax_amount'] = $transaction_tax->transaction_tax;

            $tax_details[$transaction_tax->tax_id]['is_tax_group'] = false;
            if ($transaction_tax->is_tax_group == 1) {
                $tax_details[$transaction_tax->tax_id]['is_tax_group'] = true;
            }
        }

        foreach ($product_tax_details as $product_tax) {
            if (!isset($tax_details[$product_tax->tax_id])) {
                $tax_details[$product_tax->tax_id]['tax_name'] = $product_tax->tax_name;
                $tax_details[$product_tax->tax_id]['tax_amount'] = $product_tax->product_tax;

                $tax_details[$product_tax->tax_id]['is_tax_group'] = false;
                if ($product_tax->is_tax_group == 1) {
                    $tax_details[$product_tax->tax_id]['is_tax_group'] = true;
                }
            } else {
                $tax_details[$product_tax->tax_id]['tax_amount'] += $product_tax->product_tax;
            }
        }

        //If group tax add group tax details
        foreach ($tax_details as $key => $value) {
            if ($value['is_tax_group']) {
                $tax_details[$key]['group_tax_details'] = $this->groupTaxDetails($key, $value['tax_amount']);
            }
        }

        $output['tax_details'] = $tax_details;
        $output['total_tax'] = $transaction_tax_details->sum('transaction_tax') + $product_tax_details->sum('product_tax');

        return $output;
    }

    /**
     * Gives the total output tax for a business within the date range passed
     *
     * @param int $business_id
     * @param string $start_date default null
     * @param string $end_date default null
     *
     * @return float
     */
    public static function getOutputTax($business_id, $start_date = null, $end_date = null, $location_id = null)
    {
        $query1 = Transaction::where('transactions.business_id', $business_id)
                        ->leftjoin('tax_rates as T', 'transactions.tax_id', '=', 'T.id')
                        ->leftJoin(\DB::raw('(SELECT * FROM delivery_in_carts GROUP BY delivery_in_carts.uid) AS D'), function($join) {
                            $join->on('transactions.delivery_uid', '=', 'D.uid');
                        })
                        ->where('type', 'sell')
                        ->whereNotNull('transactions.tax_id')
                        ->where('transactions.status', '=', 'final')
                        ->select(
                            DB::raw("SUM(transactions.tax_amount + IF(transactions.delivery_uid IS NULL, 0, D.delivery_tax)) as transaction_tax"),
                            'T.name as tax_name',
                            'T.id as tax_id',
                            'T.is_tax_group'
                        );

        $query2 = Transaction::where('transactions.business_id', $business_id)
                        ->leftjoin('transaction_sell_lines as tsl', 'transactions.id', '=', 'tsl.transaction_id')
                        ->leftjoin('tax_rates as T', 'tsl.tax_id', '=', 'T.id')
                        ->where('type', 'sell')
                        ->whereNotNull('tsl.tax_id')
                        ->where('transactions.status', '=', 'final')
                        ->select(
                            DB::raw("SUM( tsl.quantity * tsl.item_tax ) as product_tax"),
                            'T.name as tax_name',
                            'T.id as tax_id',
                            'T.is_tax_group'
                        );

        ///Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query1->whereIn('transactions.location_id', $permitted_locations);
            $query2->whereIn('transactions.location_id', $permitted_locations);
        }

        if (!empty($start_date) && !empty($end_date)) {
            $query1->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
            $query2->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        if (!empty($location_id)) {
            $query1->where('transactions.location_id', $location_id);
            $query2->where('transactions.location_id', $location_id);
        }

        $transaction_tax_details = $query1->groupBy('T.id')
                                    ->get();

        $product_tax_details = $query2->groupBy('T.id')
                                    ->get();
        $tax_details = [];
        foreach ($transaction_tax_details as $transaction_tax) {
            $tax_details[$transaction_tax->tax_id]['tax_name'] = $transaction_tax->tax_name;
            $tax_details[$transaction_tax->tax_id]['tax_amount'] = $transaction_tax->transaction_tax;

            $tax_details[$transaction_tax->tax_id]['is_tax_group'] = false;
            if ($transaction_tax->is_tax_group == 1) {
                $tax_details[$transaction_tax->tax_id]['is_tax_group'] = true;
            }
        }

        foreach ($product_tax_details as $product_tax) {
            if (!isset($tax_details[$product_tax->tax_id])) {
                $tax_details[$product_tax->tax_id]['tax_name'] = $product_tax->tax_name;
                $tax_details[$product_tax->tax_id]['tax_amount'] = $product_tax->product_tax;

                $tax_details[$product_tax->tax_id]['is_tax_group'] = false;
                if ($product_tax->is_tax_group == 1) {
                    $tax_details[$product_tax->tax_id]['is_tax_group'] = true;
                }
            } else {
                $tax_details[$product_tax->tax_id]['tax_amount'] += $product_tax->product_tax;
            }
        }

        //If group tax add group tax details
        foreach ($tax_details as $key => $value) {
            if ($value['is_tax_group']) {
                $tax_details[$key]['group_tax_details'] = $this->groupTaxDetails($key, $value['tax_amount']);
            }
        }

        $output['tax_details'] = $tax_details;
        $output['total_tax'] = $transaction_tax_details->sum('transaction_tax') + $product_tax_details->sum('product_tax');

        return $output;
    }

     /**
     * Gives total sells of last 30 days day-wise
     *
     * @param int $business_id
     * @param array $filters
     *
     * @return Obj
     */
    public function getSellsLast30Days($business_id)
    {
        $query = Transaction::where('business_id', $business_id)
                            ->where('type', 'sell')
                            ->where('status', 'final')
                            ->whereBetween(DB::raw('date(transaction_date)'), [\Carbon::now()->subDays(30), \Carbon::now()]);

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        $sells = $query->select(
            DB::raw("DATE_FORMAT(transaction_date, '%Y-%m-%d') as date"),
            DB::raw("SUM( final_total ) as total_sells")
        )
                        ->groupBy(DB::raw('Date(transaction_date)'))
                        ->get()->pluck('total_sells', 'date');
        return $sells;
    }

     /**
     * Gives total sells of current FY month-wise
     *
     * @param int $business_id
     * @param string $start
     * @param string $end
     *
     * @return Obj
     */
    public function getSellsCurrentFy($business_id, $start, $end)
    {
        $query = Transaction::where('business_id', $business_id)
                            ->where('type', 'sell')
                            ->where('status', 'final')
                            ->whereBetween(DB::raw('date(transaction_date)'), [$start, $end]);

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }
        
        $sells = $query->select(
            DB::raw("DATE_FORMAT(transaction_date, '%m') as month"),
            DB::raw("SUM( final_total ) as total_sells")
        )
                        ->groupBy(DB::raw('Date(month)'))
                        ->get()->pluck('total_sells', 'month');
        return $sells;
    }

    /**
     * Retrives expense report
     *
     * @param int $business_id
     * @param array $filters
     * @param string $type = by_category (by_category or total)
     *
     * @return Obj
     */
    public function getExpenseReport(
        $business_id,
        $filters = [],
        $type = 'by_category'
    ) {
    
        $query = Transaction::leftjoin('expense_categories AS ec', 'transactions.expense_category_id', '=', 'ec.id')
                            ->where('transactions.business_id', $business_id)
                            ->where('type', 'expense')
                            ->where('payment_status', 'paid');

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (!empty($filters['location_id'])) {
            $query->where('transactions.location_id', $filters['location_id']);
        }

        if (!empty($filters['expense_for'])) {
            $query->where('transactions.expense_for', $filters['expense_for']);
        }

        if (!empty($filters['category'])) {
            $query->where('ec.id', $filters['category']);
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$filters['start_date'],
                $filters['end_date']]);
        }

        //Check tht type of report and return data accordingly
        if ($type == 'by_category') {
            $expenses = $query->select(
                DB::raw("SUM( final_total ) as total_expense"),
                'ec.name as category'
            )
                        ->groupBy('expense_category_id')
                        ->get();
        } elseif ($type == 'total') {
            $expenses = $query->select(
                DB::raw("SUM( final_total ) as total_expense")
            )
                        ->first();
        }
        
        return $expenses;
    }

    /**
     * Get total paid amount for a transaction
     *
     * @param int $transaction_id
     *
     * @return int
     */
    public function getTotalPaid($transaction_id)
    {
        $total_paid = TransactionPayment::where('transaction_id', $transaction_id)
                ->select(DB::raw('SUM(IF( is_return = 0, amount, amount*-1))as total_paid'))
                ->first()
                ->total_paid;

        return $total_paid;
    }

    /**
     * Calculates the payment status and returns back.
     *
     * @param int $transaction_id
     * @param float $final_amount = null
     *
     * @return string
     */
    public function calculatePaymentStatus($transaction_id, $final_amount = null)
    {
        $total_paid = $this->getTotalPaid($transaction_id);

        if (is_null($final_amount)) {
            $final_amount = Transaction::find($transaction_id)->final_total;
        }
        $points = Transaction::find($transaction_id)->points;

        $status = 'due';
        if ($total_paid == 0) {
            $status = 'due';
            if ($points > 0)
                $status = 'paid';
        } elseif ($final_amount > $total_paid) {
            $status = 'partial';
        } else {
            $status = 'paid';
        }

        return $status;
    }

    /**
     * Update the payment status for purchase or sell transactions. Returns
     * the status
     *
     * @param int $transaction_id
     *
     * @return string
     */
    public function updatePaymentStatus($transaction_id, $final_amount = null)
    {
        $status = $this->calculatePaymentStatus($transaction_id, $final_amount);
        Transaction::where('id', $transaction_id)
            ->update(['payment_status' => $status]);

        return $status;
    }

    /**
     * Purchase currency details
     *
     * @param int $business_id
     *
     * @return object
     */
    public function purchaseCurrencyDetails($business_id)
    {
        $business = Business::find($business_id);
        $output = ['purchase_in_diff_currency' => false,
                    'p_exchange_rate' => 1,
                    'decimal_seperator' => '.',
                    'thousand_seperator' => ',',
                    'symbol' => '',
                ];

        //Check if diff currency is used or not.
        if ($business->purchase_in_diff_currency == 1) {
            $output['purchase_in_diff_currency'] = true;
            $output['p_exchange_rate'] = $business->p_exchange_rate;

            $currency_id = $business->purchase_currency_id;
        } else {
            $output['purchase_in_diff_currency'] = false;
            $output['p_exchange_rate'] = 1;
            $currency_id = $business->currency_id;
        }

        $currency = Currency::find($currency_id);
        $output['thousand_separator'] = $currency->thousand_separator;
        $output['decimal_separator'] = $currency->decimal_separator;
        $output['symbol'] = $currency->symbol;
        $output['code'] = $currency->code;
        $output['name'] = $currency->currency;

        return (object)$output;
    }

    /**
     * Pay contact due at once
     *
     * @param obj $parent_payment, string $type
     *
     * @return void
     */
    public function payAtOnce($parent_payment, $type)
    {

        //Get all unpaid transaction for the contact
        $types = ['opening_balance', $type];
        $due_transactions = Transaction::where('contact_id', $parent_payment->payment_for)
                                ->whereIn('type', $types)
                                ->where('payment_status', '!=', 'paid')
                                ->orderBy('transaction_date', 'asc')
                                ->get();
        $total_amount = $parent_payment->amount;

        $tranaction_payments = [];
        if ($due_transactions->count()) {
            foreach ($due_transactions as $transaction) {
                if ($total_amount > 0) {
                    $total_paid = $this->getTotalPaid($transaction->id);
                    $due = $transaction->final_total - $total_paid;

                    $now = \Carbon::now()->toDateTimeString();

                    $array = [
                            'transaction_id' => $transaction->id,
                            'method' => $parent_payment->method,
                            'transaction_no' => $parent_payment->method,
                            'card_transaction_number' => $parent_payment->card_transaction_number,
                            'card_number' => $parent_payment->card_number,
                            'card_type' => $parent_payment->card_type,
                            'card_holder_name' => $parent_payment->card_holder_name,
                            'card_month' => $parent_payment->card_month,
                            'card_year' => $parent_payment->card_year,
                            'card_security' => $parent_payment->card_security,
                            'cheque_number' => $parent_payment->cheque_number,
                            'bank_account_number' => $parent_payment->bank_account_number,
                            'paid_on' => $parent_payment->paid_on,
                            'created_by' => $parent_payment->created_by,
                            'payment_for' => $parent_payment->payment_for,
                            'parent_id' => $parent_payment->id,
                            'created_at' => $now,
                            'updated_at' => $now
                        ];

                    $prefix_type = 'sell_payment';
                    if ($transaction->type == 'purchase') {
                        $prefix_type = 'purchase_payment';
                    }
                    $ref_count = $this->setAndGetReferenceCount($prefix_type);
                    //Generate reference number
                    $payment_ref_no = $this->generateReferenceNumber($prefix_type, $ref_count);
                    $array['payment_ref_no'] = $payment_ref_no;

                    if ($due <= $total_amount) {
                        $array['amount'] = $due;
                        $tranaction_payments[] = $array;

                        //Update transaction status to paid
                        $transaction->payment_status = 'paid';
                        $transaction->save();

                        $total_amount = $total_amount - $due;
                    } else {
                        $array['amount'] = $total_amount;
                        $tranaction_payments[] = $array;

                        //Update transaction status to partial
                        $transaction->payment_status = 'partial';
                        $transaction->save();
                        break;
                    }
                }
            }

            //Insert new transaction payments
            if (!empty($tranaction_payments)) {
                TransactionPayment::insert($tranaction_payments);
            }
        }
    }

    /**
     * Add a mapping between purchase & sell lines.
     * NOTE: Don't use request variable here, request variable don't exist while adding
     * dummybusiness via command line
     *
     * @param array $business
     * @param array $transaction_lines
     * @param string $mapping_type = purchase (purchase or stock_adjustment)
     * @param boolean $check_expiry = true
     * @param int $purchase_line_id (default: null)
     *
     * @return object
     */
    public function mapPurchaseSell($business, $transaction_lines, $mapping_type = 'purchase', $check_expiry = true, $purchase_line_id = null)
    {
        if (empty($transaction_lines)) {
            return false;
        }

        //Set flag to check for expired items during SELLING only.
        $stop_selling_expired = false;
        if ($check_expiry) {
            if (request()->session()->get('business')['enable_product_expiry'] == 1 && request()->session()->get('business')['on_product_expiry'] == 'stop_selling') {
                if ($mapping_type == 'purchase') {
                    $stop_selling_expired = true;
                }
            }
        }

        $qty_selling = null;
        foreach ($transaction_lines as $line) {
            //Check if stock is not enabled then no need to assign purchase & sell
            $product = Product::find($line->product_id);
            if ($product->enable_stock != 1) {
                continue;
            }

            //Get purchase lines, only for products with enable stock.
            $query = Transaction::join('purchase_lines AS PL', 'transactions.id', '=', 'PL.transaction_id')
                ->where('transactions.business_id', $business['id'])
                ->where('transactions.location_id', $business['location_id'])
                ->whereIn('transactions.type', ['purchase', 'purchase_transfer',
                    'opening_stock', 'sell_return'])
                ->where('transactions.status', 'received')
                ->whereRaw('(PL.quantity_sold + PL.quantity_adjusted) < PL.quantity')
                ->where('PL.product_id', $line->product_id)
                ->where('PL.variation_id', $line->variation_id);

            //If product expiry is enabled then check for on expiry conditions
            if ($stop_selling_expired && empty($purchase_line_id)) {
                $stop_before = request()->session()->get('business')['stop_selling_before'];
                $expiry_date = \Carbon::today()->addDays($stop_before)->toDateString();
                $query->whereRaw('PL.exp_date IS NULL OR PL.exp_date > ?', [$expiry_date]);
            }

            //If lot number present consider only lot number purchase line
            if (!empty($line->lot_no_line_id)) {
                $query->where('PL.id', $line->lot_no_line_id);
            }

            //If purchase_line_id is given consider only that purchase line
            if (!empty($purchase_line_id)) {
                $query->where('PL.id', $purchase_line_id);
            }

            //Sort according to LIFO or FIFO
            if ($business['accounting_method'] == 'lifo') {
                $query = $query->orderBy('transaction_date', 'desc');
            } else {
                $query = $query->orderBy('transaction_date', 'asc');
            }

            $rows = $query->select(
                'PL.id as purchase_lines_id',
                DB::raw('(PL.quantity - (PL.quantity_sold + PL.quantity_adjusted)) AS quantity_available'),
                'PL.quantity_sold as quantity_sold',
                'PL.quantity_adjusted as quantity_adjusted',
                'transactions.invoice_no'
            )
                        ->get();

            $purchase_sell_map = [];

            //Iterate over the rows, assign the purchase line to sell lines.
            $qty_selling = $line->quantity;
            foreach ($rows as $k => $row) {
                $qty_allocated = 0;

                //Check if qty_available is more or equal
                if ($qty_selling <= $row->quantity_available) {
                    $qty_allocated = $qty_selling;
                    $qty_selling = 0;
                } else {
                    $qty_selling = $qty_selling - $row->quantity_available;
                    $qty_allocated = $row->quantity_available;
                }

                //Check for sell mapping or stock adjsutment mapping
                if ($mapping_type == 'stock_adjustment') {
                    //Mapping of stock adjustment
                    $purchase_adjustment_map[] =
                        ['stock_adjustment_line_id' => $line->id,
                            'purchase_line_id' => $row->purchase_lines_id,
                            'quantity' => $qty_allocated,
                            'created_at' => \Carbon::now(),
                            'updated_at' => \Carbon::now()
                        ];

                    //Update purchase line
                    PurchaseLine::where('id', $row->purchase_lines_id)
                        ->update(['quantity_adjusted' => $row->quantity_adjusted + $qty_allocated]);
                } elseif ($mapping_type == 'purchase') {
                    //Mapping of purchase
                    $purchase_sell_map[] = ['sell_line_id' => $line->id,
                            'purchase_line_id' => $row->purchase_lines_id,
                            'quantity' => $qty_allocated,
                            'created_at' => \Carbon::now(),
                            'updated_at' => \Carbon::now()
                        ];

                    //Update purchase line
                    PurchaseLine::where('id', $row->purchase_lines_id)
                        ->update(['quantity_sold' => $row->quantity_sold + $qty_allocated]);
                }

                if ($qty_selling == 0) {
                    break;
                }
            }

            if (! ($qty_selling == 0 || is_null($qty_selling))) {
                $variation = Variation::find($line->variation_id);
                $mismatch_name = $product->name;
                if (!empty($variation->sub_sku)) {
                    $mismatch_name .= ' ' . 'SKU: ' . $variation->sub_sku;
                }
                if (!empty($qty_selling)) {
                    $mismatch_name .= ' ' . 'Quantity: ' . abs($qty_selling);
                }
                
                if ($mapping_type == 'purchase') {
                    $mismatch_error = trans(
                        "messages.purchase_sell_mismatch_exception",
                        ['product' => $mismatch_name]
                    );

                    if ($stop_selling_expired) {
                        $mismatch_error .= ' OR available stock has expired.';
                    }
                } elseif ($mapping_type == 'stock_adjustment') {
                    $mismatch_error = trans(
                        "messages.purchase_stock_adjustment_mismatch_exception",
                        ['product' => $mismatch_name]
                    );
                }

                throw new PurchaseSellMismatch($mismatch_error);
            }

            //Insert the mapping
            if (!empty($purchase_adjustment_map)) {
                TransactionSellLinesPurchaseLines::insert($purchase_adjustment_map);
            }
            if (!empty($purchase_sell_map)) {
                TransactionSellLinesPurchaseLines::insert($purchase_sell_map);
            }
        }
    }

    /**
     * F => D (Delete all mapping lines, decrease the qty sold.)
     * D => F (Call the mapPurchaseSell function)
     * F => F (Check for quantity of existing product, call mapPurchase for new products.)
     *
     * @param  string $status_before
     * @param  object $transaction
     * @param  array $business
     * @param  array $deleted_line_ids = [] //deleted sell lines ids.
     *
     * @return void
     */
    public function adjustMappingPurchaseSell(
        $status_before,
        $transaction,
        $business,
        $deleted_line_ids = []
    ) {

        if ($status_before == 'final' && $transaction->status == 'draft') {
            //Get sell lines used for the transaction.
            $sell_purchases = Transaction::join('transaction_sell_lines AS SL', 'transactions.id', '=', 'SL.transaction_id')
                    ->join('transaction_sell_lines_purchase_lines as TSP', 'SL.id', '=', 'TSP.sell_line_id')
                    ->where('transactions.id', $transaction->id)
                    ->select('TSP.purchase_line_id', 'TSP.quantity', 'TSP.id')
                    ->get()
                    ->toArray();

            //Included the deleted sell lines
            if (!empty($deleted_line_ids)) {
                $deleted_sell_purchases = TransactionSellLinesPurchaseLines::whereIn('sell_line_id', $deleted_line_ids)
                            ->select('purchase_line_id', 'quantity', 'id')
                            ->get()
                            ->toArray();

                $sell_purchases = $sell_purchases + $deleted_sell_purchases;
            }

            //TODO: Optimize the query to take our of loop.
            $sell_purchase_ids = [];
            if (!empty($sell_purchases)) {
                //Decrease the quantity sold of products
                foreach ($sell_purchases as $row) {
                    PurchaseLine::where('id', $row['purchase_line_id'])
                        ->decrement('quantity_sold', $row['quantity']);

                    $sell_purchase_ids[] = $row['id'];
                }

                //Delete the lines.
                TransactionSellLinesPurchaseLines::whereIn('id', $sell_purchase_ids)
                    ->delete();
            }
        } elseif ($status_before == 'draft' && $transaction->status == 'final') {
            $this->mapPurchaseSell($business, $transaction->sell_lines, 'purchase');
        } elseif ($status_before == 'final' && $transaction->status == 'final') {
            //Handle deleted line
            if (!empty($deleted_line_ids)) {
                $deleted_sell_purchases = TransactionSellLinesPurchaseLines::whereIn('sell_line_id', $deleted_line_ids)
                            ->select('sell_line_id', 'quantity')
                            ->get();
                if (!empty($deleted_sell_purchases)) {
                    foreach ($deleted_sell_purchases as $value) {
                        $this->mapDecrementPurchaseQuantity($value->sell_line_id, $value->quantity);
                    }
                }
            }

            //Check for update quantity, new added rows, deleted rows.
            $sell_purchases = Transaction::join('transaction_sell_lines AS SL', 'transactions.id', '=', 'SL.transaction_id')
                    ->leftjoin('transaction_sell_lines_purchase_lines as TSP', 'SL.id', '=', 'TSP.sell_line_id')
                    ->where('transactions.id', $transaction->id)
                    ->select(
                        'TSP.purchase_line_id',
                        'TSP.quantity AS tsp_quantity',
                        'TSP.id as tsp_id',
                        'SL.*'
                    )
                    ->get();

            $deleted_sell_lines = [];
            $new_sell_lines = [];
            $processed_sell_lines = [];

            foreach ($sell_purchases as $line) {
                if (empty($line->purchase_line_id)) {
                    $new_sell_lines[] = $line;
                } else {
                    //Skip if already processed.
                    if (in_array($line->purchase_line_id, $processed_sell_lines)) {
                        continue;
                    }

                    $processed_sell_lines[] = $line->purchase_line_id;

                    $total_sold_entry = TransactionSellLinesPurchaseLines::where('sell_line_id', $line->id)
                        ->select(DB::raw('SUM(quantity) AS quantity'))
                        ->first();

                    if ($total_sold_entry->quantity != $line->quantity) {
                        if ($line->quantity > $total_sold_entry->quantity) {
                            //If quantity is increased add it to new sell lines by decreasing tsp_quantity
                            $line_temp = $line;
                            $line_temp->quantity = $line_temp->quantity - $total_sold_entry->quantity;
                            $new_sell_lines[] = $line_temp;
                        } elseif ($line->quantity < $total_sold_entry->quantity) {
                            $decrement_qty = $total_sold_entry->quantity - $line->quantity;

                            $this->mapDecrementPurchaseQuantity($line->id, $decrement_qty);
                        }
                    }
                }
            }

            //Add mapping for new sell lines and for incremented quantity
            if (!empty($new_sell_lines)) {
                $this->mapPurchaseSell($business, $new_sell_lines);
            }
        }
    }

    /**
     * Decrease the purchase quantity from
     * transaction_sell_lines_purchase_lines and purchase_lines.quantity_sold
     *
     * @param  int $sell_line_id
     * @param  int $decrement_qty
     *
     * @return void
     */
    private function mapDecrementPurchaseQuantity($sell_line_id, $decrement_qty)
    {

        $sell_purchase_line = TransactionSellLinesPurchaseLines::
                                where('sell_line_id', $sell_line_id)
                                ->orderBy('id', 'desc')
                                ->get();

        foreach ($sell_purchase_line as $row) {
            if ($row->quantity > $decrement_qty) {
                PurchaseLine::where('id', $row->purchase_line_id)
                    ->decrement('quantity_sold', $decrement_qty);

                $row->quantity = $row->quantity - $decrement_qty;
                $row->save();
                $decrement_qty = 0;
            } else {
                PurchaseLine::where('id', $row->purchase_line_id)
                    ->decrement('quantity_sold', $decrement_qty);
                $row->delete();
            }

            $decrement_qty = $decrement_qty - $row->quantity;
            if ($decrement_qty <= 0) {
                break;
            }
        }
    }

    /**
     * Decrement quantity adjusted in product line according to
     * transaction_sell_lines_purchase_lines
     * Used in delete of stock adjustment
     *
     * @param  array $line_ids
     *
     * @return boolean
     */
    public function mapPurchaseQuantityForDeleteStockAdjustment($line_ids)
    {

        if (empty($line_ids)) {
            return true;
        }

        $map_line = TransactionSellLinesPurchaseLines::whereIn('stock_adjustment_line_id', $line_ids)
                            ->orderBy('id', 'desc')
                            ->get();

        foreach ($map_line as $row) {
            PurchaseLine::where('id', $row->purchase_line_id)
                ->decrement('quantity_adjusted', $row->quantity);
        }

        //Delete the tslp line.
        TransactionSellLinesPurchaseLines::whereIn('stock_adjustment_line_id', $line_ids)
            ->delete();

        return true;
    }

    /**
     * Adjust the existing mapping between purchase & sell on edit of
     * purchase
     *
     * @param  string $before_status
     * @param  object $transaction
     * @param  object $delete_purchase_lines
     *
     * @return void
     */
    public function adjustMappingPurchaseSellAfterEditingPurchase($before_status, $transaction, $delete_purchase_lines)
    {

        if ($before_status == 'received' && $transaction->status == 'received') {
            //Check if there is some irregularities between purchase & sell and make appropiate adjustment.

            //Get all purchase line having irregularities.
            $purchase_lines = Transaction::join(
                'purchase_lines AS PL',
                'transactions.id',
                '=',
                'PL.transaction_id'
            )
                    ->join(
                        'transaction_sell_lines_purchase_lines AS TSPL',
                        'PL.id',
                        '=',
                        'TSPL.purchase_line_id'
                    )
                    ->groupBy('TSPL.purchase_line_id')
                    ->where('transactions.id', $transaction->id)
                    ->havingRaw('SUM(TSPL.quantity) > MAX(PL.quantity)')
                    ->select(['TSPL.purchase_line_id AS id',
                            DB::raw('SUM(TSPL.quantity) AS tspl_quantity'),
                            DB::raw('MAX(PL.quantity) AS pl_quantity')
                        ])
                    ->get()
                    ->toArray();
        } elseif ($before_status == 'received' && $transaction->status != 'received') {
            //Delete sell for those & add new sell or throw error.
            $purchase_lines = Transaction::join(
                'purchase_lines AS PL',
                'transactions.id',
                '=',
                'PL.transaction_id'
            )
                    ->join(
                        'transaction_sell_lines_purchase_lines AS TSPL',
                        'PL.id',
                        '=',
                        'TSPL.purchase_line_id'
                    )
                    ->groupBy('TSPL.purchase_line_id')
                    ->where('transactions.id', $transaction->id)
                    ->select(['TSPL.purchase_line_id AS id',
                        DB::raw('MAX(PL.quantity) AS pl_quantity')
                    ])
                    ->get()
                    ->toArray();
        } else {
            return true;
        }

        //Get detail of purchase lines deleted
        if (!empty($delete_purchase_lines)) {
            $purchase_lines = $delete_purchase_lines->toArray() + $purchase_lines;
        }

        //All sell lines & Stock adjustment lines.
        $sell_lines = [];
        $stock_adjustment_lines = [];
        foreach ($purchase_lines as $purchase_line) {
            $tspl_quantity = isset($purchase_line['tspl_quantity']) ? $purchase_line['tspl_quantity'] : 0;
            $pl_quantity = isset($purchase_line['pl_quantity']) ? $purchase_line['pl_quantity'] : $purchase_line['quantity'];


            $extra_sold = abs($tspl_quantity - $pl_quantity);

            //Decrease the quantity from transaction_sell_lines_purchase_lines or delete it if zero
            $tspl = TransactionSellLinesPurchaseLines::where('purchase_line_id', $purchase_line['id'])
                ->leftjoin(
                    'transaction_sell_lines AS SL',
                    'transaction_sell_lines_purchase_lines.sell_line_id',
                    '=',
                    'SL.id'
                )
                ->leftjoin(
                    'stock_adjustment_lines AS SAL',
                    'transaction_sell_lines_purchase_lines.stock_adjustment_line_id',
                    '=',
                    'SAL.id'
                )
                ->orderBy('transaction_sell_lines_purchase_lines.id', 'desc')
                ->select(['SL.product_id AS sell_product_id',
                        'SL.variation_id AS sell_variation_id',
                        'SL.id AS sell_line_id',
                        'SAL.product_id AS adjust_product_id',
                        'SAL.variation_id AS adjust_variation_id',
                        'SAL.id AS adjust_line_id',
                        'transaction_sell_lines_purchase_lines.quantity',
                        'transaction_sell_lines_purchase_lines.purchase_line_id', 'transaction_sell_lines_purchase_lines.id as tslpl_id'])
                ->get();

            foreach ($tspl as $row) {
                if ($row->quantity <= $extra_sold) {
                    if (!empty($row->sell_line_id)) {
                        $sell_lines[] = (object)['id' => $row->sell_line_id,
                                'quantity' => $row->quantity,
                                'product_id' => $row->sell_product_id,
                                'variation_id' => $row->sell_variation_id,
                            ];
                        PurchaseLine::where('id', $row->purchase_line_id)
                            ->decrement('quantity_sold', $row->quantity);
                    } else {
                        $stock_adjustment_lines[] =
                            (object)['id' => $row->adjust_line_id,
                                'quantity' => $row->quantity,
                                'product_id' => $row->adjust_product_id,
                                'variation_id' => $row->adjust_variation_id,
                            ];
                        PurchaseLine::where('id', $row->purchase_line_id)
                            ->decrement('quantity_adjusted', $row->quantity);
                    }

                    $extra_sold = $extra_sold - $row->quantity;
                    TransactionSellLinesPurchaseLines::where('id', $row->tslpl_id)->delete();
                } else {
                    if (!empty($row->sell_line_id)) {
                        $sell_lines[] = (object)['id' => $row->sell_line_id,
                                'quantity' => $extra_sold,
                                'product_id' => $row->sell_product_id,
                                'variation_id' => $row->sell_variation_id,
                            ];
                        PurchaseLine::where('id', $row->purchase_line_id)
                            ->decrement('quantity_sold', $extra_sold);
                    } else {
                        $stock_adjustment_lines[] =
                            (object)['id' => $row->adjust_line_id,
                                'quantity' => $extra_sold,
                                'product_id' => $row->adjust_product_id,
                                'variation_id' => $row->adjust_variation_id,
                            ];

                        PurchaseLine::where('id', $row->purchase_line_id)
                            ->decrement('quantity_adjusted', $extra_sold);
                    }

                    TransactionSellLinesPurchaseLines::where('id', $row->tslpl_id)->update(['quantity' => $row->quantity - $extra_sold]);
                    
                    $extra_sold = 0;
                }

                if ($extra_sold == 0) {
                    break;
                }
            }
        }

        $business = Business::find($transaction->business_id)->toArray();
        $business['location_id'] = $transaction->location_id;

        //Allocate the sold lines to purchases.
        if (!empty($sell_lines)) {
            $sell_lines = (object)$sell_lines;
            $this->mapPurchaseSell($business, $sell_lines, 'purchase');
        }

        //Allocate the stock adjustment lines to purchases.
        if (!empty($stock_adjustment_lines)) {
            $stock_adjustment_lines = (object)$stock_adjustment_lines;
            $this->mapPurchaseSell($business, $stock_adjustment_lines, 'stock_adjustment');
        }
    }

    /**
     * Check if transaction can be edited based on business     transaction_edit_days
     *
     * @param  int/object $transaction
     * @param  int $edit_duration
     *
     * @return boolean
     */
    public function canBeEdited($transaction, $edit_duration)
    {

        if (!is_object($transaction)) {
            $transaction = Transaction::find($transaction);
        }
        if (empty($transaction)) {
            return false;
        }

        $date = \Carbon::parse($transaction->transaction_date)
                    ->addDays($edit_duration);

        $today = today();

        if ($date->gte($today)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Calculates total stock on the given date
     *
     * @param int $business_id
     * @param string $date
     * @param int $location_id
     * @param boolean $is_opening = false
     *
     * @return float
     */
    public function getOpeningClosingStock($business_id, $date, $location_id, $is_opening = false)
    {

        $query = PurchaseLine::join(
            'transactions as purchase',
            'purchase_lines.transaction_id',
            '=',
            'purchase.id'
        )
        ->where('purchase.business_id', $business_id);

        //If opening
        if ($is_opening) {
            $next_day = \Carbon::createFromFormat('Y-m-d', $date)->addDay()->format('Y-m-d');
            
            $query->where(function ($query) use ($date, $next_day) {
                $query->whereRaw("date(transaction_date) <= '$date'")
                    ->orWhereRaw("date(transaction_date) = '$next_day' AND type='opening_stock' ");
            });
        } else {
            $query->whereRaw("date(transaction_date) <= '$date'");
        }
        $query->select(
            DB::raw("SUM(
                            (purchase_lines.quantity -
                            (SELECT COALESCE(SUM(tspl.quantity), 0) FROM 
                            transaction_sell_lines_purchase_lines AS tspl
                            JOIN transaction_sell_lines as tsl ON 
                            tspl.sell_line_id=tsl.id 
                            JOIN transactions as sale ON 
                            tsl.transaction_id=sale.id 
                            WHERE tspl.purchase_line_id = purchase_lines.id AND 
                            date(sale.transaction_date) <= '$date') ) * (purchase_lines.purchase_price)
                        ) as stock")
        );
       

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('purchase.location_id', $permitted_locations);
        }

        if (!empty($location_id)) {
            $query->where('purchase.location_id', $location_id);
        }

        $details = $query->first();
        return $details->stock;
    }

    /**
     * Calculates total discount on the given date
     *
     * @param int $business_id
     * @param string $transaction_type
     * @param string $start_date
     * @param string $end_date
     * @param int $location_id = null
     *
     * @return float
     */
    public function getTotalDiscounts($business_id, $transaction_type, $start_date, $end_date, $location_id = null)
    {

        $query = Transaction::where('business_id', $business_id)
                    ->where('type', $transaction_type);

        //Date filter
        if (!empty($start_date) && !empty($end_date)) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }
        //Location filter
        if (!empty($location_id)) {
            $query->where('location_id', $location_id);
        }

        $query->select(
            DB::raw("SUM(IF(discount_type = 'percentage', COALESCE(discount_amount, 0)*total_before_tax/100, COALESCE(discount_amount, 0))) as discount")
        );

        $details = $query->first();
        return $details->discount;
    }

    /**
     * Calculates total expense for a business
     *
     * @param  int $business_id
     * @param  string $start_date
     * @param  string $end_date
     * @param  int $location_id
     *
     * @return boolean
     */
    public function getTotalExpense($business_id, $start_date = null, $end_date = null, $location_id = null)
    {

        //Get Total Expense
        $q = Transaction::where('business_id', $business_id)
                        ->where('type', 'expense')
                        ->where('payment_status', 'paid');

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $q->whereIn('location_id', $permitted_locations);
        }
        if (!empty($start_date) && !empty($end_date)) {
            $q->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }
        if (!empty($location_id)) {
            $q->where('location_id', $location_id);
        }
        $expenses = $q->get();
        $total_expense = $expenses->sum('final_total');

        return $total_expense;
    }

    /**
     * Calculates total stock adjustment for a business
     *
     * @param  int $business_id
     * @param  string $start_date
     * @param  string $end_date
     * @param  int $location_id
     *
     * @return boolean
     */
    public function getTotalStockAdjustment($business_id, $start_date = null, $end_date = null, $location_id = null)
    {

        //Get Total Expense
        $q = Transaction::where('business_id', $business_id)
                        ->where('type', 'stock_adjustment')
                        ->select(
                            DB::raw("SUM(final_total) as total_adjustment"),
                            DB::raw("SUM(total_amount_recovered) as total_recovered")
                        );

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $q->whereIn('location_id', $permitted_locations);
        }
        if (!empty($start_date) && !empty($end_date)) {
            $q->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }
        if (!empty($location_id)) {
            $q->where('location_id', $location_id);
        }
        
        $total_adjustment = $q->first();

        return $total_adjustment;
    }

    /**
     * Gives the total sell commission for a commission agent within the date range passed
     *
     * @param int $business_id
     * @param string $start_date
     * @param string $end_date
     * @param int $location_id
     * @param int $commission_agent
     *
     * @return array
     */
    public function getTotalSellCommission($business_id, $start_date = null, $end_date = null, $location_id = null, $commission_agent = null)
    {
        $query = Transaction::where('business_id', $business_id)
                        ->where('type', 'sell')
                        ->where('status', 'final')
                        ->select('final_total');

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('location_id', $permitted_locations);
        }

        if (!empty($start_date) && !empty($end_date)) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        //Filter by the location
        if (!empty($location_id)) {
            $query->where('location_id', $location_id);
        }

        if (!empty($commission_agent)) {
            $query->where('commission_agent', $commission_agent);
        }

        $sell_details = $query->get();

        $output['total_sales_with_commission'] = $sell_details->sum('final_total');

        return $output;
    }

    /**
     * Calculates total stock adjustment for a business
     *
     * @param  int $business_id
     * @param  string $start_date
     * @param  string $end_date
     * @param  int $location_id
     *
     * @return boolean
     */
    public function getTotalTransferShippingCharges($business_id, $start_date = null, $end_date = null, $location_id = null)
    {

        //Get Total Transfer Shipping charge
        $q = Transaction::where('business_id', $business_id)
                        ->where('type', 'sell_transfer')
                        ->select(DB::raw("SUM(shipping_charges) as total_shipping_charges"));

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $q->whereIn('location_id', $permitted_locations);
        }
        if (!empty($start_date) && !empty($end_date)) {
            $q->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }
        if (!empty($location_id)) {
            $q->where('location_id', $location_id);
        }
        
        return $q->first()->total_shipping_charges;
    }

    /**
     * Add Sell transaction
     *
     * @param int $business_id
     * @param array $input
     * @param float $invoice_total
     * @param int $user_id
     *
     * @return boolean
     */
    public function createSellReturnTransaction($business_id, $input, $invoice_total, $user_id)
    {
        $transaction = Transaction::create([
            'business_id' => $business_id,
            'location_id' => $input['location_id'],
            'type' => 'sell_return',
            'status' => 'final',
            'contact_id' => $input['contact_id'],
            'customer_group_id' => $input['customer_group_id'],
            'invoice_no' => $input['invoice_no'],
            'total_before_tax' => $invoice_total['total_before_tax'],
            'transaction_date' => $input['transaction_date'],
            'tax_id' => null,
            'discount_type' => $input['discount_type'],
            'discount_amount' => $this->num_uf($input['discount_amount']),
            'tax_amount' => $invoice_total['tax'],
            'final_total' => $this->num_uf($input['final_total']),
            'additional_notes' => $input['additional_notes'],
            'created_by' => $user_id,
            'is_quotation' => isset($input['is_quotation']) ? $input['is_quotation'] : 0
        ]);

        return $transaction;
    }

    public function groupTaxDetails($tax, $amount)
    {
        if (!is_object($tax)) {
            $tax = TaxRate::find($tax);
        }

        if (!empty($tax)) {
            $sub_taxes = $tax->sub_taxes;

            $sum = $tax->sub_taxes->sum('amount');

            $details = [];
            foreach ($sub_taxes as $sub_tax) {
                $details[$sub_tax->name] = ($amount / $sum) * $sub_tax->amount;
            }

            return $details;
        } else {
            return [];
        }
    }

    /**
     * Retrieves all available lot numbers of a product from variation id
     *
     * @param  int $variation_id
     * @param  int $business_id
     * @param  int $location_id
     *
     * @return boolean
     */
    public function getLotNumbersFromVariation($variation_id, $business_id, $location_id, $exclude_empty_lot = false){

        $query = PurchaseLine::join('transactions as T', 'purchase_lines.transaction_id', '=', 
                                            'T.id')
                                        ->where('T.business_id', $business_id)
                                        ->where('T.location_id', $location_id)
                                        ->where('purchase_lines.variation_id', $variation_id);

        //If expiry is disabled
        if(request()->session()->get('business.enable_product_expiry') == 0){
            $query->whereNotNull('purchase_lines.lot_number');
        }
        if($exclude_empty_lot){
            $query->whereRaw('(purchase_lines.quantity_sold + purchase_lines.quantity_adjusted) < purchase_lines.quantity');
        } else {
            $query->whereRaw('(purchase_lines.quantity_sold + purchase_lines.quantity_adjusted) <= purchase_lines.quantity');
        }

        $purchase_lines = $query->select('purchase_lines.id as purchase_line_id', 'lot_number', 'purchase_lines.exp_date as exp_date', DB::raw('(purchase_lines.quantity - (purchase_lines.quantity_sold + purchase_lines.quantity_adjusted)) AS qty_available') )->get();
        return $purchase_lines;
    }

    /**
     * Checks if credit limit of a customer is exceeded
     *
     * @param  array $input
     * @param  int $exclude_transaction_id (For update sell)
     *
     * @return mixed
     * if exceeded returns credit_limit else false
     */
    public function isCustomerCreditLimitExeeded(
        $input,
        $exclude_transaction_id = null
    ) {

        $credit_limit = Contact::find($input['contact_id'])->credit_limit;

        if ($credit_limit == null) {
            return false;
        }

        $query = Contact::where('contacts.id', $input['contact_id'])
                ->join('transactions AS t', 'contacts.id', '=', 't.contact_id');

        //Exclude transaction id if update transaction
        if (!empty($exclude_transaction_id)) {
            $query->where('t.id', '!=', $exclude_transaction_id);
        }
                                    
        $credit_details =  $query->select(
            DB::raw("SUM(IF(t.type = 'sell', final_total, 0)) as total_invoice"),
            DB::raw("SUM(IF(t.type = 'sell', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as invoice_paid")
        )->first();

        $total_invoice = !empty($credit_details->total_invoice) ? $credit_details->total_invoice : 0;
        $invoice_paid = !empty($credit_details->invoice_paid) ? $credit_details->invoice_paid : 0;

        $final_total = $this->num_uf($input['final_total']);
        $curr_total_payment = 0;
        foreach ($input['payment'] as $payment) {
            $curr_total_payment += $this->num_uf($payment['amount']);
        }
        $curr_due = $final_total - $curr_total_payment;

        $total_due = $total_invoice - $invoice_paid + $curr_due;
        if ($total_due <= $credit_limit) {
            return false;
        }

        return $credit_limit;
    }


    /**
     * Creates a new opening balance transaction for a contact
     *
     * @param  int $business_id
     * @param  int $contact_id
     * @param  int $amount
     *
     * @return void
     */
    public function createOpeningBalanceTransaction($business_id, $contact_id, $amount){
        $business_location = BusinessLocation::where('business_id', $business_id)
                                                        ->first();
        $final_amount = $this->num_uf($amount);
        $ob_data = array(
                    'business_id' => $business_id,
                    'location_id' => $business_location->id,
                    'type' => 'opening_balance',
                    'status' => 'final',
                    'payment_status' => 'due',
                    'contact_id' => $contact_id,
                    'transaction_date' => \Carbon::now(),
                    'total_before_tax' => $final_amount,
                    'final_total' => $final_amount,
                    'created_by' => request()->session()->get('user.id')
                );
        //Update reference count
        $ob_ref_count = $this->setAndGetReferenceCount('opening_balance');
        //Generate reference number
        $ob_data['ref_no'] = $this->generateReferenceNumber('opening_balance', $ob_ref_count);
        //Create opening balance transaction
        Transaction::create($ob_data);
    }
}
