jQuery(function($){
 $(document).on('click','.etalk-v1-request-otp',function(){
  var $b=$(this), $p=$b.closest('.payment_box, form'),$s=$p.find('.etalk-v1-otp-status');
  var orderId=window.etalkV1Test&&etalkV1Test.orderId;
  if(!orderId){$s.text('Open the order payment page to request a verification code.');return;}
  $b.prop('disabled',true);$s.text('Requesting code…');
  $.post(etalkV1Test.ajax,{action:'etalk_v1_request_otp',nonce:etalkV1Test.nonce,order_id:orderId,order_key:etalkV1Test.orderKey,wallet:$p.find('[name="etalk_v1_wallet"]').val(),ccv:$p.find('[name="etalk_v1_ccv"]').val()})
   .done(function(r){$s.text(r.success?'Code sent to your registered email.':'Rejected: '+(r.data&&r.data.code||'unknown'));})
   .fail(function(x){$s.text('Request failed: '+(x.responseJSON&&x.responseJSON.data&&x.responseJSON.data.code||x.status));})
   .always(function(){$b.prop('disabled',false);});
 });
});
