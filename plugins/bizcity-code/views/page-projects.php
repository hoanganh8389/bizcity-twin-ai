<div class="wrap">
	<h1>Code Builder — Dự án</h1>
	<p>Quản lý các dự án web/landing page đã tạo bằng AI.</p>

	<div id="bzcode-projects-app">
		<div class="bzcode-projects-toolbar">
			<a href="<?php echo esc_url( home_url( '/tool-code/' ) ); ?>" class="button button-primary">
				+ Tạo dự án mới
			</a>
		</div>
		<table class="wp-list-table widefat fixed striped" id="bzcode-projects-table">
			<thead>
				<tr>
					<th>Tên dự án</th>
					<th>Stack</th>
					<th>Trạng thái</th>
					<th>Cập nhật</th>
					<th>Thao tác</th>
				</tr>
			</thead>
			<tbody>
				<tr><td colspan="5">Đang tải...</td></tr>
			</tbody>
		</table>
	</div>
</div>
