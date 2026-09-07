<?php
/**
 * Plugin Name: QC Sales Reports
 * Description: Sales by sales rep reporting — gross, net, coupons & shipping with commission.
 * Version:     1.1
 * Author:      Quality Components
 * Text Domain: qc-sales-reports
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register ACF options page for Sales Reps settings.
 */
add_action( 'acf/init', 'qc_register_acf_options_pages' );
function qc_register_acf_options_pages() {
	if ( function_exists( 'acf_add_options_page' ) ) {
		acf_add_options_page( array(
			'page_title' => 'Sales Reps',
			'menu_title' => 'Sales Reps',
			'menu_slug'  => 'sales-reps',
			'parent_slug' => 'options-general.php',
			'capability'  => 'manage_options',
			'redirect'    => false,
			'position'    => 30,
		) );
	}
}

/**
 * Register the admin menu page.
 */
add_action( 'admin_menu', 'qc_register_sales_reports_page' );
function qc_register_sales_reports_page() {
	add_menu_page(
		'Sales Reports',
		'Sales Reports',
		'manage_options',
		'qc-sales-reports',
		'qc_sales_reports_page',
		'dashicons-chart-bar',
		56
	);
}

/**
 * Intercept CSV export before admin page output.
 */
add_action( 'admin_init', 'qc_handle_csv_export' );
function qc_handle_csv_export() {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'qc-sales-reports' ) {
		return;
	}
	if ( ! isset( $_GET['qc_export'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorised.' );
	}

	$export = sanitize_key( $_GET['qc_export'] );
	if ( ! in_array( $export, array( 'rep', 'customers' ), true ) ) {
		return;
	}

	$from     = isset( $_GET['from'] )     ? sanitize_text_field( wp_unslash( $_GET['from'] ) )     : date( 'Y-m-01' );
	$to       = isset( $_GET['to'] )       ? sanitize_text_field( wp_unslash( $_GET['to'] ) )       : date( 'Y-m-t' );
	$drill_rep = isset( $_GET['qc_rep'] )  ? sanitize_text_field( wp_unslash( $_GET['qc_rep'] ) )  : '';

	qc_export_csv( $export, $from, $to, $drill_rep );
}

/**
 * Render the reports page.
 */
function qc_sales_reports_page() {
	$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
	$to   = isset( $_GET['to'] )   ? sanitize_text_field( wp_unslash( $_GET['to'] ) )   : '';
	$drill_rep = isset( $_GET['qc_rep'] ) ? sanitize_text_field( wp_unslash( $_GET['qc_rep'] ) ) : '';

	if ( ! $from && ! $to ) {
		$from = date( 'Y-m-01' );
		$to   = date( 'Y-m-t' );
	}

	$stats = qc_get_sales_stats( $from, $to, $drill_rep );

	?>
	<div class="wrap">
		<h1>Sales Reports</h1>

		<form method="get" style="margin-bottom: 20px;">
			<input type="hidden" name="page" value="qc-sales-reports" />
			<label for="from">From:</label>
			<input type="date" id="from" name="from" value="<?php echo esc_attr( $from ); ?>" />
			<label for="to">To:</label>
			<input type="date" id="to" name="to" value="<?php echo esc_attr( $to ); ?>" />
			<input type="submit" class="button" value="Filter" />
		</form>

		<?php
		if ( $drill_rep ) {
			qc_render_drill_breadcrumb( 'Sales Rep', $drill_rep );
			qc_render_summary_cards( $stats );
			qc_render_customer_table( $stats['customers'] );
		} else {
			qc_render_summary_cards( $stats );
			qc_render_rep_table( $stats['rep'] );
			qc_render_customer_table( $stats['customers'] );
		}
		?>
	</div>
	<?php
}

/**
 * Drill-down breadcrumb.
 */
function qc_render_drill_breadcrumb( $type, $value ) {
	$back_url = remove_query_arg( array( 'qc_rep' ) );
	?>
	<p style="margin-bottom:15px;">
		<a href="<?php echo esc_url( $back_url ); ?>">← All Sales Reports</a>
		&nbsp;/&nbsp;
		<strong><?php echo esc_html( $type ); ?>: <?php echo esc_html( $value ); ?></strong>
	</p>
	<?php
}

/**
 * Fetch and aggregate sales data with gross, coupons, shipping, net, and commission.
 */
function qc_get_sales_stats( $from, $to, $filter_rep = '' ) {
	global $wpdb;

	$where_date = $wpdb->prepare(
		"AND o.date_created_gmt >= %s AND o.date_created_gmt < %s",
		$from . ' 00:00:00',
		date( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00'
	);

	$where_drill = '';
	if ( $filter_rep ) {
		$drill_val = esc_sql( $filter_rep );
		$where_drill = "AND o.customer_id IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'qc_sales_rep' AND meta_value = '$drill_val')";
	}

	$orders = $wpdb->get_results(
		"SELECT o.id, o.total_amount, o.customer_id,
		        COALESCE(od.shipping_total_amount, 0) AS shipping,
		        COALESCE(od.discount_total_amount, 0) AS coupons
		 FROM {$wpdb->prefix}wc_orders o
		 LEFT JOIN {$wpdb->prefix}wc_order_operational_data od ON o.id = od.order_id
		 WHERE o.type = 'shop_order' AND o.status = 'wc-completed'
		 $where_date
		 $where_drill"
	);

	$total_orders   = count( $orders );
	$total_gross    = 0;
	$total_coupons  = 0;
	$total_shipping = 0;
	$total_net      = 0;

	$customer_map = array();
	$customer_ids = array();

	foreach ( $orders as $r ) {
		$cid      = $r->customer_id;
		$gross    = round( (float) $r->total_amount / 1.1, 2 );
		$coupons  = round( (float) $r->coupons / 1.1, 2 );
		$shipping = round( (float) $r->shipping / 1.1, 2 );
		$net      = $gross - $coupons - $shipping;

		$total_gross   += $gross;
		$total_coupons += $coupons;
		$total_shipping += $shipping;
		$total_net     += $net;

		if ( ! isset( $customer_map[ $cid ] ) ) {
			$customer_map[ $cid ] = array(
				'gross'    => 0,
				'coupons'  => 0,
				'shipping' => 0,
				'net'      => 0,
				'orders'   => 0,
			);
		}
		$customer_map[ $cid ]['gross']    += $gross;
		$customer_map[ $cid ]['coupons']  += $coupons;
		$customer_map[ $cid ]['shipping'] += $shipping;
		$customer_map[ $cid ]['net']      += $net;
		$customer_map[ $cid ]['orders']   += 1;

		$customer_ids[ $cid ] = true;
	}

	$customer_ids = array_keys( $customer_ids );
	$user_data    = array();
	$reps         = array();

	if ( ! empty( $customer_ids ) ) {
		$id_list = implode( ',', array_map( 'intval', $customer_ids ) );

		$users = $wpdb->get_results(
			"SELECT ID, user_email, display_name FROM {$wpdb->users} WHERE ID IN ($id_list)"
		);
		foreach ( $users as $u ) {
			$user_data[ $u->ID ] = $u;
		}

		$meta = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta}
			 WHERE meta_key = 'qc_sales_rep' AND user_id IN ($id_list)"
		);
		foreach ( $meta as $m ) {
			$reps[ $m->user_id ] = $m->meta_value;
		}
	}

	// Fetch rep percentages from ACF options
	$rep_percentages = qc_get_rep_percentages();

	// Rep aggregates
	$rep_data      = array();
	$customer_rows = array();

	foreach ( $orders as $r ) {
		$cid      = $r->customer_id;
		$gross    = round( (float) $r->total_amount / 1.1, 2 );
		$coupons  = round( (float) $r->coupons / 1.1, 2 );
		$shipping = round( (float) $r->shipping / 1.1, 2 );
		$net      = $gross - $coupons - $shipping;
		$rep      = isset( $reps[ $cid ] ) ? $reps[ $cid ] : 'Unassigned';

		if ( ! isset( $rep_data[ $rep ] ) ) {
			$rep_data[ $rep ] = array( 'gross' => 0, 'coupons' => 0, 'shipping' => 0, 'net' => 0, 'orders' => 0, 'customers' => array() );
		}
		$rep_data[ $rep ]['gross']    += $gross;
		$rep_data[ $rep ]['coupons']  += $coupons;
		$rep_data[ $rep ]['shipping'] += $shipping;
		$rep_data[ $rep ]['net']      += $net;
		$rep_data[ $rep ]['orders']   += 1;
		$rep_data[ $rep ]['customers'][ $cid ] = true;
	}

	foreach ( $customer_map as $cid => $d ) {
		$u   = isset( $user_data[ $cid ] ) ? $user_data[ $cid ] : null;
		$rep = isset( $reps[ $cid ] ) ? $reps[ $cid ] : 'Unassigned';

		$customer_rows[] = array(
			'email'   => $u ? $u->user_email : "User #{$cid}",
			'name'    => $u ? $u->display_name : "User #{$cid}",
			'rep'     => $rep,
			'orders'  => $d['orders'],
			'gross'   => $d['gross'],
			'coupons' => $d['coupons'],
			'shipping' => $d['shipping'],
			'net'     => $d['net'],
		);
	}

	usort( $customer_rows, function( $a, $b ) {
		return $b['gross'] <=> $a['gross'];
	} );

	return array(
		'total_orders'    => $total_orders,
		'total_gross'     => $total_gross,
		'total_coupons'   => $total_coupons,
		'total_shipping'  => $total_shipping,
		'total_net'       => $total_net,
		'rep_percentages' => $rep_percentages,
		'from'            => $from,
		'to'              => $to,
		'rep'             => $rep_data,
		'customers'       => $customer_rows,
	);
}

