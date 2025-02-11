<!-- Edit Bullet Modal -->
<div class="modal fade" tabindex="-1" role="dialog" id="posEditBulletModal">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<h4 class="modal-title">@lang('sale.edit_bullet')</h4>
			</div>
			<div class="modal-body">
				<div class="row">
				    <div class="col-md-6">
				        <div class="form-group">
				            {!! Form::label('bullet_amount_modal', __('sale.bullet_amount') . ':*' ) !!}
				            <div class="input-group">
				                <span class="input-group-addon">
				                    <i class="fa fa-info"></i>
				                </span>
				                {!! Form::text('bullet_amount_modal', @num_format($sales_bullet), ['class' => 'form-control input_number']); !!}
				            </div>
				        </div>
				    </div>

				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary" id="posEditBulletModalUpdate">@lang('messages.update')</button>
			    <button type="button" class="btn btn-default" data-dismiss="modal">@lang('messages.cancel')</button>
			</div>
		</div><!-- /.modal-content -->
	</div><!-- /.modal-dialog -->
</div><!-- /.modal -->