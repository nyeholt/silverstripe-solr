
;(function ($) {
	$('#Form_EditForm_action_saveconfig').live('click', function () {
		var button = $(this);
		$('#ModelAdminPanel form').ajaxSubmit(function (data) {
			$('#ModelAdminPanel').html(data);

			Behaviour.apply();
			button.removeClass('loading');
		});
	});
})(jQuery);