/**
 * Fetch rep percentages from ACF options.
 */
function qc_get_rep_percentages() {
	$row_count = get_option( 'options_qc_sales_reps', 0 );
	$map = array();
	for ( $i = 0; $i < (int) $row_count; $i++ ) {
		$name       = get_option( "options_qc_sales_reps_{$i}_rep_name", '' );
		$percentage = get_option( "options_qc_sales_reps_{$i}_rep_percentage", 0 );
		$active     = get_option( "options_qc_sales_reps_{$i}_rep_active", 0 );
		if ( $name && $active ) {
			$map[ $name ] = (float) $percentage;
		}
	}
	return $map;
}

/**
 * Render a money column.
 */
function qc_money( $val ) {
	return '$' . number_format( (float) $val, 2 );
}

/**
 * Summary cards.
 */
function qc_render_summary_cards( $stats ) {
	?>
	<div style="display:flex; gap:20px; margin-bottom:20px; flex-wrap:wrap;">
		<div style="background:#fff; border:1px solid #ccd0d4; padding:15px 25px; border-radius:4px; min-width:120px;">
			<strong style="color:#646970;">Orders</strong>
			<div style="font-size:28px; font-weight:700; margin-top:5px;"><?php echo intval( $stats['total_orders'] ); ?></div>
		</div>
		<div style="background:#fff; border:1px solid #ccd0d4; padding:15px 25px; border-radius:4px; min-width:120px;">
			<strong style="color:#646970;">Gross (ex GST)</strong>
			<div style="font-size:28px; font-weight:700; margin-top:5px;">$<?php echo number_format( $stats['total_gross'], 2 ); ?></div>
		</div>
		<div style="background:#fff; border:1px solid #ccd0d4; padding:15px 25px; border-radius:4px; min-width:120px;">
			<strong style="color:#646970;">Coupons (ex GST)</strong>
			<div style="font-size:28px; font-weight:700; margin-top:5px;">$<?php echo number_format( $stats['total_coupons'], 2 ); ?></div>
		</div>
		<div style="background:#fff; border:1px solid #ccd0d4; padding:15px 25px; border-radius:4px; min-width:120px;">
			<strong style="color:#646970;">Shipping (ex GST)</strong>
			<div style="font-size:28px; font-weight:700; margin-top:5px;">$<?php echo number_format( $stats['total_shipping'], 2 ); ?></div>
		</div>
		<div style="background:#fff; border:1px solid #ccd0d4; padding:15px 25px; border-radius:4px; min-width:120px;">
			<strong style="color:#646970;">Net (ex GST)</strong>
			<div style="font-size:28px; font-weight:700; margin-top:5px;">$<?php echo number_format( $stats['total_net'], 2 ); ?></div>
		</div>
		<div style="background:#fff; border:1px solid #ccd0d4; padding:15px 25px; border-radius:4px; min-width:120px;">
			<strong style="color:#646970;">Period</strong>
			<div style="font-size:14px; margin-top:5px;"><?php echo esc_html( $stats['from'] ); ?><br><?php echo esc_html( $stats['to'] ); ?></div>
		</div>
	</div>
	<?php
}

