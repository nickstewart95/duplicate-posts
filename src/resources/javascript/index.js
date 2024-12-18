window.onload = function () {
	var add_button = document.getElementById('add-post-type');

	add_button.addEventListener(
		'click',
		function (e) {
			e.preventDefault();
			var form_table = document
				.querySelector('#settings-form table')
				.getElementsByTagName('tbody')[0];

			// The last six settings are post related and need to be duplicated
			// TODO - turn this into a template so its less fragile
			var rows = document.querySelectorAll(
				'#settings-form .form-table tr:nth-last-child(-n+6)'
			);

			// Figure out the last ID and add one to it
			var last_row_id = document
				.querySelector('#settings-form .form-table tr:last-of-type input')
				.getAttribute('id')
				.slice(-1);
			last_row_id = Number(last_row_id);

			var new_row_id = last_row_id + 1;

			rows.forEach(function (row) {
				contents = row.outerHTML.replaceAll(
					'_' + last_row_id,
					'_' + new_row_id
				);
				contents = contents.replaceAll(' - ' + last_row_id, ' - ' + new_row_id);
				contents = contents.replace(/value=".*?"/, '');

				form_table.insertAdjacentHTML('beforeend', contents);
			});
		},
		false
	);
};
