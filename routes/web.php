<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

include_once('install.php');

Route::middleware(['IsInstalled'])->group(function () {

    Route::get('/', function () {
        return view('welcome');
    });

    Auth::routes();

    Route::get('/business/register', 'BusinessController@getRegister')->name('business.getRegister');
    Route::post('/business/register', 'BusinessController@postRegister')->name('business.postRegister');
    Route::post('/business/register/check-username', 'BusinessController@postCheckUsername')->name('business.postCheckUsername');
});

//Routes for authenticated users only
Route::middleware(['IsInstalled', 'auth', 'SetSessionData', 'language', 'timezone'])->group(function () {

    Route::get('/logout', 'Auth\LoginController@logout')->name('logout');

    Route::get('/home', 'HomeController@index')->name('home');
    Route::post('/home/get-purchase-details', 'HomeController@getPurchaseDetails');
    Route::post('/home/get-sell-details', 'HomeController@getSellDetails');
    Route::post('/home/get-business-points', 'HomeController@getBusinessPoints');
    Route::get('/home/product-stock-alert', 'HomeController@getProductStockAlert');
    Route::get('/home/payment-dues', 'HomeController@getPaymentDues');

    Route::get('/business/settings', 'BusinessController@getBusinessSettings')->name('business.getBusinessSettings');
    Route::post('/business/update', 'BusinessController@postBusinessSettings')->name('business.postBusinessSettings');
    Route::get('/user/profile', 'UserController@getProfile')->name('user.getProfile');
    Route::post('/user/update', 'UserController@updateProfile')->name('user.updateProfile');
    Route::post('/user/update-password', 'UserController@updatePassword')->name('user.updatePassword');

    Route::get('/business/getLocInfo/{location_id}', 'BusinessController@getLocInfo');

    Route::resource('brands', 'BrandController');
    
    Route::resource('payment-account', 'PaymentAccountController');

    Route::resource('tax-rates', 'TaxRateController');

    Route::resource('units', 'UnitController');

    Route::get('/contacts/import', 'ContactController@getImportContacts')->name('contacts.import');
    Route::post('/contacts/import', 'ContactController@postImportContacts');
    Route::post('/contacts/check-contact-id', 'ContactController@checkContactId');
    Route::get('/contacts/customers', 'ContactController@getCustomers');
    Route::resource('contacts', 'ContactController');

    Route::resource('categories', 'CategoryController');

    Route::resource('variation-templates', 'VariationTemplateController');

    Route::get('/products/view-product-group-price/{id}/{location}', 'ProductController@viewGroupPrice');
    Route::get('/products/add-selling-prices/{id}', 'ProductController@addSellingPrices');
    Route::post('/products/save-selling-prices', 'ProductController@saveSellingPrices');
    Route::post('/products/mass-delete', 'ProductController@massDestroy');
    Route::get('/products/view/{id}/{location}', 'ProductController@view');
    Route::get('/products/list', 'ProductController@getProducts');
    Route::get('/products/list-no-variation', 'ProductController@getProductsWithoutVariations');
    
    Route::post('/products/get_sub_categories', 'ProductController@getSubCategories');
    Route::post('/products/product_form_part', 'ProductController@getProductVariationFormPart');
    Route::post('/products/get_product_variation_row', 'ProductController@getProductVariationRow');
    Route::post('/products/get_variation_template', 'ProductController@getVariationTemplate');
    Route::get('/products/get_variation_value_row', 'ProductController@getVariationValueRow');
    Route::post('/products/check_product_sku', 'ProductController@checkProductSku');
    Route::get('/products/quick_add', 'ProductController@quickAdd');
    Route::post('/products/save_quick_product', 'ProductController@saveQuickProduct');
    Route::get('products/generate_table', 'ProductController@generate_product_table');
    Route::resource('products', 'ProductController');

    Route::get('/purchases/get_products', 'PurchaseController@getProducts');
    Route::get('/purchases/get_suppliers', 'PurchaseController@getSuppliers');
    Route::post('/purchases/get_purchase_entry_row', 'PurchaseController@getPurchaseEntryRow');
    Route::post('/purchases/check_ref_number', 'PurchaseController@checkRefNumber');
    Route::get('/purchases/print/{id}', 'PurchaseController@printInvoice');
    Route::resource('purchases', 'PurchaseController');

    Route::get('/sells/duplicate/{id}', 'SellController@duplicateSell');
    Route::get('/sells/drafts', 'SellController@getDrafts');
    Route::get('/sells/quotations', 'SellController@getQuotations');
    Route::get('/sells/draft-dt', 'SellController@getDraftDatables');
    Route::resource('sells', 'SellController');

    Route::get('/sells/pos/get_product_row/{variation_id}/{location_id}', 'SellPosController@getProductRow');
    Route::post('/sells/pos/get_payment_row', 'SellPosController@getPaymentRow');
    Route::get('/sells/pos/get-recent-transactions', 'SellPosController@getRecentTransactions');
    Route::get('/sells/{transaction_id}/print', 'SellPosController@printInvoice')->name('sell.printInvoice');
    Route::get('/sells/pos/get-product-suggestion', 'SellPosController@getProductSuggestion');
    Route::resource('pos', 'SellPosController');

    Route::resource('roles', 'RoleController');

    Route::resource('users', 'ManageUserController');

    Route::resource('group-taxes', 'GroupTaxController');

    Route::get('/barcodes/set_default/{id}', 'BarcodeController@setDefault');
    Route::resource('barcodes', 'BarcodeController');

    //Invoice schemes..
    Route::get('/invoice-schemes/set_default/{id}', 'InvoiceSchemeController@setDefault');
    Route::resource('invoice-schemes', 'InvoiceSchemeController');

    //Print Labels
    Route::get('/labels/show', 'LabelsController@show');
    Route::get('/labels/add-product-row', 'LabelsController@addProductRow');
    Route::post('/labels/preview', 'LabelsController@preview');

    //Reports...
    Route::get('/reports/service-staff-report', 'ReportController@getServiceStaffReport');
    Route::get('/reports/table-report', 'ReportController@getTableReport');
    Route::get('/reports/profit-loss', 'ReportController@getProfitLoss');
    Route::get('/reports/get-opening-stock', 'ReportController@getOpeningStock');
    Route::get('/reports/purchase-sell', 'ReportController@getPurchaseSell');
    Route::get('/reports/customer-supplier', 'ReportController@getCustomerSuppliers');
    Route::get('/reports/stock-report', 'ReportController@getStockReport');
    Route::get('/reports/stock-details', 'ReportController@getStockDetails');
    Route::get('/reports/tax-report', 'ReportController@getTaxReport');
    Route::get('/reports/trending-products', 'ReportController@getTrendingProducts');
    Route::get('/reports/expense-report', 'ReportController@getExpenseReport');
    Route::get('/reports/stock-adjustment-report', 'ReportController@getStockAdjustmentReport');
    Route::get('/reports/register-report', 'ReportController@getRegisterReport');
    Route::get('/reports/sales-representative-report', 'ReportController@getSalesRepresentativeReport');
    Route::get('/reports/sales-representative-total-expense', 'ReportController@getSalesRepresentativeTotalExpense');
    Route::get('/reports/sales-representative-total-sell', 'ReportController@getSalesRepresentativeTotalSell');
    Route::get('/reports/sales-representative-total-commission', 'ReportController@getSalesRepresentativeTotalCommission');
    Route::get('/reports/stock-expiry', 'ReportController@getStockExpiryReport');
    Route::get('/reports/stock-expiry-edit-modal/{purchase_line_id}', 'ReportController@getStockExpiryReportEditModal');
    Route::post('/reports/stock-expiry-update', 'ReportController@updateStockExpiryReport')->name('updateStockExpiryReport');
    Route::get('/reports/customer-group', 'ReportController@getCustomerGroup');
    Route::get('/reports/product-purchase-report', 'ReportController@getproductPurchaseReport');
    Route::get('/reports/product-sell-report', 'ReportController@getproductSellReport');
    Route::get('/reports/lot-report', 'ReportController@getLotReport');
    Route::get('/reports/purchase-payment-report', 'ReportController@purchasePaymentReport');
    Route::get('/reports/sell-payment-report', 'ReportController@sellPaymentReport');
    
    //Business Location Settings...
    Route::prefix('business-location/{location_id}')->name('location.')->group(function () {
        Route::get('settings', 'LocationSettingsController@index')->name('settings');
        Route::post('settings', 'LocationSettingsController@updateSettings')->name('settings_update');
    });

    //Business Locations...
    Route::post('business-location/check-location-id', 'BusinessLocationController@checkLocationId');
    Route::resource('business-location', 'BusinessLocationController');

    //Invoice layouts..
    Route::resource('invoice-layouts', 'InvoiceLayoutController');

    //Expense Categories...
    Route::resource('expense-categories', 'ExpenseCategoryController');

    //Expenses...
    Route::resource('expenses', 'ExpenseController');

    //Transaction payments...
    Route::get('/payments/add_payment/{transaction_id}', 'TransactionPaymentController@addPayment');
    Route::get('/payments/pay-contact-due/{contact_id}', 'TransactionPaymentController@getPayContactDue');
    Route::post('/payments/pay-contact-due', 'TransactionPaymentController@postPayContactDue');
    Route::resource('payments', 'TransactionPaymentController');

    //Printers...
    Route::resource('printers', 'PrinterController');

    Route::get('/stock-adjustments/remove-expired-stock/{purchase_line_id}', 'StockAdjustmentController@removeExpiredStock');
    Route::post('/stock-adjustments/get_product_row', 'StockAdjustmentController@getProductRow');
    Route::resource('stock-adjustments', 'StockAdjustmentController');

    Route::get('/cash-register/register-details', 'CashRegisterController@getRegisterDetails');
    Route::get('/cash-register/close-register', 'CashRegisterController@getCloseRegister');
    Route::post('/cash-register/close-register', 'CashRegisterController@postCloseRegister');
    Route::resource('cash-register', 'CashRegisterController');

    //Import products
    Route::get('/import-products', 'ImportProductsController@index');
    Route::post('/import-products/store', 'ImportProductsController@store');

    //Sales Commission Agent
    Route::resource('sales-commission-agents', 'SalesCommissionAgentController');

    //Stock Transfer
    Route::get('stock-transfers/print/{id}', 'StockTransferController@printInvoice');
    Route::resource('stock-transfers', 'StockTransferController');
    
    Route::get('/opening-stock/add/{product_id}', 'OpeningStockController@add');
    Route::post('/opening-stock/save', 'OpeningStockController@save');

    //Customer Groups
    Route::resource('customer-group', 'CustomerGroupController');

    //Import opening stock
    Route::get('/import-opening-stock', 'ImportOpeningStockController@index');
    Route::post('/import-opening-stock/store', 'ImportOpeningStockController@store');

    //Sell return
    Route::resource('sell-return', 'SellReturnController');
    Route::get('sell-return/get-product-row', 'SellReturnController@getProductRow');
    Route::get('/sell-return/print/{id}', 'SellReturnController@printInvoice');
    
    //Backup
    Route::get('backup/download/{file_name}', 'BackUpController@download');
    Route::get('backup/delete/{file_name}', 'BackUpController@delete');
    Route::resource('backup', 'BackUpController', ['only' => [
        'index', 'create', 'store'
    ]]);


    Route::resource('selling-price-group', 'SellingPriceGroupController', ['except' => ['create']]);
    Route::get('selling-price-group/create/{location}', 'SellingPriceGroupController@create');
    //Restaurant module
    Route::group(['prefix' => 'modules'], function () {

        Route::resource('tables', 'Restaurant\TableController');
        Route::resource('modifiers', 'Restaurant\ModifierSetsController');

        //Map modifier to products
        Route::get('/product-modifiers/{id}/edit', 'Restaurant\ProductModifierSetController@edit');
        Route::post('/product-modifiers/{id}/update', 'Restaurant\ProductModifierSetController@update');
        Route::get('/product-modifiers/product-row/{product_id}', 'Restaurant\ProductModifierSetController@product_row');

        Route::get('/add-selected-modifiers', 'Restaurant\ProductModifierSetController@add_selected_modifiers');

        Route::get('/kitchen', 'Restaurant\KitchenController@index');
        Route::get('/kitchen/mark-as-cooked/{id}', 'Restaurant\KitchenController@markAsCooked');
        Route::post('/refresh-orders-list', 'Restaurant\KitchenController@refreshOrdersList');

        Route::get('/orders', 'Restaurant\OrderController@index');
        Route::get('/orders/mark-as-served/{id}', 'Restaurant\OrderController@markAsServed');
        Route::get('/data/get-pos-details', 'Restaurant\DataController@getPosDetails');
    });

    Route::get('bookings/get-todays-bookings', 'Restaurant\BookingController@getTodaysBookings');
    Route::resource('bookings', 'Restaurant\BookingController');

    //2019-01-07
    Route::resource('points', 'PointsController',['only' => ['index']]);
    Route::get('/points', 'PointsController@index');
    Route::get('/points/business_points', 'PointsController@businessPoints');
    Route::get('/points/request-redeem/{points}', 'PointsController@requestRedeemPoints');
    Route::get('/points/requested', 'PointsController@requestedPoints');
    Route::get('/points/approved', 'PointsController@approvedPoints');
    Route::get('/points/rejected', 'PointsController@rejectedPoints');

    //custom route
    Route::get('/get_products_in_cart/{uid}/{location_id}', 'SellPosController@get_products_in_cart');
    Route::get('/sells/pos/get_product_in_cart_row/{variation_id}/{location_id}/{product_quantity}', 'SellPosController@getProductInCartRow');
    Route::get('/cancel-products/{uid}', 'Api\ProductController@cancel_products');
    Route::get('/pos_ping/{uid}', 'Api\ProductController@ping_to_app');

    Route::get('/delivery/new', 'DeliveryController@newDelivery');
    Route::get('/delivery/pending', 'DeliveryController@pendingDelivery');
    Route::get('/delivery/confirm/{uid}', 'DeliveryController@confirm');
    Route::get('/delivery/confirmPending/{uid}', 'DeliveryController@confirmPending');
    Route::get('/delivery/show_new_order/{uid}', 'DeliveryController@showNewOrder');
    Route::get('/delivery/cancel/{uid}', 'DeliveryController@cancel');
    Route::get('/delivery/cancelPending/{uid}', 'DeliveryController@cancelPending');
    Route::get('/delivery/complete', 'DeliveryController@completeDelivery');
    Route::get('/delivery/uncomplete', 'DeliveryController@uncompleteDelivery');
});