/**
 * Sales by Rep table.
 */
function qc_render_rep_table( $data ) {
	$percentages = qc_get_rep_percentages();

	qc_render_table( 'Sales by Rep', 'rep', $data,
		array( 'Sales Rep', 'Orders', 'Gross', 'Coupons', 'Shipping', 'Net', 'Commission', 'Customers' ),
		function( $key, $row ) use ( $percentages ) {
			$drill_url  = add_query_arg( 'qc_rep', urlencode( $key ) );
			$pct        = isset( $percentages[ $key ] ) ? $percentages[ $key ] : 0;
			$ex_gst_net = (float) $row['net'] / 1.1;
			$commission = $ex_gst_net * ( $pct / 100 );
			return array(
				'<a href="' . esc_url( $drill_url ) . '">' . esc_html( $key ) . '</a>',
				intval( $row['orders'] ),
				qc_money( $row['gross'] ),
				qc_money( $row['coupons'] ),
				qc_money( $row['shipping'] ),
				qc_money( $row['net'] ),
				$pct > 0 ? qc_money( $commission ) . ' <span style="color:#8c8f94;">(' . $pct . '% on ' . qc_money( $ex_gst_net ) . ' ex-GST)</span>' : '<span style="color:#8c8f94;">—</span>',
				count( $row['customers'] ),
			);
		}
	);
}

