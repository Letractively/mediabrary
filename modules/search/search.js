$(function () {
	$('#movie-search').autocomplete({minLength: 0, source: function (sender) {
		$.get('search/'+sender.term, function (data) {
			$('#movie-items').load('movie/items');
		}, 'html');
	}});
});