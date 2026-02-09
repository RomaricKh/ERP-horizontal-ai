export function createDataTable(tableSelector, options = {}) {
	const defaultOptions = {
		columnDefs: [],
		responsive: true,
		lengthChange: true,
		pageLength: 10,
		scrollCollapse: true,
		autoWidth: false,
		scrollX: true,
		scrollY: '55vh',
		layout: {
			topStart: { search: { placeholder: 'Search ...' } },
			topEnd: null,
			bottomStart: { pageLength: { menu: [10, 25, 50, 100] } },
			bottomEnd: 'info',
			bottom1: 'paging'
		}
	};

	return $(tableSelector).DataTable({
		...defaultOptions,
		...options
	});
}