/**
 * Customer breakdown table.
 */
function qc_render_customer_table( $rows ) {
	if ( empty( $rows ) ) {
		echo '<h2>Customer Breakdown</h2><p>No data for this period.</p>';
		return;
	}

	$percentages = qc_get_rep_percentages();

	$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
	$to   = isset( $_GET['to'] )   ? sanitize_text_field( wp_unslash( $_GET['to'] ) )   : '';
	$export_url = add_query_arg( array(
		'page'      => 'qc-sales-reports',
		'from'      => $from,
		'to'        => $to,
		'qc_export' => 'customers',
	) );
	?>
	<h2>
		Customer Breakdown
		<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary" style="margin-left:10px;">Export CSV</a>
	</h2>
	<table class="wp-list-table widefat fixed striped" style="margin-bottom:30px;">
		<thead>
			<tr>
				<th>Customer</th>
				<th>Email</th>
				<th>Sales Rep</th>
				<th>Orders</th>
				<th>Gross (ex GST)</th>
				<th>Coupons (ex GST)</th>
				<th>Shipping (ex GST)</th>
				<th>Net (ex GST)</th>
				<th>Commission</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $r ) :
				$pct        = isset( $percentages[ $r['rep'] ] ) ? $percentages[ $r['rep'] ] : 0;
				$ex_gst_net = (float) $r['net'] / 1.1;
				$commission = $ex_gst_net * ( $pct / 100 );
			?>
				<tr>
					<td><?php echo esc_html( $r['name'] ); ?></td>
					<td><?php echo esc_html( $r['email'] ); ?></td>
					<td><?php echo esc_html( $r['rep'] ); ?></td>
					<td><?php echo intval( $r['orders'] ); ?></td>
					<td><?php echo qc_money( $r['gross'] ); ?></td>
					<td><?php echo qc_money( $r['coupons'] ); ?></td>
					<td><?php echo qc_money( $r['shipping'] ); ?></td>
					<td><?php echo qc_money( $r['net'] ); ?></td>
					<td><?php echo $pct > 0 ? qc_money( $commission ) . ' <span style="color:#8c8f94;">(' . $pct . '% on ' . qc_money( $ex_gst_net ) . ' ex-GST)</span>' : '<span style="color:#8c8f94;">—</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Generic table renderer.
 */
