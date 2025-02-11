@extends('layouts.app')

@section('title', 'POS')

@section('content')

<!-- Content Header (Page header) -->
<!-- <section class="content-header">
    <h1>Add Purchase</h1> -->
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
<!-- </section> -->
<input type="hidden" id="__precision" value="{{config('constants.currency_precision')}}">

<!-- Main content -->
<section class="content no-print">
	<div class="row">
		<div class="col-md-7 col-sm-12">
			<div class="box box-success">

				<div class="box-header with-border">
					<div class="col-sm-8">
						<h3 class="box-title">
							Editing 
							@if($transaction->status == 'draft' && $transaction->is_quotation == 1) 
								@lang('lang_v1.quotation')
							@elseif($transaction->status == 'draft') 
								Draft 
							@elseif($transaction->status == 'final') 
								Invoice 
							@endif 
						<span class="text-success">#{{$transaction->invoice_no}}</span> <i class="fa fa-keyboard-o hover-q text-muted" aria-hidden="true" data-container="body" data-toggle="popover" data-placement="bottom" data-content="@include('sale_pos.partials.keyboard_shortcuts_details')" data-html="true" data-trigger="hover" data-original-title="" title=""></i></h3>
					</div>
				</div>
				<input type="hidden" id="item_addition_method">
				{!! Form::open(['url' => action('SellPosController@update', [$transaction->id]), 'method' => 'post', 'id' => 'edit_pos_sell_form' ]) !!}

				{{ method_field('PUT') }}

				{!! Form::hidden('location_id', $transaction->location_id, ['id' => 'location_id', 'data-receipt_printer_type' => !empty($location_printer_type) ? $location_printer_type : 'browser']); !!}

				<!-- /.box-header -->
				<div class="box-body">
					<div class="row">
						@if(config('constants.enable_sell_in_diff_currency') == true)
							<div class="col-md-4 col-sm-6">
								<div class="form-group">
									<div class="input-group">
										<span class="input-group-addon">
											<i class="fa fa-exchange"></i>
										</span>
										{!! Form::text('exchange_rate', @num_format($transaction->exchange_rate), ['class' => 'form-control input-sm input_number', 'placeholder' => __('lang_v1.currency_exchange_rate'), 'id' => 'exchange_rate']); !!}
									</div>
								</div>
							</div>
						@endif
						@if(!empty($price_groups))
							@if(count($price_groups) > 1)
								<div class="col-sm-12">
									<div class="form-group">
										<div class="input-group">
											<span class="input-group-addon">
												<i class="fa fa-money"></i>
											</span>
											{!! Form::hidden('hidden_price_group', $transaction->selling_price_group_id, ['id' => 'hidden_price_group']) !!}
											{!! Form::select('price_group', $price_groups, $transaction->selling_price_group_id, ['class' => 'form-control select2', 'id' => 'price_group']); !!}
											<span class="input-group-addon">
												@show_tooltip(__('lang_v1.price_group_help_text'))
											</span> 
										</div>
									</div>
								</div>
							@else
								{!! Form::hidden('price_group', $transaction->selling_price_group_id, ['id' => 'price_group']) !!}
							@endif
						@endif
					</div>
					<div class="row">
						<div class="@if(!empty($commission_agent)) col-sm-4 @else col-sm-6 @endif">
							<div class="form-group">
								<div class="input-group">
									<span class="input-group-addon">
										<i class="fa fa-user"></i>
									</span>
									<input type="hidden" id="default_customer_id" 
									value="{{ $walk_in_customer['id']}}" >
									<input type="hidden" id="default_customer_name" 
									value="{{ $walk_in_customer['name']}}" >
									{!! Form::select('contact_id', 
										[], null, ['class' => 'form-control mousetrap', 'id' => 'customer_id', 'placeholder' => 'Enter Customer name / phone', 'required']); !!}
									<span class="input-group-btn">
										<button type="button" class="btn btn-default bg-white btn-flat add_new_customer" data-name=""><i class="fa fa-plus-circle text-primary fa-lg"></i></button>
									</span>
								</div>
							</div>
						</div>
						@if(!empty($commission_agent))
						<div class="col-sm-4">
							<div class="form-group">
							{!! Form::select('commission_agent', 
										$commission_agent, $transaction->commission_agent, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.commission_agent')]); !!}
							</div>
						</div>
						@endif
						<div class="@if(!empty($commission_agent)) col-sm-4 @else col-sm-6 @endif">
							<div class="form-group">
								<div class="input-group">
									<span class="input-group-addon">
										<i class="fa fa-barcode"></i>
									</span>
									{!! Form::text('search_product', null, ['class' => 'form-control mousetrap', 'id' => 'search_product', 'placeholder' => __('lang_v1.search_product_placeholder'), 'autofocus']); !!}
								</div>
							</div>
						</div>

						<!-- Call restaurant module if defined -->
				        @if(in_array('tables' ,$enabled_modules) || in_array('service_staff' ,$enabled_modules))
				        	<span id="restaurant_module_span" 
				        		data-transaction_id="{{$transaction->id}}">
				          		<div class="col-md-3"></div>
				        	</span>
				        @endif
				     </div>
					<div class="row col-sm-12 pos_product_div">

						<input type="hidden" name="sell_price_tax" id="sell_price_tax" >

						<!-- Keeps count of product rows -->
						<input type="hidden" id="product_row_count" 
							value="{{count($sell_details)}}">
						<!-- custom uid for mobile payment -->
						<input type="hidden" id="uid" name="uid" value="{{$sell_details ? $sell_details[0]['uid'] : 0}}">
						<input type="hidden" id="points" name="points" value="0">
						<input type="hidden" id="total_mobile_price" name="total_mobile_price" value="0">
						<input type="hidden" id="point_ratio" name="point_ratio" value="none">
						<input type="hidden" id="receipt_no" name="receipt_no" value=".">
						@php
							$hide_tax = '';
							if( session()->get('business.enable_inline_tax') == 0){
								$hide_tax = 'hide';
							}
						@endphp
						<table class="table table-condensed table-bordered table-striped table-responsive" id="pos_table">
							<thead>
								<tr>
									<th class="text-center col-md-4">	
										@lang('sale.product')
									</th>
									<th class="text-center col-md-3">
										@lang('sale.qty')
									</th>
									<th class="text-center col-md-2 {{$hide_tax}}">
										@lang('sale.price_inc_tax')
									</th>
									<th class="text-center col-md-3">
										@lang('sale.subtotal')
									</th>
									<th class="text-center"><i class="fa fa-trash" aria-hidden="true"></i></th>
								</tr>
							</thead>
							<tbody>
								@foreach($sell_details as $sell_line)
									@include('sale_pos.product_row', ['product' => $sell_line, 'row_count' => $loop->index, 'tax_dropdown' => $taxes])
								@endforeach
							</tbody>
						</table>
					</div>
					@include('sale_pos.partials.pos_details', ['edit' => true])

					@include('sale_pos.partials.payment_modal')
				</div>
				<!-- /.box-body -->
				{!! Form::close() !!}
			</div>
			<!-- /.box -->
		</div>

		<div class="col-md-5 col-sm-12">
			@include('sale_pos.partials.right_div')
		</div>
	</div>
</section>

<!-- This will be printed -->
<section class="invoice print_section" id="receipt_section">
</section>
<div class="modal fade contact_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
	@include('contact.create', ['quick_add' => true])
</div>
<!-- /.content -->
<div class="modal fade register_details_modal" tabindex="-1" role="dialog" 
	aria-labelledby="gridSystemModalLabel">
</div>
<div class="modal fade close_register_modal" tabindex="-1" role="dialog" 
	aria-labelledby="gridSystemModalLabel">
</div>
@stop
@section('javascript')
	<script src="{{ asset('js/pos.js') }}"></script>
	<script src="{{ asset('js/printer.js?v=' . $asset_v) }}"></script>
	@include('sale_pos.partials.keyboard_shortcuts')

	<!-- Call restaurant module if defined -->
    @if(in_array('tables' ,$enabled_modules) || in_array('modifiers' ,$enabled_modules) || in_array('service_staff' ,$enabled_modules))
    	<script src="{{ asset('js/restaurant.js?v=' . $asset_v) }}"></script>
    @endif
@endsection

@section('css')
	<style type="text/css">
		/*CSS to print receipts*/
		.print_section{
		    display: none;
		}
		@media print{
		    .print_section{
		        display: block !important;
		    }
		}
		@page {
		    size: 3.1in auto;/* width height */
		    height: auto !important;
		    margin-top: 0mm;
		    margin-bottom: 0mm;
		}
	</style>
@endsection