//Api
Route::get('/api/get_group_price/{variation_id}/{group_name}/{tax_id}', 'Api\ProductController@getSellingGroup');
Route::get('/api/get_product/{sku}/{business_id}/{location_id}', 'Api\ProductController@getProduct');
Route::get('/api/get_delivery_products/{business_id}/{location_id}', 'Api\ProductController@getDeliveryProduct');
Route::get('/api/get_all_products/{business_id}/{location_id}', 'Api\ProductController@getAllProduct');
Route::get('/api/get_tables/{business_id}/{location_id}', 'Api\ProductController@getTables');
Route::get('/api/getBusinesses/{nType}', 'Api\StoreController@getBusinesses');
Route::get('/api/get_products_in_cart/{uid}/{location_id}', 'SellPosController@get_products_in_cart');
Route::post('/api/register_products', 'Api\ProductController@registerProducts');
Route::get('/api/remove_products/{uid}/{mode}', 'Api\ProductController@removeProducts');
Route::get('/api/get_braintree_token/{business_loc_id}', 'Api\PaymentsController@generate_token');
Route::post('/api/payment/process', 'Api\PaymentsController@process');
Route::post('/api/payment/process_for_delivery', 'Api\PaymentsController@processForDelivery');
Route::get('/sells/pos/get-recent-mobile-transactions', 'Api\PaymentsController@getMobileTransactions');
Route::post('/api/ror', 'Api\StoreController@ror');
Route::get('/api/d_ror', 'Api\StoreController@d_ror');

Route::post('/api/get_points_status', 'Api\StoreController@getPointsStatus');
Route::post('/api/inform_business', 'Api\StoreController@emailToPointsBusiness');
Route::post('/api/update_point_ratio', 'Api\StoreController@updatePointRatio');
Route::get('/api/pos_pong/{event}', 'Api\ProductController@pong_from_app');

Route::post('/api/delivery/order', 'Api\DeliveryController@order');