function qc_render_table( $title, $export_key, $data, $headers, $row_callback ) {
	if ( empty( $data ) ) {
		echo "<h2>{$title}</h2><p>No data for this period.</p>";
		return;
	}

	$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
	$to   = isset( $_GET['to'] )   ? sanitize_text_field( wp_unslash( $_GET['to'] ) )   : '';
	$export_url = add_query_arg( array(
		'page'      => 'qc-sales-reports',
		'from'      => $from,
		'to'        => $to,
		'qc_export' => $export_key,
	) );

	uasort( $data, function( $a, $b ) {
		return $b['gross'] <=> $a['gross'];
	} );
	?>
	<h2>
		<?php echo esc_html( $title ); ?>
		<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary" style="margin-left:10px;">Export CSV</a>
	</h2>
	<table class="wp-list-table widefat fixed striped" style="margin-bottom:30px;">
		<thead>
			<tr>
				<?php foreach ( $headers as $h ) : ?>
					<th><?php echo esc_html( $h ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $data as $key => $row ) : ?>
				<tr>
					<?php foreach ( $row_callback( $key, $row ) as $cell ) : ?>
						<td><?php echo $cell; ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

// ====== SALES REP COLUMN — USERS LIST ======

add_filter( 'manage_users_columns', 'qc_users_columns' );
function qc_users_columns( $columns ) {
	$columns['qc_sales_rep'] = 'Sales Rep';
	return $columns;
}

add_filter( 'manage_users_custom_column', 'qc_users_column_content', 10, 3 );
function qc_users_column_content( $value, $column, $user_id ) {
	if ( $column === 'qc_sales_rep' ) {
		$rep = get_user_meta( $user_id, 'qc_sales_rep', true );
		return $rep ? esc_html( $rep ) : '—';
	}
	return $value;
}

add_filter( 'manage_users_sortable_columns', 'qc_users_sortable_columns' );
function qc_users_sortable_columns( $columns ) {
	$columns['qc_sales_rep'] = 'qc_sales_rep';
	return $columns;
}

// ====== DYNAMIC SALES REP CHOICES FROM ACF ======

add_filter( 'acf/load_field', 'qc_acf_load_sales_rep_choices' );
function qc_acf_load_sales_rep_choices( $field ) {
	if ( $field['name'] !== 'qc_sales_rep' ) {
		return $field;
	}
	$field['choices'] = array( '' => '— Unassigned —' );
	if ( have_rows( 'qc_sales_reps', 'option' ) ) {
		while ( have_rows( 'qc_sales_reps', 'option' ) ) {
			the_row();
			$name   = get_sub_field( 'rep_name' );
			$active = get_sub_field( 'rep_active' );
			if ( ! empty( $name ) && $active ) {
				$field['choices'][ $name ] = $name;
			}
		}
	}
	return $field;
}

// ====== CSV EXPORT ======

function qc_export_csv( $export_type, $from, $to, $filter_rep = '' ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorised.' );
	}

	$stats = qc_get_sales_stats( $from, $to, $filter_rep );

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="qc-sales-' . $export_type . '-' . $from . '-to-' . $to . '.csv"' );

	$output = fopen( 'php://output', 'w' );

	if ( 'rep' === $export_type ) {
		$percentages = isset( $stats['rep_percentages'] ) ? $stats['rep_percentages'] : array();
		fputcsv( $output, array( 'Sales Rep', 'Orders', 'Gross', 'Coupons', 'Shipping', 'Net', 'Commission Rate', 'Commission' ) );
		foreach ( $stats['rep'] as $key => $row ) {
			$pct        = isset( $percentages[ $key ] ) ? $percentages[ $key ] : 0;
			$ex_gst_net = (float) $row['net'] / 1.1;
			$commission = $ex_gst_net * ( $pct / 100 );
			fputcsv( $output, array( $key, $row['orders'], round( $row['gross'], 2 ), round( $row['coupons'], 2 ), round( $row['shipping'], 2 ), round( $row['net'], 2 ), $pct . '%', round( $commission, 2 ) ) );
		}
	} elseif ( 'customers' === $export_type ) {
		$percentages = isset( $stats['rep_percentages'] ) ? $stats['rep_percentages'] : array();
		fputcsv( $output, array( 'Customer', 'Email', 'Sales Rep', 'Orders', 'Gross', 'Coupons', 'Shipping', 'Net', 'Commission Rate', 'Commission' ) );
		foreach ( $stats['customers'] as $r ) {
			$pct        = isset( $percentages[ $r['rep'] ] ) ? $percentages[ $r['rep'] ] : 0;
			$ex_gst_net = (float) $r['net'] / 1.1;
			$commission = $ex_gst_net * ( $pct / 100 );
			fputcsv( $output, array( $r['name'], $r['email'], $r['rep'], $r['orders'], round( $r['gross'], 2 ), round( $r['coupons'], 2 ), round( $r['shipping'], 2 ), round( $r['net'], 2 ), $pct . '%', round( $commission, 2 ) ) );
		}
	}

	fclose( $output );
	exit;
}
