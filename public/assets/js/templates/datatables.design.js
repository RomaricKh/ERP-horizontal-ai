// Call the dataTables jQuery plugin
//Table design
$(document).ready(function() {
	$('#dataTable').DataTable({
		columnDefs: [
		{
			target: 0,
			visible: false,
			searchable: false
		}],
		// Optional: Bootstrap 5 styling
		responsive: true,
		lengthChange: true,
		pageLength: 10,
		scrollCollapse: true,
		scrollY: '55vh',
		layout: {
			topStart: {
				search: {
					placeholder: 'Search ...'
				}
			},
			topEnd: null,
			bottomStart: {
				pageLength: { menu: [ 10, 25, 50, 100 ] }
			},
			bottomEnd: 'info',
			bottom1: 'paging'
		}
	});
});
