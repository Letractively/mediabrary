$(function () {
	$('#dialog-tv').dialog({
		'modal': true,
		autoOpen: false,
		width: '80%',
		position: 'top'
	});

	$('.tv-item').live('click', function () {
		$('#dialog-tv').dialog('option', 'title',
			'Details for '+$(this).attr('title'));

		$('#dialog-tv').load('tv/series',
			{'name': $(this).attr('title')},
			function () {
				$('#dialog-tv').dialog('open');
			}
		);
		return false;
	});

	$('#scrape-tv-link').live('click', function () {
		$.get('tv/search/'+$(this).attr('href'), function (data) {
			$('#scrape-tv-link').after(data);
		}, 'html')
		return false;
	});

	$('#tv-items').load('tv/items');
});
